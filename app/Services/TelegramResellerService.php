<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Utils\Helper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TelegramResellerService
{
    public const GENERATED_EMAIL_DOMAIN = 'bot.zaoguang.invalid';
    private const OPERATION_RECEIPTS_TABLE = 'telegram_webhook_update_receipts';
    private const OPERATION_RECEIPT_DAYS = 30;

    public function canManage(User $actor): bool
    {
        // Staff access is deliberately not implicit: a staff member who needs
        // these commercial powers must be explicitly marked as a reseller.
        return (bool) ($actor->is_reseller || $actor->is_admin);
    }

    /** @return Collection<int, Plan> */
    public function availablePlans(): Collection
    {
        return Plan::query()
            ->where('show', true)
            ->where('sell', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->filter(fn (Plan $plan): bool => $this->availablePeriods($plan) !== [])
            ->values();
    }

    /** @return list<string> */
    public function availablePeriods(Plan $plan): array
    {
        $periods = [];
        foreach (($plan->prices ?? []) as $period => $price) {
            $period = (string) $period;
            if ($period === Plan::PERIOD_RESET_TRAFFIC
                || !Plan::isValidPeriod($period)
                || !is_numeric($price)
                || (float) $price <= 0) {
                continue;
            }
            $periods[] = $period;
        }
        return $periods;
    }

    public function ownedCustomers(User $actor, int $page = 1, int $perPage = 6): LengthAwarePaginator
    {
        $this->assertReseller($actor);

        return User::query()
            ->where('invite_user_id', $actor->id)
            ->where('is_admin', false)
            ->where('is_staff', false)
            ->where('is_reseller', false)
            ->with('plan:id,name')
            ->orderByDesc('id')
            ->paginate(
                perPage: max(1, min(10, $perPage)),
                columns: ['id', 'email', 'plan_id', 'banned', 'expired_at', 'created_at'],
                pageName: 'page',
                page: max(1, $page),
            );
    }

    public function ownedCustomer(User $actor, int $customerId, bool $lock = false): ?User
    {
        $this->assertReseller($actor);

        $query = User::query()
            ->whereKey($customerId)
            ->where('invite_user_id', $actor->id)
            ->where('is_admin', false)
            ->where('is_staff', false)
            ->where('is_reseller', false);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Create a pseudonymous account only after the coupon, plan and period
     * have passed validation, then activate its zero-total order atomically.
     * The generated password is intentionally never returned or logged because
     * bot-created customers consume only the subscription URL.
     *
     * @return array{user: User, order: Order, plan: Plan, reference: string, subscribe_url: string}
     */
    public function createCustomer(
        User $actor,
        int $planId,
        string $period,
        string $couponCode,
        string $operationNonce,
        string $locale = 'en-US',
    ): array {
        $result = DB::transaction(function () use ($actor, $planId, $period, $couponCode, $operationNonce, $locale): array {
            $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            $this->assertReseller($lockedActor);
            $this->claimOperation('create', (int) $lockedActor->id, $operationNonce);

            $plan = $this->sellablePlan($planId, $period);
            $couponId = $this->validateFullDiscountCoupon($couponCode, $plan, $period, null);

            $email = $this->generateCustomerEmail();
            $password = bin2hex(random_bytes(24)) . 'Aa1!';
            $user = app(UserService::class)->createUser([
                'email' => $email,
                'password' => $password,
                // Suppress automatic trial-plan assignment. The paid order is
                // the only operation allowed to activate this new customer.
                'plan_id' => 0,
                'invite_user_id' => (int) $lockedActor->id,
            ]);
            $user->locale = $locale;
            $user->saveOrFail();

            // A 100% reseller coupon is an entitlement, not a cash-out path.
            // Never convert the customer's old plan into balance credit for a
            // bot-created purchase, even when the global surplus feature is on.
            $order = OrderService::createFromRequest(
                $user,
                $plan,
                $period,
                $couponCode,
                allowSurplus: false,
            );
            $this->assertZeroTotalCouponOrder($order, $couponId);

            if (!(new OrderService($order))->paid($this->paymentReference((int) $lockedActor->id))) {
                throw new ApiException(__('Failed to activate the customer subscription.'));
            }

            $order->refresh();
            $user->refresh();
            if ((int) $order->status !== Order::STATUS_COMPLETED) {
                throw new ApiException(__('Failed to activate the customer subscription.'));
            }

            return [
                'user' => $user,
                'order' => $order,
                'plan' => $plan,
                'reference' => $this->customerReference($user),
                'subscribe_url' => Helper::getSubscribeUrl($user->token),
            ];
        }, 3);

        $this->audit('customer_created', $actor, $result['user'], $result['order']);

        return $result;
    }

    /**
     * Renew the current plan or change it while retaining strict ownership.
     *
     * @return array{user: User, order: Order, plan: Plan, reference: string, subscribe_url: string}
     */
    public function purchaseForCustomer(
        User $actor,
        int $customerId,
        int $planId,
        string $period,
        string $couponCode,
        string $operationNonce,
    ): array {
        $result = DB::transaction(function () use ($actor, $customerId, $planId, $period, $couponCode, $operationNonce): array {
            $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            $this->assertReseller($lockedActor);
            $this->claimOperation('purchase', (int) $lockedActor->id, $operationNonce);
            $customer = $this->ownedCustomer($lockedActor, $customerId, true);
            if (!$customer) {
                throw new ApiException(__('The customer does not exist or is not owned by this reseller.'));
            }

            $plan = $this->sellablePlan($planId, $period);
            $couponId = $this->validateFullDiscountCoupon($couponCode, $plan, $period, $customer);
            $order = OrderService::createFromRequest(
                $customer,
                $plan,
                $period,
                $couponCode,
                allowSurplus: false,
            );
            $this->assertZeroTotalCouponOrder($order, $couponId);

            if (!(new OrderService($order))->paid($this->paymentReference((int) $lockedActor->id))) {
                throw new ApiException(__('Failed to activate the customer subscription.'));
            }

            $order->refresh();
            $customer->refresh();
            if ((int) $order->status !== Order::STATUS_COMPLETED) {
                throw new ApiException(__('Failed to activate the customer subscription.'));
            }

            return [
                'user' => $customer,
                'order' => $order,
                'plan' => $plan,
                'reference' => $this->customerReference($customer),
                'subscribe_url' => Helper::getSubscribeUrl($customer->token),
            ];
        }, 3);

        $this->audit('customer_plan_purchased', $actor, $result['user'], $result['order']);

        return $result;
    }

    public function subscriptionUrl(User $actor, int $customerId): ?string
    {
        $customer = $this->ownedCustomer($actor, $customerId);
        if (!$customer) {
            return null;
        }

        $this->audit('subscription_viewed', $actor, $customer);

        return Helper::getSubscribeUrl($customer->token);
    }

    public function resetSubscription(User $actor, int $customerId, string $operationNonce): ?string
    {
        $customer = DB::transaction(function () use ($actor, $customerId, $operationNonce): ?User {
            $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            $this->assertReseller($lockedActor);
            $this->claimOperation('reset', (int) $lockedActor->id, $operationNonce);
            $customer = $this->ownedCustomer($lockedActor, $customerId, true);
            if (!$customer) {
                return null;
            }

            // Match V1 UserController::resetSecurity and the admin reset path:
            // both authentication identifiers rotate together.
            $customer->uuid = Helper::guid(true);
            $customer->token = Helper::guid();
            $customer->saveOrFail();

            return $customer;
        }, 3);

        if (!$customer) {
            return null;
        }

        $url = Helper::getSubscribeUrl($customer->token);
        try {
            HookManager::call('user.subscribe.reset.after', [$customer, $url]);
        } catch (\Throwable $e) {
            // Credential rotation has committed and must not be repeated just
            // because a notification listener failed. Record only safe types
            // and internal ids; never the token, URL or exception message.
            Log::error('Telegram reseller reset hook failed', [
                'action' => 'subscription_reset_hook',
                'operator_user_id' => (int) $actor->id,
                'customer_user_id' => (int) $customer->id,
                'error_type' => $e::class,
            ]);
        }
        $this->audit('subscription_reset', $actor, $customer);

        return $url;
    }

    /** @return array<string, bool|int|string|null> */
    public function customerInfo(User $actor, int $customerId): ?array
    {
        $customer = $this->ownedCustomer($actor, $customerId);
        if (!$customer) {
            return null;
        }
        $customer->loadMissing('plan:id,name');

        $trafficUsed = (int) (($customer->u ?? 0) + ($customer->d ?? 0));
        $trafficTotal = (int) ($customer->transfer_enable ?? 0);

        return [
            'id' => (int) $customer->id,
            'reference' => $this->customerReference($customer),
            'plan_id' => $customer->plan_id === null ? null : (int) $customer->plan_id,
            'plan_name' => $customer->plan?->name,
            'active' => (bool) $customer->isActive(),
            'banned' => (bool) $customer->banned,
            'expired_at' => $customer->expired_at === null ? null : (int) $customer->expired_at,
            'traffic_used' => $trafficUsed,
            'traffic_total' => $trafficTotal,
            'traffic_remaining' => max(0, $trafficTotal - $trafficUsed),
            'device_limit' => $customer->device_limit === null ? null : (int) $customer->device_limit,
            // User casts timestamps to integers rather than Carbon instances.
            'created_at' => $customer->created_at === null ? null : (int) $customer->created_at,
        ];
    }

    public function customerReference(User $customer): string
    {
        $email = strtolower((string) $customer->email);
        if (preg_match('/^(zg-[a-f0-9]{20})@' . preg_quote(self::GENERATED_EMAIL_DOMAIN, '/') . '$/', $email, $matches)) {
            return $matches[1];
        }

        // Never expose a manually registered customer's personal email in a
        // Telegram list. The stable database id is sufficient for management.
        return 'customer-' . (int) $customer->id;
    }

    private function assertReseller(?User $actor): void
    {
        if (!$actor || !$this->canManage($actor)) {
            throw new ApiException(__('You are not allowed to use reseller tools.'));
        }
    }

    private function sellablePlan(int $planId, string $period): Plan
    {
        $plan = Plan::query()
            ->whereKey($planId)
            ->where('show', true)
            ->where('sell', true)
            ->lockForUpdate()
            ->first();
        if (!$plan || !in_array($period, $this->availablePeriods($plan), true)) {
            throw new ApiException(__('Subscription plan or billing period is not available.'));
        }

        return $plan;
    }

    private function validateFullDiscountCoupon(
        string $couponCode,
        Plan $plan,
        string $period,
        ?User $customer,
    ): int {
        $couponService = new CouponService(trim($couponCode));
        $coupon = $couponService->getCoupon();
        if (!$coupon || (int) $coupon->type !== 2 || (float) $coupon->value !== 100.0) {
            throw new ApiException(__('A valid 100% percentage coupon is required.'));
        }

        $couponService->setPlanId((int) $plan->id);
        $couponService->setPeriod($period);
        if ($customer) {
            $couponService->setUserId((int) $customer->id);
        }
        $couponService->check();
        return (int) $coupon->id;
    }

    private function assertZeroTotalCouponOrder(Order $order, int $couponId): void
    {
        if ((int) $order->coupon_id !== $couponId
            || (int) $order->total_amount !== 0
            || (float) $order->discount_amount <= 0
            || (int) ($order->surplus_amount ?? 0) !== 0
            || (int) ($order->surplus_credit ?? 0) !== 0
            || !empty($order->surplus_order_ids)) {
            throw new ApiException(__('The coupon did not fully discount this order.'));
        }
    }

    private function generateCustomerEmail(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            // 80 random bits, no personal data, valid for XBoard's email field.
            $email = 'zg-' . bin2hex(random_bytes(10)) . '@' . self::GENERATED_EMAIL_DOMAIN;
            if (!User::byEmail($email)->exists()) {
                return $email;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique customer reference.');
    }

    private function paymentReference(int $actorId): string
    {
        return 'telegram-reseller-' . $actorId . '-' . bin2hex(random_bytes(8));
    }

    /**
     * Claim a destructive bot action in the same database transaction as its
     * business mutation. Redis locks remain useful for UX/concurrency, while
     * this receipt prevents replay after a cache restart or stale restore.
     */
    private function claimOperation(string $action, int $actorId, string $nonce): void
    {
        if (!in_array($action, ['create', 'purchase', 'reset'], true)
            || preg_match('/^[a-f0-9]{16}$/', $nonce) !== 1) {
            throw new ApiException(__('Invalid parameter'));
        }

        $now = now();
        DB::table(self::OPERATION_RECEIPTS_TABLE)
            ->where('expires_at', '<=', $now)
            ->delete();

        $receiptHash = hash('sha256', "telegram-reseller\0{$action}\0{$actorId}\0{$nonce}");
        $claimed = DB::table(self::OPERATION_RECEIPTS_TABLE)->insertOrIgnore([
            'receipt_hash' => $receiptHash,
            'created_at' => $now,
            'expires_at' => $now->copy()->addDays(self::OPERATION_RECEIPT_DAYS),
        ]);
        if ($claimed !== 1) {
            throw new ApiException(__('Invalid parameter'));
        }
    }

    private function audit(string $action, User $actor, User $customer, ?Order $order = null): void
    {
        try {
            Log::notice('Telegram reseller action', array_filter([
                'action' => $action,
                'operator_user_id' => (int) $actor->id,
                'customer_user_id' => (int) $customer->id,
                'order_id' => $order ? (int) $order->id : null,
            ], static fn ($value) => $value !== null));
        } catch (\Throwable) {
            // Auditing is intentionally non-fatal after a committed purchase
            // or credential rotation; a logger outage must never invite a
            // Telegram retry of the business mutation.
        }
    }
}
