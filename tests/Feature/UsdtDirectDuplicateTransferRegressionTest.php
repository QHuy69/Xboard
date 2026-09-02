<?php

namespace Tests\Feature;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\UsdtDirectInvoice;
use App\Models\UsdtDirectTransfer;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class UsdtDirectDuplicateTransferRegressionTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
    private const ADDRESS = 'TXLAQ63Xg1NAzckPwKHvzw7CSEmLMEqcdj';

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en-US');
        config()->set('app.url', 'https://xboard.example.test');
        admin_setting(['app_url' => 'https://xboard.example.test']);
        HookManager::reset();
        $pluginManager = app(PluginManager::class);
        $pluginManager->install('usdt_direct');
        $pluginManager->enable('usdt_direct');
    }

    protected function tearDown(): void
    {
        HookManager::reset();
        parent::tearDown();
    }

    public function test_second_chain_identity_for_confirmed_exact_amount_is_audited_without_double_settlement(): void
    {
        $user = $this->makeUser();
        $payment = $this->makePayment();
        $order = $this->makeOrder($user);
        $checkout = OrderService::beginUsdtDirectCheckout(
            (int) $user->id,
            (string) $order->trade_no,
            $payment
        );
        /** @var UsdtDirectInvoice $invoice */
        $invoice = $checkout['invoice']->fresh();

        Bus::fake([OrderHandleJob::class]);

        $firstTxid = str_repeat('a', 64);
        $firstLogIndex = 0;
        $first = OrderService::settleUsdtDirectTransfer(
            (int) $invoice->id,
            $this->event($invoice, [
                'txid' => $firstTxid,
                'log_index' => $firstLogIndex,
                'confirmations' => 30,
                'solidified' => true,
            ])
        );

        $this->assertTrue($first['transitioned']);
        $this->assertFalse($first['manual_review']);
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->assertSame(UsdtDirectInvoice::STATE_CONFIRMED, (string) $invoice->fresh()->state);
        Bus::assertDispatchedSyncTimes(OrderHandleJob::class, 1);

        $settledOrder = $order->fresh();
        $settledInvoice = $invoice->fresh();
        $originalCallbackNo = (string) $settledOrder->callback_no;
        $originalPaidAt = (int) $settledOrder->paid_at;
        $originalConfirmedAt = (int) $settledInvoice->confirmed_at;

        // A customer can accidentally send the same exact amount twice. The
        // second tx/log is distinct chain evidence, not an idempotent replay.
        // It must be retained for refund/manual review without reopening the
        // already settled invoice or dispatching the order a second time.
        $secondTxid = str_repeat('b', 64);
        $secondLogIndex = 7;
        $extra = OrderService::settleUsdtDirectTransfer(
            (int) $invoice->id,
            $this->event($invoice, [
                'txid' => $secondTxid,
                'log_index' => $secondLogIndex,
                'block_number' => 123457,
                'block_hash' => str_repeat('d', 64),
                'block_timestamp' => (int) $invoice->created_at + 2,
                'confirmations' => 30,
                'solidified' => true,
                'raw_payload_hash' => str_repeat('c', 64),
            ])
        );

        $this->assertFalse($extra['transitioned']);
        $this->assertFalse($extra['replay']);
        $this->assertTrue($extra['manual_review']);

        $this->assertDatabaseHas('v2_usdt_direct_transfer', [
            'invoice_id' => $invoice->id,
            'txid' => $firstTxid,
            'log_index' => $firstLogIndex,
            'state' => UsdtDirectTransfer::STATE_SETTLED,
        ]);
        $this->assertDatabaseHas('v2_usdt_direct_transfer', [
            'invoice_id' => $invoice->id,
            'txid' => $secondTxid,
            'log_index' => $secondLogIndex,
            'state' => UsdtDirectTransfer::STATE_MANUAL_REVIEW,
            'manual_review_reason' => 'additional_transfer_after_settlement',
        ]);
        $this->assertSame(2, UsdtDirectTransfer::query()
            ->where('invoice_id', $invoice->id)
            ->count());

        $orderAfterExtra = $order->fresh();
        $invoiceAfterExtra = $invoice->fresh();
        $this->assertSame(Order::STATUS_PROCESSING, (int) $orderAfterExtra->status);
        $this->assertSame($originalCallbackNo, (string) $orderAfterExtra->callback_no);
        $this->assertSame($originalPaidAt, (int) $orderAfterExtra->paid_at);
        $this->assertSame($firstTxid, (string) $invoiceAfterExtra->txid);
        $this->assertSame($firstLogIndex, (int) $invoiceAfterExtra->log_index);
        $this->assertSame($originalConfirmedAt, (int) $invoiceAfterExtra->confirmed_at);
        $this->assertNull($invoiceAfterExtra->manual_review_reason);
        Bus::assertDispatchedSyncTimes(OrderHandleJob::class, 1);

        // Regression guard: manual-reviewing the extra transfer must not
        // downgrade the immutable settlement result of the original invoice.
        $this->assertSame(UsdtDirectInvoice::STATE_CONFIRMED, (string) $invoiceAfterExtra->state);
    }

    /** @param array<string, mixed> $overrides */
    private function event(UsdtDirectInvoice $invoice, array $overrides = []): array
    {
        return array_replace([
            'network' => 'tron',
            'token_contract' => self::CONTRACT,
            'txid' => str_repeat('a', 64),
            'log_index' => 0,
            'from_address' => self::CONTRACT,
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
            'config' => [
                'usdt_network' => 'tron',
                'usdt_token_contract' => self::CONTRACT,
                'usdt_receive_address' => self::ADDRESS,
                'usdt_cny_usdt_rate' => '0.14',
                'usdt_invoice_ttl_minutes' => 30,
                'usdt_required_confirmations' => 3,
                'usdt_trongrid_api_key' => 'test-trongrid-api-key',
                'usdt_scan_overlap_seconds' => 600,
                'usdt_scan_max_pages' => 25,
            ],
            'handling_fee_fixed' => 0,
            'handling_fee_percent' => 0,
            'enable' => true,
            'sort' => 1,
        ]);
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
            'name' => 'USDT duplicate transfer regression plan',
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
            'trade_no' => 'usdt_duplicate_' . bin2hex(random_bytes(6)),
            'total_amount' => 100,
            'balance_amount' => 0,
            'status' => Order::STATUS_PENDING,
            'commission_status' => 0,
            'commission_balance' => 0,
        ]);
    }
}
