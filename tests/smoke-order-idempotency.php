<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Utils\Helper;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function smokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function smokeUser(string $prefix, array $overrides = []): User
{
    return User::create(array_merge([
        'email' => $prefix . '-' . bin2hex(random_bytes(4)) . '@example.invalid',
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
    ], $overrides));
}

function smokePlan(string $name): Plan
{
    return Plan::create([
        'group_id' => null,
        'transfer_enable' => 5,
        'name' => $name,
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
}

DB::beginTransaction();
try {
    $plan = smokePlan('Idempotency smoke plan');
    $user = smokeUser('cancel');
    $order = Order::create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'type' => Order::TYPE_NEW_PURCHASE,
        'period' => Plan::PERIOD_MONTHLY,
        'trade_no' => 'smoke_cancel_' . bin2hex(random_bytes(6)),
        'total_amount' => 100,
        'balance_amount' => 100,
        'status' => Order::STATUS_PENDING,
    ]);
    $firstStaleOrder = Order::findOrFail($order->id);
    $secondStaleOrder = Order::findOrFail($order->id);
    smokeAssert((new OrderService($firstStaleOrder))->cancel(), 'First cancellation failed');
    smokeAssert(!(new OrderService($secondStaleOrder))->cancel(), 'Second cancellation was not rejected');
    smokeAssert((int) User::findOrFail($user->id)->balance === 100, 'Balance was refunded more than once');

    $openUser = smokeUser('open', ['transfer_enable' => 0]);
    $openOrder = Order::create([
        'user_id' => $openUser->id,
        'plan_id' => $plan->id,
        'type' => Order::TYPE_NEW_PURCHASE,
        'period' => Plan::PERIOD_MONTHLY,
        'trade_no' => 'smoke_open_' . bin2hex(random_bytes(6)),
        'total_amount' => 0,
        'balance_amount' => 0,
        'status' => Order::STATUS_PROCESSING,
    ]);
    (new OrderService(Order::findOrFail($openOrder->id)))->open();
    (new OrderService(Order::findOrFail($openOrder->id)))->open();
    smokeAssert((int) Order::findOrFail($openOrder->id)->status === Order::STATUS_COMPLETED, 'Order did not complete');
    smokeAssert((int) User::findOrFail($openUser->id)->reset_count === 1, 'Subscription was applied more than once');

    admin_setting(['surplus_enable' => 0]);
    $oldPlan = smokePlan('Old plan');
    $newPlan = smokePlan('New plan');
    $upgradeUser = smokeUser('surplus', ['plan_id' => $oldPlan->id, 'expired_at' => time() + 86400]);
    $upgrade = new Order([
        'user_id' => $upgradeUser->id,
        'plan_id' => $newPlan->id,
        'period' => Plan::PERIOD_MONTHLY,
        'total_amount' => 50,
    ]);
    (new OrderService($upgrade))->setOrderType($upgradeUser);
    smokeAssert((int) $upgrade->type === Order::TYPE_UPGRADE, 'Plan change was not classified as an upgrade');
    smokeAssert((int) $upgrade->total_amount === 50, 'Disabled surplus changed the order total');
    smokeAssert(empty($upgrade->surplus_amount) && empty($upgrade->surplus_credit), 'Disabled surplus generated credit');

    echo "Order cancellation/open idempotency and disabled-surplus checks passed.\n";
} finally {
    DB::rollBack();
}
