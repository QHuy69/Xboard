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
const snapshotMigration = read('database/migrations/2026_08_31_000005_add_coinpayments_checkout_snapshot.php');
const snapshotService = read('app/Services/CoinPaymentsCheckoutSnapshot.php');
const guestController = read('app/Http/Controllers/V1/Guest/PaymentController.php');
const entrypoint = read('.docker/entrypoint.sh');
const updateCommand = read('app/Console/Commands/XboardUpdate.php');
const webRoutes = read('routes/web.php');
const smoke = read('tests/smoke-coinpayments-checkout-idempotency.php');

expect(controller.includes("$payment->payment === 'CoinPayments'"), 'Checkout controller does not isolate CoinPayments durable handling.');
expect(controller.includes('beginCoinPaymentsCheckout'), 'Checkout controller does not claim an invoice before the provider call.');
expect(controller.includes('completeCoinPaymentsCheckout'), 'Checkout controller does not persist the successful provider result.');
expect(controller.includes('failCoinPaymentsCheckout'), 'Checkout controller does not classify failed provider outcomes.');
expect(controller.includes('$paymentService->validateConfiguration();'), 'Checkout does not validate CoinPayments configuration before creating a provider claim.');
expect(controller.indexOf('$paymentService->validateConfiguration();') < controller.indexOf('beginCoinPaymentsCheckout'), 'CoinPayments can create a durable claim before its credentials are validated.');
expect(controller.includes("->filter(function (Payment $payment): bool")
  && controller.includes('$paymentService = new PaymentService($payment->payment, $payment->id);')
  && controller.includes('->validateConfiguration();'),
  'Customer payment list does not hide disabled/missing gateways or incomplete CoinPayments records.');
expect(controller.indexOf('beginCoinPaymentsCheckout') < controller.indexOf('$paymentService->pay([', controller.indexOf("$payment->payment === 'CoinPayments'")), 'Provider POST can run before the durable claim.');
expect(service.includes('$freshPayment = Payment::whereKey($payment->id)->lockForUpdate()->first();')
  && service.includes('$paymentService = new PaymentService($freshPayment->payment, $freshPayment->id);')
  && service.includes('->validateConfiguration();'),
  'CoinPayments claim does not re-check the current enabled/configured payment row under lock.');

for (const state of ['creating', 'ready', 'failed', 'uncertain', 'closed']) {
  expect(service.includes(`'${state}'`), `Checkout state machine is missing ${state}.`);
}
expect(service.includes("where('user_id', $userId)"), 'Cached checkout ownership is not tied to the authenticated user.');
expect(service.includes('CHECKOUT_CLAIM_TTL'), 'Stale provider claims are not handled.');
expect(service.includes("return 'coinpayments-checkout:'"), 'Payment transitions do not share the CoinPayments per-order lock namespace.');
expect(service.includes('beginStandardPaymentCheckout'), 'A standard gateway can bypass the CoinPayments checkout invariant.');
expect(controller.includes('beginStandardPaymentCheckout'), 'The standard checkout controller bypasses the serialized payment transition.');
expect(service.includes('$hasBlockingCheckout'), 'A standard gateway can start while another checkout is creating, ready, or uncertain.');
expect(controller.includes('completeStandardPaymentCheckout')
  && controller.includes('failStandardPaymentCheckout')
  && service.includes("'cached' => true")
  && service.includes("'provider' => (string) $freshPayment->payment"),
  'Standard gateways do not persist and reuse a durable provider result.');
expect(service.includes("'state' => $ambiguous ? self::CHECKOUT_UNCERTAIN : self::CHECKOUT_FAILED"),
  'Ambiguous standard gateway failures are not fail-closed.');
expect(service.includes('private function cancelInternal') && service.includes('paymentCheckoutLockKey('), 'Cancellation is not serialized with invoice creation.');
expect(service.includes('insertOrIgnore'), 'Concurrent checkout insert can still leak a unique-key exception.');
expect(service.includes("$inserted !== 1"), 'A lost atomic insert does not stop the provider call.');
expect(service.includes('response_data'), 'Successful checkout URL is not persisted for restart-safe reuse.');
expect(service.includes('self::closePaymentCheckouts($order->id)'), 'Paid/cancelled orders do not clear checkout URLs.');
expect(service.includes('cancelAfterManualPaymentReconciliation'), 'There is no explicit admin recovery method for uncertain checkout.');
expect(service.includes('$hasPayableCheckout && !$allowUncertainCheckout')
  && service.includes("self::CHECKOUT_READY, self::CHECKOUT_UNCERTAIN"),
  'User cancellation does not block READY and uncertain CoinPayments invoices.');
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
for (const event of ['invoiceCompleted', 'invoiceTimedOut', 'invoiceCancelled']) {
  expect(plugin.includes(`'${event}'`), `CoinPayments invoice creation does not subscribe to ${event}.`);
}
expect(plugin.includes("'invoicetimedout' => 'timed_out'")
  && plugin.includes("'invoicecancelled' => 'cancelled'")
  && plugin.includes('$invoiceState !== $expectedState'),
  'Authenticated terminal webhook types are not paired with their provider invoice states.');
