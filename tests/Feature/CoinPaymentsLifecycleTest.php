<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Http\Controllers\V2\Admin\PaymentController as AdminPaymentController;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\CoinPaymentsCheckoutSnapshot;
use App\Services\OrderService;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Plugin\CoinPayments\Plugin as CoinPaymentsPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoinPaymentsLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private PluginManager $pluginManager;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en-US');
        config()->set('app.url', 'https://xboard.example.test');
        admin_setting(['app_url' => 'https://xboard.example.test']);
        HookManager::reset();
        $this->pluginManager = app(PluginManager::class);
        $this->pluginManager->install('coin_payments');
        $this->pluginManager->enable('coin_payments');
    }

    protected function tearDown(): void
    {
        HookManager::reset();
        parent::tearDown();
    }

    public function test_non_terminal_webhooks_require_authentication_and_ack_without_order_lookup(): void
    {
        $pendingPayload = [
            'id' => 'provider-pending-without-order',
            'type' => 'InvoicePending',
            'invoice' => [
                'invoiceId' => 'ORDER-DOES-NOT-EXIST',
                'state' => 'Pending',
            ],
        ];
        $this->assertSame('success', $this->invokeStandaloneWebhook($pendingPayload));

        $this->assertSame('success', $this->invokeStandaloneWebhook([
            'type' => 'InvoicePaymentCreated',
        ]));

        try {
            $this->invokeStandaloneWebhook($pendingPayload, false);
            $this->fail('An unauthenticated pending webhook was acknowledged.');
        } catch (ApiException $exception) {
            $this->assertSame(400, $exception->getCode());
        }

        try {
            $this->invokeStandaloneWebhook([
                'invoice' => [
                    'invoiceId' => 'ORDER-DOES-NOT-EXIST',
                    'state' => 'Pending',
                ],
            ]);
            $this->fail('A signed webhook without an event type was acknowledged.');
        } catch (ApiException $exception) {
            $this->assertSame(400, $exception->getCode());
        }
    }

    public function test_ready_invoice_is_immutable_and_blocks_payment_switch_cancel_delete_and_plugin_disable(): void
    {
        [$user, $order, $payment, $expiresAt] = $this->createReadyCheckout();
        $checkout = DB::table('v2_order_payment_checkout')->where('order_id', $order->id)->first();

        $this->assertNotNull($checkout);
        $this->assertSame('ready', $checkout->state);
        $this->assertSame('provider-invoice-1', $checkout->provider_invoice_id);
        $this->assertSame($expiresAt, (int) $checkout->provider_expires_at);
        $this->assertSame('0.14000000', $checkout->expected_amount);
        $this->assertStringNotContainsString('original-payment-secret', (string) $checkout->config_snapshot);
        $snapshot = CoinPaymentsCheckoutSnapshot::decrypt((string) $checkout->config_snapshot);
        $this->assertSame('original-payment-secret', $snapshot['coinpayments_client_secret']);
        $this->assertSame($payment->uuid, $snapshot['payment_uuid']);

        $standardPayment = Payment::query()->create([
            'uuid' => bin2hex(random_bytes(16)),
            'payment' => 'StripeCheckout',
            'name' => 'Other provider',
            'icon' => 'StripeCheckout',
            'config' => [],
            'handling_fee_fixed' => 0,
            'handling_fee_percent' => 0,
            'enable' => true,
        ]);
        try {
            OrderService::beginStandardPaymentCheckout(
                (int) $user->id,
                (string) $order->trade_no,
                $standardPayment
            );
            $this->fail('A READY CoinPayments invoice allowed another provider path.');
        } catch (ApiException) {
            // Expected.
        }

        try {
            (new OrderService(Order::findOrFail($order->id)))->cancel();
            $this->fail('A customer cancelled an order with a payable CoinPayments invoice.');
        } catch (ApiException) {
            // Expected.
        }
        $this->assertSame(Order::STATUS_PENDING, (int) Order::findOrFail($order->id)->status);

        try {
            $this->pluginManager->disable('coin_payments');
            $this->fail('CoinPayments plugin was disabled while an invoice was active.');
        } catch (\RuntimeException) {
            // Expected.
        }

        $drop = (new AdminPaymentController())->drop(Request::create(
            '/api/v2/admin/payment/drop',
            'POST',
            ['id' => $payment->id]
        ));
        $this->assertSame(409, $drop->getStatusCode());
        $this->assertDatabaseHas('v2_payment', ['id' => $payment->id]);

        $gatewayChange = (new AdminPaymentController())->save(Request::create(
            '/api/v2/admin/payment/save',
            'POST',
            [
                'id' => $payment->id,
                'name' => 'Unsafe switch',
                'icon' => 'Coinbase',
                'payment' => 'Coinbase',
                // Laravel's `required|array` rejects an empty array before
                // the controller reaches the active-invoice gateway guard.
                'config' => ['blocked_before_gateway_parse' => true],
            ]
        ));
        $this->assertSame(409, $gatewayChange->getStatusCode());
        $this->assertSame('CoinPayments', Payment::findOrFail($payment->id)->payment);

        $status = $this->getJson('/payment/status/' . $order->trade_no);
        $status->assertOk()->assertJson([
            'status' => Order::STATUS_PENDING,
            'expires_at' => $expiresAt,
        ]);

        $this->assertTrue(
            (new OrderService(Order::findOrFail($order->id)))
                ->cancelAfterManualPaymentReconciliation()
        );
        $this->assertSame(Order::STATUS_CANCELLED, (int) Order::findOrFail($order->id)->status);
        $this->assertSame(
            'closed',
            DB::table('v2_order_payment_checkout')->where('order_id', $order->id)->value('state')
        );
    }

    public function test_late_webhook_uses_encrypted_snapshot_after_payment_is_disabled_and_credentials_rotate(): void
    {
        [$user, $order, $payment] = $this->createReadyCheckout();
        $snapshotRow = DB::table('v2_order_payment_checkout')->where('order_id', $order->id)->first();
        $snapshot = CoinPaymentsCheckoutSnapshot::decrypt((string) $snapshotRow->config_snapshot);

        $payment->config = array_replace($this->validConfig((string) $payment->uuid), [
            'coinpayments_client_id' => 'rotated-client',
            'coinpayments_client_secret' => 'rotated-secret',
            'coinpayments_invoice_currency_id' => '9999',
            'coinpayments_cny_invoice_rate' => 9,
        ]);
        $payment->enable = false;
        $payment->saveOrFail();

        Bus::fake([OrderHandleJob::class]);
        $payload = [
            'id' => 'provider-invoice-1',
            'type' => 'invoiceCompleted',
            'invoice' => [
                'id' => 'provider-invoice-1',
                'state' => 'completed',
                'invoiceId' => $order->trade_no,
                'customData' => ['trade_no' => $order->trade_no],
                'amount' => [
                    'currencyId' => '5057',
                    'total' => '0.14000000',
                ],
            ],
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = gmdate('Y-m-d\TH:i:s');
        $signature = CoinPaymentsPlugin::signature(
            'POST',
            (string) $snapshot['coinpayments_webhook_url'],
            'original-payment-client',
            $timestamp,
            $rawBody,
            'original-payment-secret'
        );

        $response = $this->call(
            'POST',
            '/api/v1/guest/payment/notify/CoinPayments/' . $payment->uuid,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_COINPAYMENTS_CLIENT' => 'original-payment-client',
                'HTTP_X_COINPAYMENTS_TIMESTAMP' => $timestamp,
                'HTTP_X_COINPAYMENTS_SIGNATURE' => $signature,
            ],
            $rawBody
        );

        $response->assertOk();
        $this->assertSame('success', $response->getContent());
        $order->refresh();
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->status);
        $this->assertSame('provider-invoice-1', $order->callback_no);
        Bus::assertDispatchedSync(OrderHandleJob::class);

        $replay = $this->call(
            'POST',
            '/api/v1/guest/payment/notify/CoinPayments/' . $payment->uuid,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_COINPAYMENTS_CLIENT' => 'original-payment-client',
                'HTTP_X_COINPAYMENTS_TIMESTAMP' => $timestamp,
                'HTTP_X_COINPAYMENTS_SIGNATURE' => $signature,
            ],
            $rawBody
        );
        $replay->assertOk();

        $terminalAfterCompletion = $this->sendCoinPaymentsWebhook(
            $order,
            $payment,
            'InvoiceTimedOut',
            'TimedOut'
        );
        $terminalAfterCompletion->assertStatus(409);

        // An authenticated callback for another provider invoice is rejected
        // even though it carries the same merchant order reference.
        $payload['id'] = 'wrong-provider-invoice';
        $payload['invoice']['id'] = 'wrong-provider-invoice';
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = gmdate('Y-m-d\TH:i:s');
        $signature = CoinPaymentsPlugin::signature(
            'POST',
            (string) $snapshot['coinpayments_webhook_url'],
            'original-payment-client',
            $timestamp,
            $rawBody,
            'original-payment-secret'
        );
        $wrongInvoice = $this->call(
            'POST',
            '/api/v1/guest/payment/notify/CoinPayments/' . $payment->uuid,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_COINPAYMENTS_CLIENT' => 'original-payment-client',
                'HTTP_X_COINPAYMENTS_TIMESTAMP' => $timestamp,
                'HTTP_X_COINPAYMENTS_SIGNATURE' => $signature,
            ],
            $rawBody
        );
        $wrongInvoice->assertStatus(400);
    }

    public function test_standard_checkout_is_durable_cached_and_blocks_every_other_provider_path(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user);
        $coinPayments = $this->makePayment();
        $standard = $this->makeStandardPayment();

        $first = OrderService::beginStandardPaymentCheckout(
            (int) $user->id,
            (string) $order->trade_no,
            $standard
        );
        $this->assertFalse($first['cached']);
        $this->assertSame('creating', DB::table('v2_order_payment_checkout')
            ->where('order_id', $order->id)
            ->value('state'));

        OrderService::completeStandardPaymentCheckout(
            (int) $order->id,
            (int) $standard->id,
            (string) $first['claim_token'],
            ['type' => 1, 'data' => 'https://standard.example.test/pay/one']
        );
        $cached = OrderService::beginStandardPaymentCheckout(
            (int) $user->id,
            (string) $order->trade_no,
            $standard
        );
        $this->assertTrue($cached['cached']);
        $this->assertSame('https://standard.example.test/pay/one', $cached['data']);

        try {
            OrderService::beginCoinPaymentsCheckout(
                (int) $user->id,
                (string) $order->trade_no,
                $coinPayments
            );
            $this->fail('CoinPayments started after a standard provider returned a payable checkout.');
        } catch (ApiException) {
            // Expected.
        }

        try {
            (new OrderService(Order::findOrFail($order->id)))->cancel();
            $this->fail('A customer cancelled an order with an active standard checkout.');
        } catch (ApiException) {
            // Expected.
        }
    }

    public function test_ambiguous_standard_checkout_failure_blocks_retry_and_coinpayments(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user);
        $coinPayments = $this->makePayment();
        $standard = $this->makeStandardPayment();
        $claim = OrderService::beginStandardPaymentCheckout(
            (int) $user->id,
            (string) $order->trade_no,
            $standard
        );
        OrderService::failStandardPaymentCheckout(
            (int) $order->id,
            (int) $standard->id,
            (string) $claim['claim_token'],
            true
        );
        $this->assertSame('uncertain', DB::table('v2_order_payment_checkout')
            ->where('order_id', $order->id)
            ->value('state'));

        foreach ([
            fn () => OrderService::beginStandardPaymentCheckout(
                (int) $user->id,
                (string) $order->trade_no,
                $standard
            ),
            fn () => OrderService::beginCoinPaymentsCheckout(
                (int) $user->id,
                (string) $order->trade_no,
                $coinPayments
            ),
        ] as $retry) {
            try {
                $retry();
                $this->fail('An ambiguous provider result allowed another payable path.');
            } catch (ApiException) {
                // Expected.
            }
        }
    }

    #[DataProvider('terminalEventProvider')]
    public function test_authenticated_terminal_event_cancels_pending_order_once(
        string $eventType,
        string $invoiceState
    ): void {
        [$user, $order, $payment] = $this->createReadyCheckout();
        $order->balance_amount = 25;
        $order->saveOrFail();
        if ($eventType === 'InvoiceCancelled') {
            DB::table('v2_order_payment_checkout')
                ->where('order_id', $order->id)
                ->update(['provider_invoice_id' => null]);
        }
        Bus::fake([OrderHandleJob::class]);

        $first = $this->sendCoinPaymentsWebhook($order, $payment, $eventType, $invoiceState);
        $first->assertOk();
        $this->assertSame('success', $first->getContent());
        $this->assertSame(Order::STATUS_CANCELLED, (int) Order::findOrFail($order->id)->status);
        $this->assertSame('closed', DB::table('v2_order_payment_checkout')
            ->where('order_id', $order->id)
            ->value('state'));
        $this->assertSame('provider-invoice-1', DB::table('v2_order_payment_checkout')
            ->where('order_id', $order->id)
            ->value('provider_invoice_id'));
        $this->assertSame(25, (int) User::findOrFail($user->id)->balance);

        $replay = $this->sendCoinPaymentsWebhook($order, $payment, $eventType, $invoiceState);
        $replay->assertOk();
        $this->assertSame(25, (int) User::findOrFail($user->id)->balance);
        Bus::assertNotDispatched(OrderHandleJob::class);
    }

    public static function terminalEventProvider(): array
    {
        return [
            'timed out' => ['InvoiceTimedOut', 'TimedOut'],
            'cancelled' => ['InvoiceCancelled', 'Cancelled'],
        ];
    }

    public function test_terminal_event_with_mismatched_invoice_state_is_rejected(): void
    {
        [, $order, $payment] = $this->createReadyCheckout();
        $response = $this->sendCoinPaymentsWebhook(
            $order,
            $payment,
            'InvoiceTimedOut',
            'Completed'
        );
        $response->assertStatus(400);
        $this->assertSame(Order::STATUS_PENDING, (int) Order::findOrFail($order->id)->status);
        $this->assertSame('ready', DB::table('v2_order_payment_checkout')
            ->where('order_id', $order->id)
            ->value('state'));
    }

    public function test_completed_webhook_on_cancelled_order_returns_reconciliation_conflict(): void
    {
        [, $order, $payment] = $this->createReadyCheckout();
        $this->assertTrue(
            (new OrderService(Order::findOrFail($order->id)))
                ->cancelAfterManualPaymentReconciliation()
        );

        $response = $this->sendCoinPaymentsWebhook(
            $order,
            $payment,
            'InvoiceCompleted',
            'Completed'
        );
        $response->assertStatus(409);
        $this->assertSame(Order::STATUS_CANCELLED, (int) Order::findOrFail($order->id)->status);
    }

    public function test_legacy_unbound_invoice_on_order_paid_elsewhere_is_bound_then_returns_409(): void
    {
        [, $order, $payment] = $this->createReadyCheckout();
        $standard = $this->makeStandardPayment();
        DB::table('v2_order_payment_checkout')
            ->where('order_id', $order->id)
            ->update(['provider_invoice_id' => null]);
        $order->status = Order::STATUS_COMPLETED;
        $order->payment_id = $standard->id;
        $order->callback_no = 'another-provider-payment';
        $order->saveOrFail();

        $response = $this->sendCoinPaymentsWebhook(
            $order,
            $payment,
            'InvoiceCompleted',
            'Completed'
        );
        $response->assertStatus(409);
        $this->assertSame('provider-invoice-1', DB::table('v2_order_payment_checkout')
            ->where('order_id', $order->id)
            ->value('provider_invoice_id'));
        $this->assertSame('another-provider-payment', Order::findOrFail($order->id)->callback_no);
    }

    public function test_missing_provider_expiry_is_ambiguous_and_never_becomes_ready(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user);
        $payment = $this->makePayment();
        Http::fake([
            'https://a-api.coinpayments.net/*' => Http::response([
                'invoices' => [[
                    'id' => 'provider-invoice-without-expiry',
                    'checkoutLink' => 'https://checkout.coinpayments.net/invoices/provider-invoice-without-expiry',
                    'payment' => [],
                ]],
            ], 200),
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/user/order/checkout', [
            'trade_no' => $order->trade_no,
            'method' => $payment->id,
        ]);
        $response->assertStatus(503);
        $this->assertSame('uncertain', DB::table('v2_order_payment_checkout')
            ->where('order_id', $order->id)
            ->value('state'));
        $this->assertNull(DB::table('v2_order_payment_checkout')
            ->where('order_id', $order->id)
            ->value('response_data'));
    }

    public function test_provider_validation_detail_is_surfaced_and_checkout_can_be_retried(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user);
        $payment = $this->makePayment();
        Http::fake([
            'https://a-api.coinpayments.net/*' => Http::response([
                'title' => 'Invoice validation failed',
                'errors' => [
                    'amount' => ['Amount is below the conversion limit.'],
                ],
            ], 400),
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/user/order/checkout', [
            'trade_no' => $order->trade_no,
            'method' => $payment->id,
        ]);

        $response->assertStatus(400)->assertJson([
            'status' => 'fail',
            'message' => 'CoinPayments could not create the invoice (HTTP 400). '
                . 'Provider response: Invoice validation failed; Amount is below the conversion limit.',
        ]);
        $this->assertSame('failed', DB::table('v2_order_payment_checkout')
            ->where('order_id', $order->id)
            ->value('state'));
        $this->assertNull(DB::table('v2_order_payment_checkout')
            ->where('order_id', $order->id)
            ->value('provider_invoice_id'));
    }

    public function test_corrupted_non_null_snapshot_fails_closed(): void
    {
        [, $order, $payment] = $this->createReadyCheckout();
        DB::table('v2_order_payment_checkout')
            ->where('order_id', $order->id)
            ->update(['config_snapshot' => 'not-valid-ciphertext']);

        $payload = json_encode([
            'id' => 'corrupt-event',
            'type' => 'invoiceCompleted',
            'invoice' => [
                'id' => 'provider-invoice-1',
                'state' => 'completed',
                'invoiceId' => $order->trade_no,
                'amount' => ['currencyId' => '5057', 'total' => '0.14000000'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $response = $this->call(
            'POST',
            '/api/v1/guest/payment/notify/CoinPayments/' . $payment->uuid,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_COINPAYMENTS_CLIENT' => 'original-payment-client',
                'HTTP_X_COINPAYMENTS_TIMESTAMP' => gmdate('Y-m-d\TH:i:s'),
                'HTTP_X_COINPAYMENTS_SIGNATURE' => 'invalid',
            ],
            $payload
        );

        $response->assertStatus(400);
        $this->assertSame(Order::STATUS_PENDING, (int) Order::findOrFail($order->id)->status);
    }

    /** @return array{User, Order, Payment, int} */
    private function createReadyCheckout(): array
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user);
        $payment = $this->makePayment();
        $expiresAt = time() + 3600;
        Http::fake([
            'https://a-api.coinpayments.net/*' => Http::response([
                'invoices' => [[
                    'id' => 'provider-invoice-1',
                    'checkoutLink' => 'https://checkout.coinpayments.net/invoices/provider-invoice-1',
                    'payment' => ['expires' => gmdate(DATE_ATOM, $expiresAt)],
                ]],
            ], 200),
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/user/order/checkout', [
            'trade_no' => $order->trade_no,
            'method' => $payment->id,
        ]);
        $response->assertOk()->assertJson([
            'type' => 1,
            'data' => 'https://checkout.coinpayments.net/invoices/provider-invoice-1',
        ]);
        Http::assertSent(function ($request) use ($order): bool {
            return $request->url() === 'https://a-api.coinpayments.net/api/v2/merchant/invoices'
                && data_get($request->data(), 'poNumber') === CoinPaymentsPlugin::providerPoNumber($order->trade_no)
                && strlen((string) data_get($request->data(), 'poNumber')) === 16
                && data_get($request->data(), 'invoiceId') === $order->trade_no
                && str_contains(
                    (string) data_get($request->data(), 'payment.successUrl'),
                    '/orders?trade_no=' . rawurlencode((string) $order->trade_no)
                )
                && data_get($request->data(), 'payment.successUrl') === data_get($request->data(), 'payment.cancelUrl')
                && data_get($request->data(), 'webhooks.0.notifications') === [
                    'invoiceCompleted',
                    'invoiceTimedOut',
                    'invoiceCancelled',
                ];
        });

        return [$user, $order, $payment, $expiresAt];
    }

    private function invokeStandaloneWebhook(array $payload, bool $validSignature = true): array|string
    {
        $webhookUrl = 'https://payments.example.test/coinpayments/callback';
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = gmdate('Y-m-d\TH:i:s');
        $request = Request::create($webhookUrl, 'POST', [], [], [], [], $rawBody);
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('X-CoinPayments-Client', 'standalone-client');
        $request->headers->set('X-CoinPayments-Timestamp', $timestamp);
        $request->headers->set(
            'X-CoinPayments-Signature',
            $validSignature
                ? CoinPaymentsPlugin::signature(
                    'POST',
                    $webhookUrl,
                    'standalone-client',
                    $timestamp,
                    $rawBody,
                    'standalone-secret'
                )
                : 'invalid-signature'
        );
        app()->instance('request', $request);

        $plugin = new CoinPaymentsPlugin('coin_payments');
        $plugin->setConfig([
            'coinpayments_client_id' => 'standalone-client',
            'coinpayments_client_secret' => 'standalone-secret',
            'coinpayments_webhook_url' => $webhookUrl,
            'coinpayments_webhook_max_age' => 300,
        ]);

        return $plugin->notify([]);
    }

    private function sendCoinPaymentsWebhook(
        Order $order,
        Payment $payment,
        string $eventType,
        string $invoiceState
    ) {
        $checkout = DB::table('v2_order_payment_checkout')
            ->where('order_id', $order->id)
            ->first();
        $this->assertNotNull($checkout);
        $snapshot = CoinPaymentsCheckoutSnapshot::decrypt((string) $checkout->config_snapshot);
        $providerInvoiceId = trim((string) ($checkout->provider_invoice_id ?: 'provider-invoice-1'));
        $payload = [
            'id' => $providerInvoiceId,
            'type' => $eventType,
            'invoice' => [
                'id' => $providerInvoiceId,
                'state' => $invoiceState,
                'invoiceId' => $order->trade_no,
                'customData' => ['trade_no' => $order->trade_no],
                'amount' => [
                    'currencyId' => '5057',
                    'total' => '0.14000000',
                ],
            ],
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = gmdate('Y-m-d\TH:i:s');
        $signature = CoinPaymentsPlugin::signature(
            'POST',
            (string) $snapshot['coinpayments_webhook_url'],
            (string) $snapshot['coinpayments_client_id'],
            $timestamp,
            $rawBody,
            (string) $snapshot['coinpayments_client_secret']
        );

        return $this->call(
            'POST',
            '/api/v1/guest/payment/notify/CoinPayments/' . $payment->uuid,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_COINPAYMENTS_CLIENT' => (string) $snapshot['coinpayments_client_id'],
                'HTTP_X_COINPAYMENTS_TIMESTAMP' => $timestamp,
                'HTTP_X_COINPAYMENTS_SIGNATURE' => $signature,
            ],
            $rawBody
        );
    }

    private function makeStandardPayment(): Payment
    {
        return Payment::query()->create([
            'uuid' => bin2hex(random_bytes(16)),
            'payment' => 'Coinbase',
            'name' => 'Durable standard checkout',
            'icon' => 'Coinbase',
            'config' => [],
            'handling_fee_fixed' => 0,
            'handling_fee_percent' => 0,
            'enable' => true,
        ]);
    }

    private function validConfig(string $uuid): array
    {
        return [
            'coinpayments_client_id' => 'original-payment-client',
            'coinpayments_client_secret' => 'original-payment-secret',
            'coinpayments_invoice_currency_id' => '5057',
            'coinpayments_payment_currency' => '',
            'coinpayments_cny_invoice_rate' => 0.14,
            'coinpayments_api_base' => 'https://a-api.coinpayments.net',
            'coinpayments_webhook_url' => 'https://xboard.example.test/api/v1/guest/payment/notify/CoinPayments/' . $uuid,
            'coinpayments_webhook_max_age' => 300,
        ];
    }

    private function makePayment(): Payment
    {
        $uuid = bin2hex(random_bytes(16));
        return Payment::query()->create([
            'uuid' => $uuid,
            'payment' => 'CoinPayments',
            'name' => 'CoinPayments lifecycle',
            'icon' => 'CoinPayments',
            'config' => $this->validConfig($uuid),
            'handling_fee_fixed' => 0,
            'handling_fee_percent' => 0,
            'enable' => true,
        ]);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'email' => 'coinpayments-lifecycle-' . bin2hex(random_bytes(4)) . '@example.test',
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
    }

    private function makeOrder(User $user): Order
    {
        $plan = Plan::query()->create([
            'group_id' => null,
            'transfer_enable' => 5,
            'name' => 'CoinPayments lifecycle plan',
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

        return Order::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'cp_lifecycle_' . bin2hex(random_bytes(6)),
            'total_amount' => 100,
            'balance_amount' => 0,
            'status' => Order::STATUS_PENDING,
            'commission_status' => 0,
            'commission_balance' => 0,
        ]);
    }
}
