<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\OrderService;
use App\Services\TelegramResellerService;
use App\Services\TelegramService;
use App\Services\TicketService;
use App\Utils\Helper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class TelegramBackendInvariant20260901Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        HookManager::reset();
    }

    protected function tearDown(): void
    {
        HookManager::reset();
        parent::tearDown();
    }

    public function test_reseller_full_coupon_cannot_cash_out_old_plan_surplus(): void
    {
        admin_setting(['surplus_enable' => 1, 'plan_change_enable' => 1]);
        $actor = $this->makeUser('reseller-discount@example.test', [
            'is_reseller' => true,
        ]);
        $oldPlan = $this->makePlan();
        $plan = $this->makePlan();
        $customer = $this->makeUser('reseller-customer@example.test', [
            'invite_user_id' => $actor->id,
            'plan_id' => $oldPlan->id,
            'group_id' => $oldPlan->group_id,
            'expired_at' => time() + 2_592_000,
            'discount' => 20,
            'balance' => 700,
        ]);
        $oldOrder = Order::query()->create([
            'user_id' => $customer->id,
            'plan_id' => $oldPlan->id,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'telegram_old_' . bin2hex(random_bytes(6)),
            'total_amount' => 1500,
            'balance_amount' => 0,
            'surplus_amount' => 0,
            'surplus_credit' => 0,
            'status' => Order::STATUS_COMPLETED,
            'commission_status' => 0,
            'commission_balance' => 0,
            'created_at' => time() - 3600,
            'updated_at' => time() - 3600,
        ]);
        $coupon = Coupon::query()->create([
            'code' => 'TELEGRAM-FULL-DISCOUNT',
            'name' => 'Telegram reseller full discount',
            'type' => 2,
            'value' => 100,
            'show' => true,
            'limit_use' => null,
            'limit_use_with_user' => null,
            'limit_plan_ids' => [$plan->id],
            'limit_period' => [Plan::PERIOD_MONTHLY],
            'started_at' => time() - 60,
            'ended_at' => time() + 3600,
        ]);

        $resellerService = app(TelegramResellerService::class);
        $operationNonce = 'a1b2c3d4e5f60718';
        try {
            $resellerService->purchaseForCustomer(
                $actor,
                (int) $customer->id,
                (int) $plan->id,
                Plan::PERIOD_MONTHLY,
                'INVALID-COUPON',
                $operationNonce,
            );
            $this->fail('A rejected purchase unexpectedly committed its operation receipt.');
        } catch (ApiException) {
            $this->assertSame(1, Order::query()->where('user_id', $customer->id)->count());
        }

        // The failed claim above must roll back with the business transaction,
        // allowing the customer to correct the coupon without restarting.
        $result = $resellerService->purchaseForCustomer(
            $actor,
            (int) $customer->id,
            (int) $plan->id,
            Plan::PERIOD_MONTHLY,
            (string) $coupon->code,
            $operationNonce,
        );

        $order = $result['order']->fresh();
        $this->assertSame(0, (int) $order->total_amount);
        $this->assertSame(1500, (int) $order->discount_amount);
        $this->assertSame(0, (int) ($order->balance_amount ?? 0));
        $this->assertSame(0, (int) ($order->surplus_amount ?? 0));
        $this->assertSame(0, (int) ($order->surplus_credit ?? 0));
        $this->assertEmpty($order->surplus_order_ids ?? []);
        $this->assertSame(700, (int) $customer->fresh()->balance);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $oldOrder->fresh()->status);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->status);

        $orderCount = Order::query()->where('user_id', $customer->id)->count();
        try {
            $resellerService->purchaseForCustomer(
                $actor,
                (int) $customer->id,
                (int) $plan->id,
                Plan::PERIOD_MONTHLY,
                (string) $coupon->code,
                $operationNonce,
            );
            $this->fail('A durable Telegram operation receipt allowed a replayed purchase.');
        } catch (ApiException) {
            $this->assertSame($orderCount, Order::query()->where('user_id', $customer->id)->count());
        }
    }

    public function test_create_and_reset_receipts_prevent_replay_after_cache_loss(): void
    {
        admin_setting(['surplus_enable' => 1, 'plan_change_enable' => 1]);
        $actor = $this->makeUser('reseller-durable-receipts@example.test', [
            'is_reseller' => true,
        ]);
        $plan = $this->makePlan();
        $coupon = Coupon::query()->create([
            'code' => 'TELEGRAM-CREATE-FULL-DISCOUNT',
            'name' => 'Telegram create full discount',
            'type' => 2,
            'value' => 100,
            'show' => true,
            'limit_use' => null,
            'limit_use_with_user' => null,
            'limit_plan_ids' => [$plan->id],
            'limit_period' => [Plan::PERIOD_MONTHLY],
            'started_at' => time() - 60,
            'ended_at' => time() + 3600,
        ]);
        $resellerService = app(TelegramResellerService::class);
        $createNonce = '1020304050607080';

        $result = $resellerService->createCustomer(
            $actor,
            (int) $plan->id,
            Plan::PERIOD_MONTHLY,
            (string) $coupon->code,
            $createNonce,
            'vi-VN',
        );
        $customer = $result['user']->fresh();
        $this->assertSame((int) $actor->id, (int) $customer->invite_user_id);
        $this->assertSame('vi-VN', (string) $customer->locale);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $result['order']->fresh()->status);
        $generatedCount = User::query()
            ->where('email', 'like', '%@' . TelegramResellerService::GENERATED_EMAIL_DOMAIN)
            ->count();

        Cache::flush();
        try {
            $resellerService->createCustomer(
                $actor,
                (int) $plan->id,
                Plan::PERIOD_MONTHLY,
                (string) $coupon->code,
                $createNonce,
                'vi-VN',
            );
            $this->fail('A durable Telegram receipt allowed a replayed customer creation.');
        } catch (ApiException) {
            $this->assertSame(
                $generatedCount,
                User::query()
                    ->where('email', 'like', '%@' . TelegramResellerService::GENERATED_EMAIL_DOMAIN)
                    ->count(),
            );
        }

        $tokenBeforeReset = (string) $customer->token;
        $resetNonce = '8877665544332211';
        $firstUrl = $resellerService->resetSubscription($actor, (int) $customer->id, $resetNonce);
        $tokenAfterReset = (string) $customer->fresh()->token;
        $this->assertNotSame($tokenBeforeReset, $tokenAfterReset);
        $this->assertStringContainsString($tokenAfterReset, (string) $firstUrl);

        Cache::flush();
        try {
            $resellerService->resetSubscription($actor, (int) $customer->id, $resetNonce);
            $this->fail('A durable Telegram receipt allowed a replayed subscription reset.');
        } catch (ApiException) {
            $this->assertSame($tokenAfterReset, (string) $customer->fresh()->token);
        }
    }

    public function test_regular_coupon_and_vip_discount_keep_additive_xboard_semantics(): void
    {
        $order = new Order([
            'total_amount' => 1000,
            'discount_amount' => 500,
        ]);
        $customer = new User(['discount' => 20]);

        (new OrderService($order))->setVipDiscount($customer);

        $this->assertSame(700, (int) $order->discount_amount);
        $this->assertSame(300, (int) $order->total_amount);
    }

    public function test_telegram_id_unique_migration_fails_closed_on_legacy_duplicates(): void
    {
        $first = $this->makeUser('telegram-duplicate-one@example.test');
        $second = $this->makeUser('telegram-duplicate-two@example.test');

        Schema::table('v2_user', function (Blueprint $table): void {
            $table->dropUnique('v2_user_telegram_id_unique');
        });
        DB::table('v2_user')->whereIn('id', [$first->id, $second->id])->update([
            'telegram_id' => '4503599627370888',
        ]);

        $migration = require database_path(
            'migrations/2026_09_01_000001_add_unique_telegram_id_to_users.php'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate Telegram account binding exists');
        $migration->up();
    }

    public function test_admin_reply_rejects_a_closed_support_ticket_without_inserting_message(): void
    {
        $customer = $this->makeUser('closed-support-customer@example.test', [
            'is_reseller' => true,
        ]);
        $admin = $this->makeUser('closed-support-admin@example.test', [
            'is_admin' => true,
        ]);
        $ticket = Ticket::query()->create([
            'user_id' => $customer->id,
            'subject' => '[Telegram reseller support]',
            'level' => 0,
            'status' => Ticket::STATUS_CLOSED,
            'reply_status' => Ticket::REPLY_STATUS_WAITING,
            'last_reply_user_id' => $customer->id,
        ]);

        try {
            (new TicketService())->replyByAdmin(
                (int) $ticket->id,
                'This reply must not be saved.',
                (int) $admin->id,
                '[Telegram reseller support]',
            );
            $this->fail('A closed support ticket accepted an administrator reply.');
        } catch (ApiException $e) {
            $this->assertNotSame('', trim($e->getMessage()));
        }

        $this->assertSame(0, $ticket->messages()->count());
    }

    public function test_user_reply_rejects_a_closed_support_ticket_without_inserting_message(): void
    {
        $customer = $this->makeUser('closed-user-support@example.test');
        $ticket = Ticket::query()->create([
            'user_id' => $customer->id,
            'subject' => 'Closed user ticket',
            'level' => 0,
            'status' => Ticket::STATUS_CLOSED,
            'reply_status' => Ticket::REPLY_STATUS_WAITING,
            'last_reply_user_id' => $customer->id,
        ]);

        $result = (new TicketService())->reply(
            $ticket,
            'This user reply must not be saved.',
            (int) $customer->id,
        );

        $this->assertFalse($result);
        $this->assertSame(0, $ticket->messages()->count());
    }

    public function test_send_message_does_not_retry_an_ambiguous_http_failure(): void
    {
        admin_setting(['telegram_bot_token' => '123456789:no-retry-test-token']);
        Http::fakeSequence()
            ->push(['ok' => false], 500)
            ->push(['ok' => true, 'result' => ['message_id' => 2]], 200);

        try {
            (new TelegramService())->sendMessage('4503599627370999', 'send only once');
            $this->fail('The failed Telegram send unexpectedly succeeded after a retry.');
        } catch (ApiException) {
            $this->assertTrue(true);
        }

        Http::assertSentCount(1);
        Http::assertSent(static fn ($request): bool => $request->method() === 'POST');
    }

    private function makePlan(): Plan
    {
        return Plan::query()->create([
            'group_id' => null,
            'transfer_enable' => 50,
            'name' => 'Telegram reseller invariant plan',
            'speed_limit' => null,
            'show' => true,
            'sort' => 0,
            'renew' => true,
            'prices' => [Plan::PERIOD_MONTHLY => 15],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'capacity_limit' => null,
            'sell' => true,
            'device_limit' => 2,
        ]);
    }

    private function makeUser(string $email, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'email' => $email,
            'password' => password_hash('telegram-invariant-password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => false,
            'is_admin' => false,
            'is_staff' => false,
            'is_reseller' => false,
            'expired_at' => 0,
            'remind_expire' => true,
            'remind_traffic' => true,
        ], $overrides));
    }
}