expect(plugin.includes('isAllowedCheckoutUrl($checkoutUrl)')
  && plugin.includes("($parts['scheme'] ?? '')")
  && plugin.includes("!== 'https'"),
  'CoinPayments accepts a non-HTTPS provider checkout URL.');
expect(adminPaymentController.includes("'notify_domain' => 'nullable|url:https'"), 'Payment admin still accepts a non-HTTPS custom callback domain.');
expect(plugin.includes("($notifyParts['scheme'] ?? '')") && plugin.includes("CoinPayments webhook URL must be a valid HTTPS URL."), 'CoinPayments does not defensively reject a non-HTTPS fallback webhook URL.');
expect(adminPaymentController.includes("'ids.*' => 'required|integer|distinct'")
  && adminPaymentController.includes("Payment::whereKey($request->input('id'))->lockForUpdate()->first()")
  && adminPaymentController.includes('catch (\\Throwable $e)'),
  'Payment sorting can still crash on a missing or invalid payment ID.');
expect(service.includes('isCoinPaymentsCheckoutUrl($data)')
  && service.includes("'a-checkout.coinpayments.net'")
  && service.includes("'c-checkout.coinpayments.net'"),
  'A persisted CoinPayments checkout URL is not revalidated against official checkout hosts.');
expect(plugin.includes("DB::table('v2_order_payment_checkout')")
  && plugin.includes("->where('payment_uuid', $paymentUuid)")
  && plugin.includes('$checkout->expected_amount'),
  'CoinPayments webhook does not verify the durable payment claim and frozen fee.');
expect(!plugin.includes('$order->handling_amount ?? 0'), 'CoinPayments webhook still trusts the mutable order handling fee.');
expect(plugin.includes('public function usesGlobalPaymentConfiguration(): bool')
  && plugin.includes('return false;'),
  'CoinPayments payment rows can still inherit credentials from plugin-global configuration.');
expect(plugin.includes('private const API_HOSTS')
  && plugin.includes('private const CHECKOUT_HOSTS')
  && plugin.includes('normalizedApiBase()')
  && plugin.includes('isAllowedCheckoutUrl($checkoutUrl)')
  && !plugin.includes("$invoice['checkoutLink'] ?? $invoice['link']"),
  'CoinPayments still accepts arbitrary API/checkout origins or embeds the non-checkout invoice link.');

const abstractPlugin = read('app/Services/Plugin/AbstractPlugin.php');
const paymentService = read('app/Services/PaymentService.php');
const pluginManager = read('app/Services/Plugin/PluginManager.php');
const manifest = JSON.parse(read('plugins-core/CoinPayments/config.json'));
expect(manifest.author === 'ZaoGuang Service', 'CoinPayments custom integration has the wrong author branding.');
expect(manifest.auto_enable === false, 'CoinPayments must require an explicit administrator enable action.');
expect(manifest.auto_update_on_deploy === true
  && pluginManager.includes("$config['auto_update_on_deploy']"),
  'CoinPayments data/config migration will not run during an image deployment.');
for (const credential of [
  'coinpayments_client_id',
  'coinpayments_client_secret',
  'coinpayments_invoice_currency_id',
  'coinpayments_webhook_url'
]) {
  expect(!(credential in manifest.config), `${credential} is still configured globally instead of per payment row.`);
}
expect(abstractPlugin.includes('public function validatePaymentConfiguration(): void'), 'Payment plugins have no pre-enable configuration validation contract.');
expect(paymentService.includes('$plugin->usesGlobalPaymentConfiguration()'), 'PaymentService ignores the per-plugin global-config isolation policy.');
expect(paymentService.includes("if (empty($this->config['enable']))")
  && paymentService.includes("$this->method !== 'CoinPayments'"),
  'Direct pay is not fail-closed or late signed CoinPayments callbacks are blocked after checkout disable.');
expect(adminPaymentController.includes("if (!$payment->enable)")
  && adminPaymentController.includes('->validateConfiguration();'),
  'Admin can still enable an incomplete CoinPayments payment record.');
