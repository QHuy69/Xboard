<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Http\Controllers\V2\Admin\PaymentController as AdminPaymentController;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Plugin as PluginModel;
use App\Models\UsdtDirectInvoice;
use App\Models\UsdtDirectTransfer;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Plugin\UsdtDirect\Services\UsdtDirectScanner;
use Tests\TestCase;

class UsdtDirectSettlementTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
    private const ADDRESS = 'TXLAQ63Xg1NAzckPwKHvzw7CSEmLMEqcdj';

    private PluginManager $pluginManager;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en-US');
        config()->set('app.url', 'https://xboard.example.test');
        admin_setting(['app_url' => 'https://xboard.example.test']);
        HookManager::reset();
        $this->pluginManager = app(PluginManager::class);
        $this->pluginManager->install('usdt_direct');
        $this->pluginManager->enable('usdt_direct');
    }

    protected function tearDown(): void
    {
        HookManager::reset();
        parent::tearDown();
    }

    public function test_schema_contains_durable_invoice_transfer_and_scan_cursor_guards(): void
    {
        $this->assertTrue(Schema::hasColumns('v2_usdt_direct_invoice', [
            'public_token_hash',
            'expected_amount_raw',
            'config_snapshot',
            'manual_review_reason',
        ]));
        $this->assertTrue(Schema::hasColumns('v2_usdt_direct_transfer', [
            'invoice_id',
            'txid',
            'log_index',
            'amount_raw',
            'confirmations',
            'manual_review_reason',
        ]));
        $this->assertTrue(Schema::hasColumns('v2_usdt_direct_scan_cursor', [
            'payment_id',
            'network',
            'token_contract',
            'receiving_address',
            'last_block_number',
            'last_block_timestamp',
            'last_success_at',
            'last_error_at',
            'last_error',
        ]));

        $invoiceIndexes = array_column(Schema::getIndexes('v2_usdt_direct_invoice'), 'name');
        $transferIndexes = array_column(Schema::getIndexes('v2_usdt_direct_transfer'), 'name');
        $this->assertContains('usdt_invoice_amount_assignment_unique', $invoiceIndexes);
        $this->assertContains('usdt_transfer_chain_identity_unique', $transferIndexes);
    }

    public function test_payment_can_be_hidden_but_audited_invoices_guard_gateway_and_plugin_lifecycle(): void
    {
        [$user, $order, $payment, $invoice] = $this->createCheckout();

        $this->assertTrue(
            PluginModel::query()->where('code', 'usdt_direct')->firstOrFail()->isProtected()
        );
        $this->assertTrue(OrderService::hasMonitoredUsdtDirectInvoice((int) $payment->id));

        // Hiding the payment only stops new checkout creation. Its row remains
        // available to the scanner for late-chain reconciliation.
        $hide = (new AdminPaymentController())->show(Request::create(
            '/api/v2/admin/payment/show',
            'POST',
            ['id' => $payment->id]
        ));
        $this->assertSame(200, $hide->getStatusCode());
        $this->assertFalse((bool) $payment->fresh()->enable);

        try {
            $this->pluginManager->disable('usdt_direct');
            $this->fail('USDT Direct plugin was disabled while an invoice needed monitoring.');
        } catch (\RuntimeException) {
            // Expected.
        }

        $gatewayChange = (new AdminPaymentController())->save(Request::create(
            '/api/v2/admin/payment/save',
            'POST',
            [
                'id' => $payment->id,
                'name' => 'Unsafe gateway switch',
                'icon' => 'Coinbase',
                'payment' => 'Coinbase',
                'config' => ['blocked_before_gateway_parse' => true],
            ]
        ));
        $this->assertSame(409, $gatewayChange->getStatusCode());

        $drop = (new AdminPaymentController())->drop(Request::create(
            '/api/v2/admin/payment/drop',
            'POST',
            ['id' => $payment->id]
        ));
        $this->assertSame(409, $drop->getStatusCode());
        $this->assertDatabaseHas('v2_payment', ['id' => $payment->id, 'payment' => 'UsdtDirect']);

        // Expired invoices still need to catch late transfers and therefore
        // keep the scheduler/plugin alive.
        $invoice->state = UsdtDirectInvoice::STATE_EXPIRED;
        $invoice->saveOrFail();
        $this->assertTrue(OrderService::hasMonitoredUsdtDirectInvoice((int) $payment->id));
        try {
            $this->pluginManager->disable('usdt_direct');
            $this->fail('USDT Direct plugin was disabled while an expired invoice remained monitored.');
        } catch (\RuntimeException) {
            // Expected.
        }

        // Confirmation ends chain monitoring, but the payment row remains an
        // immutable audit parent and still cannot be repurposed or deleted.
        $invoice->state = UsdtDirectInvoice::STATE_CONFIRMED;
        $invoice->saveOrFail();
        $this->assertFalse(OrderService::hasMonitoredUsdtDirectInvoice((int) $payment->id));
        $this->assertTrue(OrderService::hasUsdtDirectInvoiceForPayment((int) $payment->id));
        $this->assertTrue($this->pluginManager->disable('usdt_direct'));
        $this->assertFalse((bool) PluginModel::query()->where('code', 'usdt_direct')->value('is_enabled'));

        $dropAfterConfirmation = (new AdminPaymentController())->drop(Request::create(
            '/api/v2/admin/payment/drop',
            'POST',
            ['id' => $payment->id]
        ));
        $this->assertSame(409, $dropAfterConfirmation->getStatusCode());
    }

    public function test_admin_reconciliation_can_close_an_expired_invoice_before_cancelling_the_order(): void
    {
        [, $order, , $invoice] = $this->createCheckout();
        $invoice->state = UsdtDirectInvoice::STATE_EXPIRED;
        $invoice->saveOrFail();

        $this->assertTrue((new OrderService($order))->cancelAfterManualPaymentReconciliation());
        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
        $this->assertDatabaseHas('v2_usdt_direct_invoice', [
            'id' => $invoice->id,
            'state' => UsdtDirectInvoice::STATE_CLOSED,
            'manual_review_reason' => 'cancelled_after_manual_reconciliation',
        ]);
    }

    public function test_scanner_keeps_monitoring_historical_invoice_after_checkout_fields_are_cleared(): void
    {
        [, , $payment, $invoice] = $this->createCheckout();
        $invoice->state = UsdtDirectInvoice::STATE_EXPIRED;
        $invoice->saveOrFail();
        $payment->enable = false;
        $payment->config = [
            'usdt_receive_address' => '',
            'usdt_cny_usdt_rate' => '',
            'usdt_trongrid_api_key' => 'preview-trongrid-key',
            'usdt_scan_overlap_seconds' => '600',
            'usdt_scan_max_pages' => '25',
        ];
        $payment->saveOrFail();

        Http::fake([
            'https://api.trongrid.io/*' => Http::response([
                'success' => true,
                'data' => [],
                'meta' => [],
            ]),
        ]);

        $stats = (new UsdtDirectScanner())->scanPayment($payment->fresh());

        $this->assertFalse($stats['skipped']);
        $this->assertSame(0, $stats['candidates']);
        $this->assertSame(0, $stats['settled']);
        Http::assertSentCount(1);
    }

    public function test_scanner_ignores_unallocated_dust_before_fetching_a_receipt(): void
    {
        [, , $payment, $invoice] = $this->createCheckout();
        $dustAmount = (string) ((int) $invoice->expected_amount_raw + 1_000_000);

        Http::fake([
            'https://api.trongrid.io/*' => Http::response([
                'success' => true,
                'data' => [[
                    'transaction_id' => str_repeat('a', 64),
                    'block_timestamp' => (time() - 1) * 1000,
                    'from' => self::ADDRESS,
                    'to' => self::ADDRESS,
                    'type' => 'Transfer',
                    'value' => $dustAmount,
                    'token_info' => [
                        'address' => self::CONTRACT,
                        'decimals' => 6,
                    ],
                ]],
                'meta' => [],
            ]),
        ]);

        $stats = (new UsdtDirectScanner())->scanPayment($payment->fresh());

        $this->assertSame(0, $stats['candidates']);
        $this->assertSame(1, $stats['ignored']);
        $this->assertSame(0, $stats['transfers']);
        Http::assertSentCount(1);
    }

    public function test_checkout_is_reused_from_opaque_snapshot_and_amount_is_never_reassigned(): void
    {
        $user = $this->makeUser();
        $payment = $this->makePayment();
        $firstOrder = $this->makeOrder($user);

        $first = OrderService::beginUsdtDirectCheckout(
            $user->id,
            (string) $firstOrder->trade_no,
            $payment
        );
        $this->assertFalse($first['cached']);
        $this->assertMatchesRegularExpression('#^https?://[^/]+/pay/usdt/[A-Za-z0-9_-]{43}$#', $first['data']);
        $this->assertGreaterThanOrEqual(140000, (int) $first['amount_raw']);
        $this->assertLessThanOrEqual(149999, (int) $first['amount_raw']);

        $token = basename(parse_url($first['data'], PHP_URL_PATH));
        $invoice = $first['invoice']->fresh();
        $checkout = DB::table('v2_order_payment_checkout')->where('id', $invoice->checkout_id)->first();
        $this->assertSame(hash('sha256', $token), $invoice->public_token_hash);
        $this->assertStringNotContainsString($token, (string) $invoice->config_snapshot);
        $this->assertStringNotContainsString($token, (string) $checkout->config_snapshot);
        $this->assertNull($checkout->response_data);
        $snapshot = json_decode(Crypt::decryptString((string) $invoice->config_snapshot), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($token, $snapshot['public_token']);
        $this->assertSame((string) $invoice->expected_amount_raw, (string) $checkout->expected_amount);

        // An issued invoice remains reopenable from its frozen snapshot after
        // administrators disable or rotate the payment method.
        $payment->enable = false;
        $payment->config = array_replace($this->validConfig(), ['usdt_cny_usdt_rate' => '0.99']);
        $payment->saveOrFail();
        $retry = OrderService::beginUsdtDirectCheckout(
            $user->id,
            (string) $firstOrder->trade_no,
            $payment
        );
        $this->assertTrue($retry['cached']);
        $this->assertSame($first['data'], $retry['data']);
        $this->assertSame($first['amount_raw'], $retry['amount_raw']);
        $this->assertSame($invoice->id, $retry['invoice']->id);
        $this->assertNull(DB::table('v2_order_payment_checkout')
            ->where('id', $invoice->checkout_id)
            ->value('response_data'));

        $payment->enable = true;
        $payment->config = $this->validConfig();
        $payment->saveOrFail();
        $this->assertTrue(OrderService::expireUsdtDirectInvoice($invoice->id, $invoice->expires_at + 1));

        $second = OrderService::beginUsdtDirectCheckout(
            $user->id,
            (string) $this->makeOrder($user)->trade_no,
            $payment
        );
        $this->assertNotSame($first['amount_raw'], $second['amount_raw']);
        $this->assertDatabaseHas('v2_usdt_direct_invoice', [
            'id' => $invoice->id,
            'expected_amount_raw' => $first['amount_raw'],
            'state' => UsdtDirectInvoice::STATE_EXPIRED,
        ]);
    }

    public function test_transfer_settlement_waits_for_finality_and_is_idempotent(): void
    {
        [$user, $order, $payment, $invoice] = $this->createCheckout();
        Bus::fake([OrderHandleJob::class]);

        $pending = OrderService::settleUsdtDirectTransfer($invoice->id, $this->event($invoice, [
            'confirmations' => 2,
            'solidified' => false,
        ]));
        $this->assertTrue($pending['pending_confirmation']);
        $this->assertFalse($pending['transitioned']);
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $this->assertSame(UsdtDirectInvoice::STATE_SEEN, $invoice->fresh()->state);

        $settled = OrderService::settleUsdtDirectTransfer($invoice->id, $this->event($invoice, [
            'confirmations' => 3,
            'solidified' => false,
        ]));
        $this->assertTrue($settled['transitioned']);
        $this->assertFalse($settled['replay']);
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->assertSame(UsdtDirectInvoice::STATE_CONFIRMED, $invoice->fresh()->state);
        $this->assertSame(UsdtDirectTransfer::STATE_SETTLED, $invoice->transfers()->firstOrFail()->state);
        Bus::assertDispatchedSyncTimes(OrderHandleJob::class, 1);

        $replay = OrderService::settleUsdtDirectTransfer($invoice->id, $this->event($invoice, [
            'confirmations' => 8,
            'solidified' => true,
        ]));
        $this->assertTrue($replay['replay']);
        $this->assertFalse($replay['transitioned']);
        $this->assertSame(8, (int) $invoice->transfers()->firstOrFail()->confirmations);
        Bus::assertDispatchedSyncTimes(OrderHandleJob::class, 1);

        $contradiction = OrderService::settleUsdtDirectTransfer($invoice->id, $this->event($invoice, [
            'confirmations' => 9,
            'successful' => false,
            'solidified' => true,
        ]));
        $this->assertTrue($contradiction['manual_review']);
        $this->assertSame(UsdtDirectInvoice::STATE_CONFIRMED, $invoice->fresh()->state);
        $this->assertNull($invoice->fresh()->manual_review_reason);
        $this->assertSame(UsdtDirectTransfer::STATE_MANUAL_REVIEW, $invoice->transfers()->firstOrFail()->state);
        $this->assertSame(
            'transfer_evidence_changed',
            $invoice->transfers()->firstOrFail()->manual_review_reason
        );
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        Bus::assertDispatchedSyncTimes(OrderHandleJob::class, 1);
    }

    public function test_late_cancelled_missing_or_changed_evidence_requires_manual_review(): void
    {
        [$user, $lateOrder, $payment, $lateInvoice] = $this->createCheckout();
        $late = OrderService::settleUsdtDirectTransfer($lateInvoice->id, $this->event($lateInvoice, [
            'block_timestamp' => (int) $lateInvoice->expires_at + 1,
            'confirmations' => 30,
            'solidified' => true,
        ]));
        $this->assertTrue($late['manual_review']);
        $this->assertSame('transfer_outside_invoice_window', $lateInvoice->fresh()->manual_review_reason);
        $this->assertSame(Order::STATUS_PENDING, (int) $lateOrder->fresh()->status);

        [$user, $cancelledOrder, $payment, $cancelledInvoice] = $this->createCheckout($user, $payment);
        $service = new OrderService($cancelledOrder);
        try {
            $service->cancel();
            $this->fail('A payable USDT invoice was cancelled without reconciliation.');
        } catch (ApiException $exception) {
            $this->assertStringContainsString('verification', strtolower($exception->getMessage()));
        }
        $this->assertTrue($service->cancelAfterManualPaymentReconciliation());
        $this->assertSame(UsdtDirectInvoice::STATE_CLOSED, $cancelledInvoice->fresh()->state);
        $cancelled = OrderService::settleUsdtDirectTransfer(
            $cancelledInvoice->id,
            $this->event($cancelledInvoice, ['confirmations' => 30, 'solidified' => true])
        );
        $this->assertTrue($cancelled['manual_review']);
        $this->assertSame(Order::STATUS_CANCELLED, (int) $cancelledOrder->fresh()->status);

        [$user, $missingOrder, $payment, $missingInvoice] = $this->createCheckout($user, $payment);
        DB::table('v2_order_payment_checkout')->where('id', $missingInvoice->checkout_id)->delete();
        $missing = OrderService::settleUsdtDirectTransfer(
            $missingInvoice->id,
            $this->event($missingInvoice, ['txid' => str_repeat('b', 64), 'confirmations' => 30, 'solidified' => true])
        );
        $this->assertTrue($missing['manual_review']);
        $this->assertSame('payment_checkout_missing', $missingInvoice->fresh()->manual_review_reason);
        $this->assertDatabaseHas('v2_usdt_direct_transfer', [
            'invoice_id' => $missingInvoice->id,
            'txid' => str_repeat('b', 64),
            'state' => UsdtDirectTransfer::STATE_MANUAL_REVIEW,
        ]);

        [$user, $settledOrder, $payment, $settledInvoice] = $this->createCheckout($user, $payment);
        Bus::fake([OrderHandleJob::class]);
        OrderService::settleUsdtDirectTransfer(
            $settledInvoice->id,
            $this->event($settledInvoice, ['txid' => str_repeat('c', 64), 'confirmations' => 30, 'solidified' => true])
        );
        $changed = OrderService::settleUsdtDirectTransfer(
            $settledInvoice->id,
            $this->event($settledInvoice, [
                'txid' => str_repeat('c', 64),
                'block_hash' => str_repeat('d', 64),
                'confirmations' => 31,
                'solidified' => true,
            ])
        );
        $this->assertTrue($changed['manual_review']);
        $this->assertSame(UsdtDirectInvoice::STATE_CONFIRMED, $settledInvoice->fresh()->state);
        $this->assertNull($settledInvoice->fresh()->manual_review_reason);
        $this->assertSame(
            'transfer_evidence_changed',
            $settledInvoice->transfers()->firstOrFail()->manual_review_reason
        );
        $this->assertSame(Order::STATUS_PROCESSING, (int) $settledOrder->fresh()->status);
    }

    public function test_generic_payment_paths_cannot_bypass_an_active_usdt_invoice(): void
    {
        [$user, $order, $payment, $invoice] = $this->createCheckout();

        $this->assertFalse((new OrderService($order))->paid('unbound-callback'));
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $this->assertNull($order->fresh()->callback_no);

        $this->expectException(ApiException::class);
        OrderService::beginStandardPaymentCheckout(
            $user->id,
            (string) $order->trade_no,
            $payment
        );
    }

    /** @return array{User, Order, Payment, UsdtDirectInvoice} */
    private function createCheckout(?User $user = null, ?Payment $payment = null): array
    {
        $user ??= $this->makeUser();
        $payment ??= $this->makePayment();
        if (!(bool) $payment->enable) {
            $payment->enable = true;
            $payment->config = $this->validConfig();
            $payment->saveOrFail();
        }
        $order = $this->makeOrder($user);
        $checkout = OrderService::beginUsdtDirectCheckout(
            $user->id,
            (string) $order->trade_no,
            $payment
        );

        return [$user, $order, $payment, $checkout['invoice']->fresh()];
    }

    /** @return array<string, mixed> */
    private function event(UsdtDirectInvoice $invoice, array $overrides = []): array
    {
        return array_replace([
            'network' => 'tron',
            'token_contract' => self::CONTRACT,
            'txid' => str_repeat('a', 64),
            'log_index' => 0,
            'from_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            'to_address' => self::ADDRESS,
            'amount_raw' => (string) $invoice->expected_amount_raw,
            'block_number' => 123456,
            'block_hash' => str_repeat('e', 64),
            'block_timestamp' => (int) $invoice->created_at + 1,
            'confirmations' => 0,
            'successful' => true,
            'solidified' => false,
            'raw_payload_hash' => str_repeat('f', 64),
        ], $overrides);
    }

    private function makePayment(): Payment
    {
        return Payment::query()->create([
            'uuid' => bin2hex(random_bytes(16)),
            'payment' => 'UsdtDirect',
            'name' => 'USDT Direct',
            'icon' => 'USDT',
            'config' => $this->validConfig(),
            'handling_fee_fixed' => 0,
            'handling_fee_percent' => 0,
            'enable' => true,
            'sort' => 1,
        ]);
    }

    /** @return array<string, int|string> */
    private function validConfig(): array
    {
        return [
            'usdt_network' => 'tron',
            'usdt_token_contract' => self::CONTRACT,
            'usdt_receive_address' => self::ADDRESS,
            'usdt_cny_usdt_rate' => '0.14',
            'usdt_invoice_ttl_minutes' => 30,
            'usdt_required_confirmations' => 3,
            'usdt_trongrid_api_key' => 'test-trongrid-api-key',
            'usdt_scan_overlap_seconds' => 600,
            'usdt_scan_max_pages' => 25,
        ];
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'email' => bin2hex(random_bytes(8)) . '@example.test',
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'uuid' => bin2hex(random_bytes(16)),
            'token' => bin2hex(random_bytes(16)),
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
            'name' => 'USDT settlement plan',
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
            'trade_no' => 'usdt_direct_' . bin2hex(random_bytes(6)),
            'total_amount' => 100,
            'balance_amount' => 0,
            'status' => Order::STATUS_PENDING,
            'commission_status' => 0,
            'commission_balance' => 0,
        ]);
    }
}
