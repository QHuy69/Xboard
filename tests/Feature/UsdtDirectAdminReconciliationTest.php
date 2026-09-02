<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\UsdtDirectInvoice;
use App\Models\UsdtDirectTransfer;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use App\Services\UsdtDirectReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UsdtDirectAdminReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = 'TLsV52sRDL79HXGGm9yzwKibb6BeruhUzy';
    private const CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

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

    public function test_non_admin_cannot_read_or_close_reconciliation_records(): void
    {
        [, , , $invoice] = $this->createCheckout();
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/v2/Huy2006/usdt-direct/invoices')->assertForbidden();
        $this->getJson('/api/v2/Huy2006/usdt-direct/invoices/' . $invoice->id)->assertForbidden();
        $this->postJson('/api/v2/Huy2006/usdt-direct/invoices/' . $invoice->id . '/close', [
            'resolution' => 'cancelled_unpaid',
            'reason' => 'Operator verified no payment arrived.',
        ])->assertForbidden();

        $this->assertSame(UsdtDirectInvoice::STATE_AWAITING, (string) $invoice->fresh()->state);
        $this->assertDatabaseMissing('v2_admin_audit_log', [
            'action' => UsdtDirectReconciliationService::AUDIT_ACTION,
        ]);
    }

    public function test_admin_can_filter_list_and_inspect_redacted_invoice_evidence(): void
    {
        [$customer, $order, $payment, $invoice] = $this->createCheckout();
        $invoice->state = UsdtDirectInvoice::STATE_MANUAL_REVIEW;
        $invoice->manual_review_reason = 'transfer_evidence_changed';
        $invoice->saveOrFail();
        $transfer = $this->makeTransfer($invoice);

        $admin = $this->makeUser(true);
        Sanctum::actingAs($admin);

        $list = $this->getJson(
            '/api/v2/Huy2006/usdt-direct/invoices?state=manual_review&trade_no=' . $order->trade_no
        );
        $list->assertOk();
        $this->assertSame(1, $list->json('pagination.total'));
        $row = $list->json('data.0');
        $this->assertSame($invoice->id, $row['id']);
        $this->assertSame($payment->id, $row['payment_id']);
        $this->assertSame($customer->email, $row['order']['user']['email']);
        $this->assertSame(1, $row['transfers_count']);
        $this->assertArrayNotHasKey('public_token_hash', $row);
        $this->assertArrayNotHasKey('config_snapshot', $row);

        $detail = $this->getJson('/api/v2/Huy2006/usdt-direct/invoices/' . $invoice->id);
        $detail->assertOk()->assertJson([
            'status' => 'success',
            'data' => [
                'id' => $invoice->id,
                'state' => UsdtDirectInvoice::STATE_MANUAL_REVIEW,
                'transfers' => [[
                    'txid' => $transfer->txid,
                    'from_address' => $transfer->from_address,
                    'amount_raw' => $transfer->amount_raw,
                    'manual_review_reason' => 'operator_review_required',
                ]],
                'reconciliation_audit' => [],
            ],
        ]);
        $detailData = $detail->json('data');
        $this->assertArrayNotHasKey('public_token_hash', $detailData);
        $this->assertArrayNotHasKey('config_snapshot', $detailData);
        $this->assertArrayNotHasKey('raw_payload_hash', $detailData['transfers'][0]);
    }

    public function test_admin_close_cancels_pending_order_and_records_explicit_audit(): void
    {
        [, $order, , $invoice] = $this->createCheckout();
        $invoice->state = UsdtDirectInvoice::STATE_MANUAL_REVIEW;
        $invoice->manual_review_reason = 'operator_review_required';
        $invoice->saveOrFail();
        DB::table('v2_order_payment_checkout')->where('id', $invoice->checkout_id)->update([
            'state' => 'uncertain',
            'updated_at' => time(),
        ]);

        $admin = $this->makeUser(true);
        Sanctum::actingAs($admin);
        $reason = 'Customer confirmed that no transfer was submitted.';

        $response = $this->postJson(
            '/api/v2/Huy2006/usdt-direct/invoices/' . $invoice->id . '/close',
            ['resolution' => 'cancelled_unpaid', 'reason' => $reason]
        );
        $response->assertOk()->assertJson([
            'status' => 'success',
            'data' => [
                'id' => $invoice->id,
                'state' => UsdtDirectInvoice::STATE_CLOSED,
                'order' => ['status' => Order::STATUS_CANCELLED],
                'reconciliation_audit' => [[
                    'operator' => ['id' => $admin->id, 'email' => $admin->email],
                    'resolution' => 'cancelled_unpaid',
                    'reason' => $reason,
                    'previous_invoice_state' => UsdtDirectInvoice::STATE_MANUAL_REVIEW,
                    'invoice_state' => UsdtDirectInvoice::STATE_CLOSED,
                    'order_status' => Order::STATUS_CANCELLED,
                ]],
            ],
        ]);

        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
        $this->assertSame(UsdtDirectInvoice::STATE_CLOSED, (string) $invoice->fresh()->state);
        $this->assertDatabaseHas('v2_order_payment_checkout', [
            'id' => $invoice->checkout_id,
            'state' => 'closed',
            'claim_token' => null,
            'response_data' => null,
        ]);

        $audit = AdminAuditLog::query()
            ->where('action', UsdtDirectReconciliationService::AUDIT_ACTION)
            ->sole();
        $this->assertSame($admin->id, (int) $audit->admin_id);
        $this->assertSame('POST', (string) $audit->method);
        $auditData = json_decode((string) $audit->request_data, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($invoice->id, $auditData['invoice_id']);
        $this->assertSame($order->id, $auditData['order_id']);
        $this->assertSame('cancelled_unpaid', $auditData['resolution']);
        $this->assertSame($reason, $auditData['reason']);
    }

    public function test_cancellation_rolls_back_when_explicit_audit_cannot_be_written(): void
    {
        [, $order, , $invoice] = $this->createCheckout();
        $invoice->state = UsdtDirectInvoice::STATE_MANUAL_REVIEW;
        $invoice->manual_review_reason = 'operator_review_required';
        $invoice->saveOrFail();
        DB::table('v2_order_payment_checkout')->where('id', $invoice->checkout_id)->update([
            'state' => 'uncertain',
            'updated_at' => time(),
        ]);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fail_usdt_reconciliation_audit
            BEFORE INSERT ON v2_admin_audit_log
            WHEN NEW.action = 'usdt_direct.reconcile_close'
            BEGIN
                SELECT RAISE(ABORT, 'forced reconciliation audit failure');
            END
            SQL);

        $admin = $this->makeUser(true);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v2/Huy2006/usdt-direct/invoices/' . $invoice->id . '/close', [
            'resolution' => 'cancelled_unpaid',
            'reason' => 'Rollback because the explicit audit is unavailable.',
        ])->assertStatus(503);

        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $this->assertSame(UsdtDirectInvoice::STATE_MANUAL_REVIEW, (string) $invoice->fresh()->state);
        $this->assertDatabaseHas('v2_order_payment_checkout', [
            'id' => $invoice->checkout_id,
            'state' => 'uncertain',
        ]);
        $this->assertDatabaseMissing('v2_admin_audit_log', [
            'action' => UsdtDirectReconciliationService::AUDIT_ACTION,
        ]);
    }

    public function test_live_confirmed_and_closed_invoices_cannot_be_manually_closed(): void
    {
        $admin = $this->makeUser(true);
        Sanctum::actingAs($admin);

        foreach ([
            UsdtDirectInvoice::STATE_AWAITING,
            UsdtDirectInvoice::STATE_SEEN,
            UsdtDirectInvoice::STATE_CONFIRMED,
            UsdtDirectInvoice::STATE_CLOSED,
        ] as $state) {
            [, $order, , $invoice] = $this->createCheckout();
            $invoice->state = $state;
            $invoice->saveOrFail();

            $this->postJson('/api/v2/Huy2006/usdt-direct/invoices/' . $invoice->id . '/close', [
                'resolution' => 'cancelled_unpaid',
                'reason' => 'This operation must be rejected.',
            ])->assertStatus(409);

            $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
            $this->assertSame($state, (string) $invoice->fresh()->state);
        }

        $this->assertDatabaseMissing('v2_admin_audit_log', [
            'action' => UsdtDirectReconciliationService::AUDIT_ACTION,
        ]);
    }

    public function test_close_requires_a_meaningful_trimmed_reason(): void
    {
        [, , , $invoice] = $this->createCheckout();
        $invoice->state = UsdtDirectInvoice::STATE_EXPIRED;
        $invoice->saveOrFail();
        $admin = $this->makeUser(true);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v2/Huy2006/usdt-direct/invoices/' . $invoice->id . '/close', [
            'resolution' => 'cancelled_unpaid',
            'reason' => '       ',
        ])->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->assertSame(Order::STATUS_PENDING, (int) $invoice->order->fresh()->status);
        $this->assertSame(UsdtDirectInvoice::STATE_EXPIRED, (string) $invoice->fresh()->state);
    }

    /** @return array{User, Order, Payment, UsdtDirectInvoice} */
    private function createCheckout(): array
    {
        $user = $this->makeUser();
        $payment = Payment::query()->create([
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
        $plan = Plan::query()->create([
            'group_id' => null,
            'transfer_enable' => 5,
            'name' => 'USDT admin reconciliation plan',
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
        $order = Order::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'usdt_admin_' . bin2hex(random_bytes(6)),
            'total_amount' => 100,
            'balance_amount' => 0,
            'status' => Order::STATUS_PENDING,
            'commission_status' => 0,
            'commission_balance' => 0,
        ]);
        $checkout = OrderService::beginUsdtDirectCheckout(
            $user->id,
            (string) $order->trade_no,
            $payment
        );

        return [$user, $order, $payment, $checkout['invoice']->fresh()];
    }

    private function makeTransfer(UsdtDirectInvoice $invoice): UsdtDirectTransfer
    {
        return $invoice->transfers()->create([
            'network' => 'tron',
            'token_contract' => self::CONTRACT,
            'txid' => str_repeat('a', 64),
            'log_index' => 0,
            'from_address' => 'TYMwi9sJp1gkK3kCcZfLLv1v4wKG6F8Lxz',
            'to_address' => self::ADDRESS,
            'amount_raw' => (string) $invoice->expected_amount_raw,
            'block_number' => 123456,
            'block_hash' => str_repeat('e', 64),
            'block_timestamp' => time() - 10,
            'confirmations' => 2,
            'state' => UsdtDirectTransfer::STATE_MANUAL_REVIEW,
            'manual_review_reason' => 'operator_review_required',
            'raw_payload_hash' => str_repeat('f', 64),
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

    private function makeUser(bool $admin = false): User
    {
        return User::query()->create([
            'email' => bin2hex(random_bytes(8)) . '@example.test',
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'uuid' => bin2hex(random_bytes(16)),
            'token' => bin2hex(random_bytes(16)),
            'banned' => 0,
            'is_admin' => $admin,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
        ]);
    }
}