expect(adminPaymentController.includes('lockForUpdate()->first()')
  && adminPaymentController.includes('OrderService::hasActiveCoinPaymentsCheckoutForPayment'),
  'Payment admin mutations are not row-locked or do not guard active CoinPayments invoices.');
expect(service.includes('whereIn(\'state\', self::activeCoinPaymentsStates())')
  && service.includes('CHECKOUT_READY'),
  'READY invoices do not block payment switches and duplicate provider paths.');
expect(controller.includes('useCoinPaymentsConfigurationSnapshot')
  && controller.includes("$checkout['configuration_snapshot']"),
  'Provider POST does not use the immutable configuration captured with its claim.');
for (const column of [
  'payment_uuid',
  'config_snapshot',
  'provider_invoice_id',
  'provider_expires_at',
  'expected_amount',
]) {
  expect(snapshotMigration.includes(`'${column}'`), `Snapshot migration is missing ${column}.`);
}
expect(snapshotMigration.includes('backfillCoinPaymentsSnapshots')
  && snapshotMigration.includes('failIfActive'),
  'Existing active CoinPayments claims are not safely backfilled or fail-closed.');
expect(snapshotService.includes('Crypt::encryptString')
  && snapshotService.includes('Crypt::decryptString')
  && snapshotService.includes('assertValid'),
  'CoinPayments credentials are not stored in an authenticated encrypted snapshot.');
expect(plugin.includes("$invoice['id']")
  && plugin.includes("data_get($invoice, 'payment.expires')")
  && plugin.includes("data_get($payload, 'invoice.id')"),
  'Provider invoice identity/expiry are not captured and checked end to end.');
expect(plugin.includes("trim((string) $providerExpiryValue) === ''")
  && service.includes('(int) $providerExpiresAt <= time()'),
  'A fresh CoinPayments READY checkout can still omit or reuse an expired provider expiry.');
expect(plugin.includes('CoinPaymentsCheckoutSnapshot::decrypt')
  && plugin.includes("$snapshot['coinpayments_client_secret']")
  && plugin.includes("$snapshot['coinpayments_webhook_url']"),
  'Late webhook authentication still depends on mutable payment configuration.');
expect(guestController.includes("$method === 'CoinPayments'")
  && guestController.includes('new PaymentService(')
  && guestController.includes('handleCoinPaymentsNotification'),
  'Signed callbacks cannot resolve CoinPayments after new checkouts are disabled.');
expect(service.includes('public static function handleCoinPaymentsNotification')
  && service.includes("Order::where('trade_no', $tradeNo)->lockForUpdate()->first()")
  && service.includes("->where('id', (int) $checkoutId)")
  && service.includes("'conflict' => true")
  && service.includes("if (!empty($outcome['conflict']))"),
  'CoinPayments settlement is not serialized with cancellation or loses reconciliation evidence on 409.');
expect(entrypoint.includes('php /www/artisan xboard:update --no-interaction')
  && !entrypoint.includes('xboard:update failed; continuing'),
  'Container startup still ignores failed migrations/plugin upgrades.');
expect(updateCommand.includes('$migrationExitCode !== 0')
  && updateCommand.includes('refusing to continue startup'),
  'xboard:update does not fail closed on a non-zero migration result.');
expect(webRoutes.includes("->where('state', 'ready')")
  && webRoutes.includes("->value('provider_expires_at')"),
  'Public payment status does not use the immutable provider expiry.');
expect(paymentService.includes("/orders?trade_no=")
  && paymentService.includes("$this->method === 'CoinPayments'"),
  'CoinPayments fallback return URL does not target the Luck order-history route.');

for (const assertion of [
  'Reload issued a second provider call.',
  'Payment switch leaked a cached URL',
  'Concurrent checkout was not serialized.',
  'A standard gateway started while CoinPayments invoice creation was active.',
  'A fresh CoinPayments invoice could be cancelled while creation was active.',
  'A non-HTTPS provider checkout URL was persisted.',
  'A standard gateway started while a CoinPayments result was uncertain.',
  'Credential rotation made a valid late CoinPayments webhook fail its durable snapshot check.',
  'Another user could read the checkout URL.',
  'A non-pending order was allowed to create an invoice.',
  'Ambiguous provider outcome was retried.',
  'A user-facing cancellation abandoned an uncertain payable invoice.',
  'Explicit admin reconciliation could not close the uncertain order.',
]) {
  expect(smoke.includes(assertion), `PHP smoke is missing: ${assertion}`);
}

console.log('CoinPayments checkout idempotency source audit passed.');
