<?php

namespace Tests\Feature;

use App\Http\Middleware\InitializePlugins;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SepayWebhookResponseTest extends TestCase
{
    use RefreshDatabase;

    private PluginManager $pluginManager;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en-US');
        HookManager::reset();
        $this->pluginManager = app(PluginManager::class);
        $this->pluginManager->install('sepay');
        $this->pluginManager->enable('sepay');
    }

    protected function tearDown(): void
    {
        HookManager::reset();
        parent::tearDown();
    }

    public function test_successful_callback_returns_required_json_and_replay_is_idempotent(): void
    {
        Bus::fake([OrderHandleJob::class]);

        $payment = $this->makePayment();
        $order = $this->makeOrder($this->makeUser());
        $payload = [
            'transferType' => 'in',
            'gateway' => 'Vietcombank',
            'code' => 'XBOARD ' . $order->trade_no,
            'transferAmount' => 25000,
            'referenceCode' => 'sepay-reference-1',
        ];

        $response = $this->withHeader('Authorization', 'Apikey webhook-test-key')
            ->postJson('/api/v1/guest/payment/notify/SePay/' . $payment->uuid, $payload);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['success' => true]);
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->refresh()->status);
        $this->assertSame('sepay-reference-1', $order->callback_no);
        Bus::assertDispatchedSyncTimes(OrderHandleJob::class, 1);

        $replay = $this->withHeader('Authorization', 'Apikey webhook-test-key')
            ->postJson('/api/v1/guest/payment/notify/SePay/' . $payment->uuid, $payload);

        $replay->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['success' => true]);
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->refresh()->status);
        $this->assertSame('sepay-reference-1', $order->callback_no);
        Bus::assertDispatchedSyncTimes(OrderHandleJob::class, 1);
    }

    public function test_authenticated_outgoing_transfer_is_acknowledged_without_payment_side_effects(): void
    {
        // Keep the test-only hook probes registered across this request. The
        // normal middleware reset is already exercised by the first test.
        $this->withoutMiddleware(InitializePlugins::class);
        Bus::fake([OrderHandleJob::class]);

        $payment = $this->makePayment();
        $order = $this->makeOrder($this->makeUser());
        $verifiedHookCalls = 0;
        $successHookCalls = 0;
        HookManager::register(
            'payment.notify.verified',
            static function () use (&$verifiedHookCalls): void {
                $verifiedHookCalls++;
            }
        );
        HookManager::register(
            'payment.notify.success',
            static function () use (&$successHookCalls): void {
                $successHookCalls++;
            }
        );

        $response = $this->withHeader('Authorization', 'Apikey webhook-test-key')
            ->postJson('/api/v1/guest/payment/notify/SePay/' . $payment->uuid, [
                'id' => 92704,
                'transferType' => 'out',
                'transferAmount' => 25000,
                'referenceCode' => 'sepay-outgoing-reference',
            ]);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['success' => true]);
        $this->assertSame(Order::STATUS_PENDING, (int) $order->refresh()->status);
        $this->assertNull($order->callback_no);
        $this->assertSame(0, $verifiedHookCalls);
        $this->assertSame(0, $successHookCalls);
        Bus::assertNotDispatched(OrderHandleJob::class);
    }

    private function makePayment(): Payment
    {
        return Payment::query()->create([
            'uuid' => bin2hex(random_bytes(16)),
            'payment' => 'SePay',
            'name' => 'SePay webhook test',
            'icon' => 'SePay',
            'config' => [
                'sepay_account_number' => 'test-account',
                'sepay_account_name' => 'TEST ACCOUNT',
                'sepay_bank_code' => 'Vietcombank',
                'sepay_api_key' => 'webhook-test-key',
                'sepay_cny_vnd_rate' => 25000,
                'sepay_transfer_prefix' => 'XBOARD',
            ],
            'handling_fee_fixed' => 0,
            'handling_fee_percent' => 0,
            'enable' => true,
        ]);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'email' => 'sepay-' . bin2hex(random_bytes(4)) . '@example.test',
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
            'name' => 'SePay webhook plan',
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
            'trade_no' => 'sepay_' . bin2hex(random_bytes(6)),
            'total_amount' => 100,
            'balance_amount' => 0,
            'status' => Order::STATUS_PENDING,
            'commission_status' => 0,
            'commission_balance' => 0,
        ]);
    }
}
