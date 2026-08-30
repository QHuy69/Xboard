const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const expect = (condition, message) => {
  if (!condition) throw new Error(message);
};

const controller = read('app/Http/Controllers/V1/User/OrderController.php');
const adminController = read('app/Http/Controllers/V2/Admin/OrderController.php');
const adminPaymentController = read('app/Http/Controllers/V2/Admin/PaymentController.php');
const service = read('app/Services/OrderService.php');
const plugin = read('plugins-core/CoinPayments/Plugin.php');
const migration = read('database/migrations/2026_08_30_000004_create_order_payment_checkouts_table.php');
const smoke = read('tests/smoke-coinpayments-checkout-idempotency.php');

expect(controller.includes("$payment->payment === 'CoinPayments'"), 'Checkout controller does not isolate CoinPayments durable handling.');
expect(controller.includes('beginCoinPaymentsCheckout'), 'Checkout controller does not claim an invoice before the provider call.');
expect(controller.includes('completeCoinPaymentsCheckout'), 'Checkout controller does not persist the successful provider result.');
expect(controller.includes('failCoinPaymentsCheckout'), 'Checkout controller does not classify failed provider outcomes.');
expect(controller.indexOf('beginCoinPaymentsCheckout') < controller.indexOf('$paymentService->pay([', controller.indexOf("$payment->payment === 'CoinPayments'")), 'Provider POST can run before the durable claim.');

for (const state of ['creating', 'ready', 'failed', 'uncertain', 'closed']) {
  expect(service.includes(`'${state}'`), `Checkout state machine is missing ${state}.`);
}
expect(service.includes("where('user_id', $userId)"), 'Cached checkout ownership is not tied to the authenticated user.');
expect(service.includes('CHECKOUT_CLAIM_TTL'), 'Stale provider claims are not handled.');
expect(service.includes("return 'coinpayments-checkout:'"), 'Payment transitions do not share the CoinPayments per-order lock namespace.');
expect(service.includes('beginStandardPaymentCheckout'), 'A standard gateway can bypass the CoinPayments checkout invariant.');
expect(controller.includes('beginStandardPaymentCheckout'), 'The standard checkout controller bypasses the serialized payment transition.');
expect(service.includes('$hasBlockingCoinPaymentsCheckout'), 'A standard gateway can start while CoinPayments is creating or uncertain.');
expect(service.includes('private function cancelInternal') && service.includes('paymentCheckoutLockKey('), 'Cancellation is not serialized with invoice creation.');
expect(service.includes('insertOrIgnore'), 'Concurrent checkout insert can still leak a unique-key exception.');
expect(service.includes("$inserted !== 1"), 'A lost atomic insert does not stop the provider call.');
expect(service.includes('response_data'), 'Successful checkout URL is not persisted for restart-safe reuse.');
expect(service.includes('self::closePaymentCheckouts($order->id)'), 'Paid/cancelled orders do not clear checkout URLs.');
expect(service.includes('cancelAfterManualPaymentReconciliation'), 'There is no explicit admin recovery method for uncertain checkout.');
expect(service.includes('$hasUncertainCheckout && !$allowUncertainCheckout'), 'User cancellation does not block an uncertain CoinPayments invoice.');
expect(controller.includes('$orderService->cancel()'), 'User cancellation bypasses the safe default cancellation path.');
expect(adminController.includes('$orderService->cancelAfterManualPaymentReconciliation()'), 'Admin cancellation does not use the explicit reconciliation path.');

const reconciliationMessage = 'Payment verification is still in progress. Do not retry or cancel this order. Please contact support if it does not update.';
for (const locale of ['en-US', 'vi-VN', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU']) {
  const messages = JSON.parse(read(`resources/lang/${locale}.json`));
  expect(typeof messages[reconciliationMessage] === 'string' && messages[reconciliationMessage].trim() !== '', `${locale} is missing the uncertain-payment recovery message.`);
  if (locale !== 'en-US') {
    expect(messages[reconciliationMessage] !== reconciliationMessage, `${locale} left the uncertain-payment recovery message in English.`);
  }
}

expect(migration.includes("unique(['order_id', 'payment_id']"), 'Database does not enforce one checkout row per order/payment.');
expect(migration.includes("Schema::dropIfExists('v2_order_payment_checkout')"), 'Checkout migration is not rollback-safe.');
expect(plugin.includes("'poNumber' => (string) $order['trade_no']"), 'CoinPayments unique merchant PO number is missing.');
expect(!plugin.includes('->retry('), 'Non-idempotent CoinPayments invoice POST still has an automatic retry.');
expect(plugin.includes('catch (ConnectionException'), 'Ambiguous transport failure is not classified.');
expect(plugin.includes('$response->serverError() ? 503 : 400'), 'Ambiguous 5xx and known 4xx responses are not separated.');
expect(plugin.includes("($checkoutParts['scheme'] ?? '')") && plugin.includes("!== 'https'"), 'CoinPayments accepts a non-HTTPS provider checkout URL.');
expect(adminPaymentController.includes("'notify_domain' => 'nullable|url:https'"), 'Payment admin still accepts a non-HTTPS custom callback domain.');
expect(plugin.includes("($notifyParts['scheme'] ?? '')") && plugin.includes("CoinPayments webhook URL must be a valid HTTPS URL."), 'CoinPayments does not defensively reject a non-HTTPS fallback webhook URL.');
expect(adminPaymentController.includes("'ids.*' => 'required|integer|distinct'")
  && adminPaymentController.includes('if (!$payment || !$payment->update')
  && adminPaymentController.includes('catch (\\Throwable $e)'),
  'Payment sorting can still crash on a missing or invalid payment ID.');
expect(service.includes('isHttpsCheckoutUrl($data)'), 'A persisted CoinPayments checkout URL is not revalidated as HTTPS.');
expect(plugin.includes("DB::table('v2_order_payment_checkout')")
  && plugin.includes("->where('payment_id', (int) $paymentId)")
  && plugin.includes('$checkout->handling_amount'),
  'CoinPayments webhook does not verify the durable payment claim and frozen fee.');
expect(!plugin.includes('$order->handling_amount ?? 0'), 'CoinPayments webhook still trusts the mutable order handling fee.');

for (const assertion of [
  'Reload issued a second provider call.',
  'Payment switch leaked a cached URL',
  'Concurrent checkout was not serialized.',
  'A standard gateway started while CoinPayments invoice creation was active.',
  'A fresh CoinPayments invoice could be cancelled while creation was active.',
  'A non-HTTPS provider checkout URL was persisted.',
  'A standard gateway started while a CoinPayments result was uncertain.',
  'Gateway switching made a valid late CoinPayments webhook fail its durable amount check.',
  'Another user could read the checkout URL.',
  'A non-pending order was allowed to create an invoice.',
  'Ambiguous provider outcome was retried.',
  'A user-facing cancellation abandoned an uncertain payable invoice.',
  'Explicit admin reconciliation could not close the uncertain order.',
]) {
  expect(smoke.includes(assertion), `PHP smoke is missing: ${assertion}`);
}

console.log('CoinPayments checkout idempotency source audit passed.');
