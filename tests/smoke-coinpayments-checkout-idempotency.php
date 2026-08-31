<?php

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/plugins-core/CoinPayments/Plugin.php';

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use App\Utils\Helper;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Plugin\CoinPayments\Plugin as CoinPaymentsPlugin;

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function checkoutAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectCheckoutRejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (ApiException) {
        return;
    }
    throw new RuntimeException($message);
}

function coinPaymentsSmokeConfig(string $uuid): array
{
    return [
        'coinpayments_client_id' => 'checkout-smoke-client',
        'coinpayments_client_secret' => 'checkout-smoke-secret',
        'coinpayments_invoice_currency_id' => '5057',
        'coinpayments_payment_currency' => '',
        'coinpayments_cny_invoice_rate' => 1,
        'coinpayments_api_base' => 'https://a-api.coinpayments.net',
        'coinpayments_webhook_url' => 'https://payments.example.test/api/v1/guest/payment/notify/CoinPayments/' . $uuid,
        'coinpayments_webhook_max_age' => 300,
    ];
}

DB::beginTransaction();
try {
    HookManager::reset();
    $pluginManager = app(PluginManager::class);
    $pluginManager->prepareForRequest();
    if (!\App\Models\Plugin::query()->where('code', 'coin_payments')->exists()) {
        $pluginManager->install('coin_payments');
    }
    if (!\App\Models\Plugin::query()->where('code', 'coin_payments')->value('is_enabled')) {
        $pluginManager->enable('coin_payments');
    } else {
        $pluginManager->initializeEnabledPlugins();
    }
    $user = User::create([
        'email' => 'checkout-smoke-' . bin2hex(random_bytes(4)) . '@example.invalid',
        'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'uuid' => Helper::guid(true),
        'token' => Helper::guid(),
        'balance' => 0,
        'commission_balance' => 0,
        'transfer_enable' => 0,
        'u' => 0,
        'd' => 0,
        'banned' => 0,
        'is_admin' => 0,
        'is_staff' => 0,
        'expired_at' => 0,
        'remind_expire' => 1,
        'remind_traffic' => 1,
    ]);
    $otherUser = User::create([
        'email' => 'checkout-other-' . bin2hex(random_bytes(4)) . '@example.invalid',
        'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'uuid' => Helper::guid(true),
        'token' => Helper::guid(),
        'balance' => 0,
        'commission_balance' => 0,
        'transfer_enable' => 0,
        'u' => 0,
        'd' => 0,
        'banned' => 0,
        'is_admin' => 0,
        'is_staff' => 0,
        'expired_at' => 0,
        'remind_expire' => 1,
        'remind_traffic' => 1,
    ]);
    $plan = Plan::create([
        'group_id' => null,
        'transfer_enable' => 5,
        'name' => 'CoinPayments checkout smoke',
        'speed_limit' => null,
        'show' => 1,
        'sort' => 0,
        'renew' => 1,
        'prices' => [Plan::PERIOD_MONTHLY => 1],
        'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
        'capacity_limit' => null,
        'sell' => 1,
        'device_limit' => 2,
    ]);
    $paymentOneUuid = bin2hex(random_bytes(16));
    $paymentOne = Payment::create([
        'uuid' => $paymentOneUuid,
        'payment' => 'CoinPayments',
        'name' => 'CoinPayments one',
        'icon' => 'CoinPayments',
        'config' => coinPaymentsSmokeConfig($paymentOneUuid),
        'handling_fee_fixed' => 2,
        'handling_fee_percent' => 1,
        'enable' => true,
    ]);
    $paymentTwoUuid = bin2hex(random_bytes(16));
    $paymentTwo = Payment::create([
        'uuid' => $paymentTwoUuid,
        'payment' => 'CoinPayments',
        'name' => 'CoinPayments two',
        'icon' => 'CoinPayments',
        'config' => coinPaymentsSmokeConfig($paymentTwoUuid),
        'handling_fee_fixed' => 0,
        'handling_fee_percent' => 0,
        'enable' => true,
    ]);
    $standardPayment = Payment::create([
        'uuid' => bin2hex(random_bytes(16)),
        'payment' => 'StripeCheckout',
        'name' => 'Standard checkout guard',
        'icon' => 'StripeCheckout',
        'config' => [],
        'handling_fee_fixed' => 5,
        'handling_fee_percent' => 0,
        'enable' => true,
    ]);
    $order = Order::create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'type' => Order::TYPE_NEW_PURCHASE,
        'period' => Plan::PERIOD_MONTHLY,
        'trade_no' => 'cp_checkout_' . bin2hex(random_bytes(6)),
        'total_amount' => 100,
        'balance_amount' => 0,
        'status' => Order::STATUS_PENDING,
    ]);

    $providerCalls = 0;
    $first = OrderService::beginCoinPaymentsCheckout($user->id, $order->trade_no, $paymentOne);
    checkoutAssert(!$first['cached'], 'First checkout did not own the provider call.');
    checkoutAssert($first['amount'] === 103, 'Checkout handling fee was not frozen with the claim.');
    expectCheckoutRejected(
        fn () => OrderService::beginStandardPaymentCheckout($user->id, $order->trade_no, $standardPayment),
        'A standard gateway started while CoinPayments invoice creation was active.'
    );
    checkoutAssert(
        !(new OrderService(Order::findOrFail($order->id)))->cancel(),
        'A fresh CoinPayments invoice could be cancelled while creation was active.'
    );
    checkoutAssert(
        (int) Order::findOrFail($order->id)->status === Order::STATUS_PENDING,
        'Rejected active-invoice cancellation changed the order status.'
    );
    $providerCalls++;
    expectCheckoutRejected(
        fn () => OrderService::completeCoinPaymentsCheckout(
            $order->id,
            $paymentOne->id,
            $first['claim_token'],
            ['type' => 1, 'data' => 'http://checkout.coinpayments.example/insecure']
        ),
        'A non-HTTPS provider checkout URL was persisted.'
    );
    OrderService::completeCoinPaymentsCheckout(
        $order->id,
        $paymentOne->id,
        $first['claim_token'],
        [
            'type' => 1,
            'data' => 'https://checkout.coinpayments.net/invoice-one',
            'provider_invoice_id' => 'smoke-provider-invoice-one',
            'provider_expires_at' => time() + 3600,
            'expected_amount' => '1.03',
        ]
    );

    $reloaded = OrderService::beginCoinPaymentsCheckout($user->id, $order->trade_no, $paymentOne);
    checkoutAssert($reloaded['cached'], 'Reload did not reuse the successful provider result.');
    checkoutAssert($reloaded['data'] === 'https://checkout.coinpayments.net/invoice-one', 'Reload returned the wrong checkout URL.');
    checkoutAssert($providerCalls === 1, 'Reload issued a second provider call.');

    expectCheckoutRejected(
        fn () => OrderService::beginCoinPaymentsCheckout($otherUser->id, $order->trade_no, $paymentOne),
        'Another user could read the checkout URL.'
    );

    // READY remains payable. Neither another CoinPayments record, a standard
    // gateway nor user cancellation may create an alternative outcome.
    expectCheckoutRejected(
        fn () => OrderService::beginCoinPaymentsCheckout($user->id, $order->trade_no, $paymentTwo),
        'Payment switch leaked a cached URL from another payment record.'
    );
    expectCheckoutRejected(
        fn () => OrderService::beginCoinPaymentsCheckout($user->id, $order->trade_no, $paymentTwo),
        'Concurrent checkout was not serialized.'
    );
    expectCheckoutRejected(
        fn () => OrderService::beginStandardPaymentCheckout($user->id, $order->trade_no, $standardPayment),
        'A standard gateway started while a READY CoinPayments invoice was payable.'
    );
    expectCheckoutRejected(
        fn () => (new OrderService(Order::findOrFail($order->id)))->cancel(),
        'A user-facing cancellation abandoned a READY payable invoice.'
    );

    // Rotate/disable the payment record after invoice creation. The late
    // callback must still use the immutable encrypted snapshot.
    $webhookUrl = coinPaymentsSmokeConfig($paymentOne->uuid)['coinpayments_webhook_url'];
    $paymentOne->config = array_replace(coinPaymentsSmokeConfig($paymentOne->uuid), [
        'coinpayments_client_id' => 'rotated-client',
        'coinpayments_client_secret' => 'rotated-secret',
        'coinpayments_invoice_currency_id' => '9999',
        'coinpayments_cny_invoice_rate' => 9,
    ]);
    $paymentOne->enable = false;
    $paymentOne->saveOrFail();
    $timestamp = gmdate('Y-m-d\TH:i:s');
    $webhookPayload = json_encode([
        'id' => 'late-event-' . bin2hex(random_bytes(4)),
        'type' => 'InvoiceCompleted',
        'invoice' => [
            'id' => 'smoke-provider-invoice-one',
            'state' => 'Completed',
            'customData' => ['trade_no' => $order->trade_no],
            'amount' => ['currencyId' => '5057', 'total' => 1.03],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $webhookRequest = Request::create($webhookUrl, 'POST', [], [], [], [], $webhookPayload);
    $webhookRequest->headers->set('X-CoinPayments-Client', 'checkout-smoke-client');
    $webhookRequest->headers->set('X-CoinPayments-Timestamp', $timestamp);
    $webhookRequest->headers->set('X-CoinPayments-Signature', CoinPaymentsPlugin::signature(
        'POST',
        $webhookUrl,
        'checkout-smoke-client',
        $timestamp,
        $webhookPayload,
        'checkout-smoke-secret'
    ));
    $app->instance('request', $webhookRequest);
    $coinPaymentsPlugin = new CoinPaymentsPlugin('coin_payments');
    $coinPaymentsPlugin->setConfig([
        'id' => $paymentOne->id,
        'uuid' => $paymentOne->uuid,
        'coinpayments_client_id' => 'rotated-client',
        'coinpayments_client_secret' => 'rotated-secret',
        'coinpayments_invoice_currency_id' => '9999',
        'coinpayments_cny_invoice_rate' => 9,
        'coinpayments_webhook_url' => 'https://rotated.example.test/webhook',
        'coinpayments_webhook_max_age' => 60,
    ]);
    $lateVerification = $coinPaymentsPlugin->notify([]);
    checkoutAssert(
        is_array($lateVerification) && $lateVerification['trade_no'] === $order->trade_no,
        'Credential rotation made a valid late CoinPayments webhook fail its durable snapshot check.'
    );
    $paymentOne->enable = true;
    $paymentOne->saveOrFail();
    $switchedBack = OrderService::beginCoinPaymentsCheckout($user->id, $order->trade_no, $paymentOne);
    checkoutAssert($switchedBack['cached'], 'Switching back did not reuse the original payment result.');
    checkoutAssert($providerCalls === 1, 'Payment switching created a duplicate invoice for the original payment.');

    $ambiguousOrder = Order::create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'type' => Order::TYPE_NEW_PURCHASE,
        'period' => Plan::PERIOD_MONTHLY,
        'trade_no' => 'cp_ambiguous_' . bin2hex(random_bytes(6)),
        'total_amount' => 100,
        'balance_amount' => 0,
        'status' => Order::STATUS_PENDING,
    ]);
    $ambiguous = OrderService::beginCoinPaymentsCheckout($user->id, $ambiguousOrder->trade_no, $paymentTwo);
    OrderService::failCoinPaymentsCheckout(
        $ambiguousOrder->id,
        $paymentTwo->id,
        $ambiguous['claim_token'],
        true
    );
    expectCheckoutRejected(
        fn () => OrderService::beginCoinPaymentsCheckout($user->id, $ambiguousOrder->trade_no, $paymentTwo),
        'Ambiguous provider outcome was retried.'
    );
    expectCheckoutRejected(
        fn () => OrderService::beginStandardPaymentCheckout($user->id, $ambiguousOrder->trade_no, $standardPayment),
        'A standard gateway started while a CoinPayments result was uncertain.'
    );
    expectCheckoutRejected(
        fn () => (new OrderService(Order::findOrFail($ambiguousOrder->id)))->cancel(),
        'A user-facing cancellation abandoned an uncertain payable invoice.'
    );
    checkoutAssert(
        (int) Order::findOrFail($ambiguousOrder->id)->status === Order::STATUS_PENDING,
        'Rejected uncertain cancellation changed the order status.'
    );
    checkoutAssert(
        (new OrderService(Order::findOrFail($ambiguousOrder->id)))
            ->cancelAfterManualPaymentReconciliation(),
        'Explicit admin reconciliation could not close the uncertain order.'
    );
    checkoutAssert(
        (int) Order::findOrFail($ambiguousOrder->id)->status === Order::STATUS_CANCELLED,
        'Admin-reconciled uncertain order was not cancelled.'
    );

    $tamperedOrder = Order::create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'type' => Order::TYPE_NEW_PURCHASE,
        'period' => Plan::PERIOD_MONTHLY,
        'trade_no' => 'cp_tampered_' . bin2hex(random_bytes(6)),
        'total_amount' => 100,
        'balance_amount' => 0,
        'status' => Order::STATUS_PENDING,
    ]);
    $tampered = OrderService::beginCoinPaymentsCheckout($user->id, $tamperedOrder->trade_no, $paymentOne);
    OrderService::completeCoinPaymentsCheckout(
        $tamperedOrder->id,
        $paymentOne->id,
        $tampered['claim_token'],
        [
            'type' => 1,
            'data' => 'https://checkout.coinpayments.net/original',
            'provider_invoice_id' => 'tampered-provider-invoice',
            'provider_expires_at' => time() + 3600,
            'expected_amount' => '9.27000000',
        ]
    );
    DB::table('v2_order_payment_checkout')
        ->where('order_id', $tamperedOrder->id)
        ->where('payment_id', $paymentOne->id)
        ->update(['response_data' => json_encode('http://checkout.coinpayments.net/tampered')]);
    expectCheckoutRejected(
        fn () => OrderService::beginCoinPaymentsCheckout($user->id, $tamperedOrder->trade_no, $paymentOne),
        'A non-HTTPS cached checkout URL was returned.'
    );
    checkoutAssert(
        DB::table('v2_order_payment_checkout')
            ->where('order_id', $tamperedOrder->id)
            ->where('payment_id', $paymentOne->id)
            ->value('state') === 'uncertain',
        'A non-HTTPS cached checkout URL was not quarantined for reconciliation.'
    );

    foreach ([Order::STATUS_PROCESSING, Order::STATUS_CANCELLED, Order::STATUS_COMPLETED] as $status) {
        $closedOrder = Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'cp_closed_' . $status . '_' . bin2hex(random_bytes(4)),
            'total_amount' => 100,
            'balance_amount' => 0,
            'status' => $status,
        ]);
        expectCheckoutRejected(
            fn () => OrderService::beginCoinPaymentsCheckout($user->id, $closedOrder->trade_no, $paymentOne),
            'A non-pending order was allowed to create an invoice.'
        );
    }

    echo "CoinPayments checkout claim/reuse/ownership/state checks passed.\n";
} finally {
    DB::rollBack();
}
