<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Models\UsdtDirectInvoice;
use App\Models\UsdtDirectTransfer;
use App\Services\Plugin\HookManager;
use App\Utils\Helper;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use App\Services\PlanService;

class OrderService
{
    private const CHECKOUT_TABLE = 'v2_order_payment_checkout';
    private const CHECKOUT_CREATING = 'creating';
    private const CHECKOUT_READY = 'ready';
    private const CHECKOUT_FAILED = 'failed';
    private const CHECKOUT_UNCERTAIN = 'uncertain';
    private const CHECKOUT_CLOSED = 'closed';
    private const CHECKOUT_CLAIM_TTL = 90;

    const STR_TO_TIME = [
        Plan::PERIOD_MONTHLY => 1,
        Plan::PERIOD_QUARTERLY => 3,
        Plan::PERIOD_HALF_YEARLY => 6,
        Plan::PERIOD_YEARLY => 12,
        Plan::PERIOD_TWO_YEARLY => 24,
        Plan::PERIOD_THREE_YEARLY => 36
    ];
    public $order;
    public $user;
    private bool $paymentTransitioned = false;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    private function lockCurrentOrder(): ?Order
    {
        return Order::whereKey($this->order->id)
            ->lockForUpdate()
            ->first();
    }

    private function lockCurrentOrderWhenStatus(int $status): ?Order
    {
        return Order::whereKey($this->order->id)
            ->where('status', $status)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Create an order from a request.
     *
     * @param User $user
     * @param Plan $plan
     * @param string $period
     * @param string|null $couponCode
     * @return Order
     * @throws ApiException
     */
    public static function createFromRequest(
        User $user,
        Plan $plan,
        string $period,
        ?string $couponCode = null,
        bool $allowSurplus = true,
    ): Order {
        $userService = app(UserService::class);
        $planService = new PlanService($plan);

        $planService->validatePurchase($user, $period);
        HookManager::call('order.create.before', [$user, $plan, $period, $couponCode]);

        return DB::transaction(function () use ($user, $plan, $period, $couponCode, $allowSurplus, $userService) {
            $user = User::lockForUpdate()->find($user->id);
            if (!$user) {
                throw new ApiException(__('The user does not exist'));
            }

            if ($userService->isNotCompleteOrderByUserId($user->id)) {
                throw new ApiException(__('You have an unpaid or pending order, please try again later or cancel it'));
            }

            $newPeriod = PlanService::getPeriodKey($period);

            $order = new Order([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'period' => $newPeriod,
                'trade_no' => Helper::generateOrderNo(),
                'total_amount' => (int) (optional($plan->prices)[$newPeriod] * 100),
            ]);

            $orderService = new self($order);

            if ($couponCode) {
                $orderService->applyCoupon($couponCode);
            }

            $orderService->setVipDiscount($user);
            $orderService->setOrderType($user, $allowSurplus);

            if ($user->balance && $order->total_amount > 0) {
                $orderService->handleUserBalance($user, $userService);
            }

            $orderService->setInvite(user: $user);

            if (!$order->save()) {
                throw new ApiException(__('Failed to create order'));
            }

            HookManager::call('order.create.after', $order);
            // 兼容旧钩子
            HookManager::call('order.after_create', $order);

            return $order;
        });
    }

    public function open(): void
    {
        $openedOrder = DB::transaction(function () {
            $order = $this->lockCurrentOrderWhenStatus(Order::STATUS_PROCESSING);
            if (!$order) {
                return null;
            }

            $plan = Plan::find($order->plan_id);
            if (!$plan) {
                throw new \RuntimeException(__('Subscription plan does not exist'));
            }

            HookManager::call('order.open.before', $order);

            $this->user = User::lockForUpdate()->find($order->user_id);

            if ($order->surplus_credit) {
                $this->user->balance += $order->surplus_credit;
            }

            if ($order->surplus_order_ids) {
                Order::whereIn('id', $order->surplus_order_ids)
                    ->update(['status' => Order::STATUS_DISCOUNTED]);
            }

            match ((string) $order->period) {
                Plan::PERIOD_ONETIME => $this->buyByOneTime($plan),
                Plan::PERIOD_RESET_TRAFFIC => app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER),
                default => $this->buyByPeriod($order, $plan),
            };

            $this->setSpeedLimit($plan->speed_limit);
            $this->setDeviceLimit($plan->device_limit);

            if (!$this->user->save()) {
                throw new \RuntimeException(__('Failed to save user information'));
            }

            $order->status = Order::STATUS_COMPLETED;
            if (!$order->save()) {
                throw new \RuntimeException(__('Failed to save order information'));
            }

            return $order;
        });

        if (!$openedOrder) {
            return;
        }

        $order = $openedOrder;
        $this->order = $order;

        $eventId = match ((int) $order->type) {
            Order::TYPE_NEW_PURCHASE => admin_setting('new_order_event_id', 0),
            Order::TYPE_RENEWAL => admin_setting('renew_order_event_id', 0),
            Order::TYPE_UPGRADE => admin_setting('change_order_event_id', 0),
            default => 0,
        };

        if ($eventId) {
            $this->openEvent($eventId);
        }

        HookManager::call('order.open.after', $order);
    }


    public function setOrderType(User $user, bool $allowSurplus = true)
    {
        $order = $this->order;
        if ($order->period === Plan::PERIOD_RESET_TRAFFIC) {
            $order->type = Order::TYPE_RESET_TRAFFIC;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id && ($user->expired_at > time() || $user->expired_at === NULL)) {
            if (!(int) admin_setting('plan_change_enable', 1))
                throw new ApiException(__('Changing subscription plans is currently disabled. Please contact support.'));
            $order->type = Order::TYPE_UPGRADE;
            if ($allowSurplus && (int) admin_setting('surplus_enable', 0)) {
                $this->getSurplusValue($user, $order);
                if ($order->surplus_amount >= $order->total_amount) {
                    $order->surplus_credit = (int) ($order->surplus_amount - $order->total_amount);
                    $order->total_amount = 0;
                } else {
                    $order->total_amount = (int) ($order->total_amount - $order->surplus_amount);
                }
            }
        } else if (($user->expired_at === null || $user->expired_at > time()) && $order->plan_id == $user->plan_id) { // 用户订阅未过期或按流量订阅 且购买订阅与当前订阅相同 === 续费
            $order->type = Order::TYPE_RENEWAL;
        } else { // 新购
            $order->type = Order::TYPE_NEW_PURCHASE;
        }
    }

    public function setVipDiscount(User $user)
    {
        $order = $this->order;
        $baseAmount = max(0, (int) $order->total_amount);
        $couponDiscount = max(0, min($baseAmount, (int) ($order->discount_amount ?? 0)));

        // Preserve XBoard's additive coupon + VIP semantics, but clamp their
        // combined value to the original price. A 100% reseller coupon plus a
        // VIP percentage must never make the order negative or create credit.
        $vipPercent = max(0, min(100, (int) ($user->discount ?? 0)));
        $vipDiscount = intdiv($baseAmount * $vipPercent, 100);
        $combinedDiscount = min($baseAmount, $couponDiscount + $vipDiscount);

        $order->discount_amount = $combinedDiscount;
        $order->total_amount = max(0, $baseAmount - $combinedDiscount);
    }

    public function setInvite(User $user): void
    {
        $order = $this->order;
        if ($user->invite_user_id && ($order->total_amount <= 0))
            return;
        $order->invite_user_id = $user->invite_user_id;
        $inviter = User::find($user->invite_user_id);
        if (!$inviter)
            return;
        $commissionType = (int) $inviter->commission_type;
        if ($commissionType === User::COMMISSION_TYPE_SYSTEM) {
            $commissionType = (bool) admin_setting('commission_first_time_enable', true) ? User::COMMISSION_TYPE_ONETIME : User::COMMISSION_TYPE_PERIOD;
        }
        $isCommission = false;
        switch ($commissionType) {
            case User::COMMISSION_TYPE_PERIOD:
                $isCommission = true;
                break;
            case User::COMMISSION_TYPE_ONETIME:
                $isCommission = !$this->haveValidOrder($user);
                break;
        }

        if (!$isCommission)
            return;
        if ($inviter->commission_rate) {
            $order->commission_balance = $order->total_amount * ($inviter->commission_rate / 100);
        } else {
            $order->commission_balance = $order->total_amount * (admin_setting('invite_commission', 10) / 100);
        }
    }

    private function haveValidOrder(User $user): Order|null
    {
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED])
            ->first();
    }

    private function getSurplusValue(User $user, Order $order)
    {
        if ($user->expired_at === NULL) {
            $lastOneTimeOrder = Order::where('user_id', $user->id)
                ->where('period', Plan::PERIOD_ONETIME)
                ->where('status', Order::STATUS_COMPLETED)
                ->orderBy('id', 'DESC')
                ->first();
            if (!$lastOneTimeOrder)
                return;
            $nowUserTraffic = Helper::transferToGB($user->transfer_enable);
            if (!$nowUserTraffic)
                return;
            $paidTotalAmount = ($lastOneTimeOrder->total_amount + $lastOneTimeOrder->balance_amount);
            if (!$paidTotalAmount)
                return;
            $trafficUnitPrice = $paidTotalAmount / $nowUserTraffic;
            $notUsedTraffic = $nowUserTraffic - Helper::transferToGB($user->u + $user->d);
            $result = $trafficUnitPrice * $notUsedTraffic;
            $order->surplus_amount = (int) ($result > 0 ? $result : 0);
            $order->surplus_order_ids = Order::where('user_id', $user->id)
                ->where('period', '!=', Plan::PERIOD_RESET_TRAFFIC)
                ->where('status', Order::STATUS_COMPLETED)
                ->pluck('id')
                ->all();
        } else {
            $orders = Order::query()
                ->where('user_id', $user->id)
                ->whereNotIn('period', [Plan::PERIOD_RESET_TRAFFIC, Plan::PERIOD_ONETIME])
                ->where('status', Order::STATUS_COMPLETED)
                ->get();

            if ($orders->isEmpty()) {
                $order->surplus_amount = 0;
                $order->surplus_order_ids = [];
                return;
            }

            $orderAmountSum = $orders->sum(fn($item) => $item->total_amount + $item->balance_amount + $item->surplus_amount - $item->surplus_credit);
            $orderMonthSum = $orders->sum(fn($item) => self::STR_TO_TIME[PlanService::getPeriodKey($item->period)] ?? 0);
            $firstOrderAt = $orders->min('created_at');
            $expiredAt = Carbon::createFromTimestamp($firstOrderAt)->addMonths($orderMonthSum);

            $now = now();
            $totalSeconds = $expiredAt->timestamp - $firstOrderAt;
            $remainSeconds = max(0, $expiredAt->timestamp - $now->timestamp);
            $cycleRatio = $totalSeconds > 0 ? $remainSeconds / $totalSeconds : 0;

            $plan = Plan::find($user->plan_id);
            $totalTraffic = $plan?->transfer_enable * $orderMonthSum;
            $usedTraffic = Helper::transferToGB($user->u + $user->d);
            $remainTraffic = max(0, $totalTraffic - $usedTraffic);
            $trafficRatio = $totalTraffic > 0 ? $remainTraffic / $totalTraffic : 0;

            $ratio = $cycleRatio;
            if (admin_setting('change_order_event_id', 0) == 1) {
                $ratio = min($cycleRatio, $trafficRatio);
            }


            $order->surplus_amount = (int) max(0, $orderAmountSum * $ratio);
            $order->surplus_order_ids = $orders->pluck('id')->all();
        }
    }

    public function paid(string $callbackNo): bool
    {
        $this->paymentTransitioned = false;
        try {
            [$order, $shouldDispatch] = DB::transaction(function () use ($callbackNo) {
                $order = $this->lockCurrentOrder();
                if (!$order) {
                    throw new \RuntimeException('Order not found.');
                }
                if ((int) $order->status !== Order::STATUS_PENDING) {
                    return [$order, false];
                }
                // Self-custody transfers must cross the chain-bound settlement
                // boundary below. Letting a generic gateway callback call
                // paid() would bypass amount, destination and log-identity
                // checks while a USDT invoice is still payable/reconcilable.
                if (Schema::hasTable('v2_usdt_direct_invoice')
                    && UsdtDirectInvoice::query()
                        ->where('order_id', $order->id)
                        ->whereIn('state', [
                            UsdtDirectInvoice::STATE_AWAITING,
                            UsdtDirectInvoice::STATE_SEEN,
                            UsdtDirectInvoice::STATE_MANUAL_REVIEW,
                        ])
                        ->lockForUpdate()
                        ->exists()) {
                    throw new ApiException(__('USDT payment verification is still in progress.'));
                }

                $order->status = Order::STATUS_PROCESSING;
                $order->paid_at = time();
                $order->callback_no = $callbackNo;
                if (!$order->save()) {
                    throw new \RuntimeException('Failed to save order status.');
                }
                self::closePaymentCheckouts($order->id);

                return [$order, true];
            });

            $this->order = $order;
            $this->paymentTransitioned = $shouldDispatch;

            if ($shouldDispatch) {
                OrderHandleJob::dispatchSync($order->trade_no);
            }
        } catch (\Exception $e) {
            Log::error($e);
            return false;
        }
        return true;
    }

    private static function paymentCheckoutLockKey(int $userId, string $tradeNo): string
    {
        // Keep the original namespace so old and new workers still serialize
        // correctly during a rolling deployment.
        return 'coinpayments-checkout:' . hash('sha256', $userId . '|' . $tradeNo);
    }

    private static function isCoinPaymentsCheckoutUrl(mixed $url): bool
    {
        if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && in_array(strtolower((string) ($parts['host'] ?? '')), [
                'checkout.coinpayments.net',
                'a-checkout.coinpayments.net',
                'b-checkout.coinpayments.net',
                'c-checkout.coinpayments.net',
            ], true)
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && (!isset($parts['port']) || (int) $parts['port'] === 443);
    }

    /** @return list<string> */
    private static function activeCoinPaymentsStates(): array
    {
        return [self::CHECKOUT_CREATING, self::CHECKOUT_READY, self::CHECKOUT_UNCERTAIN];
    }

    public static function hasActiveCoinPaymentsCheckout(?int $paymentId = null): bool
    {
        if (!Schema::hasTable(self::CHECKOUT_TABLE)) {
            return false;
        }

        $query = DB::table(self::CHECKOUT_TABLE)
            ->where('provider', 'CoinPayments')
            ->whereIn('state', self::activeCoinPaymentsStates());
        if ($paymentId !== null) {
            $query->where('payment_id', $paymentId);
        }

        return $query->exists();
    }

    public static function hasActiveCoinPaymentsCheckoutForPayment(int $paymentId): bool
    {
        return self::hasActiveCoinPaymentsCheckout($paymentId);
    }

    /** @return list<string> */
    private static function monitoredUsdtDirectInvoiceStates(): array
    {
        // Expired invoices remain monitored: a transfer mined after the
        // checkout deadline must still be attached for manual reconciliation.
        return [
            UsdtDirectInvoice::STATE_AWAITING,
            UsdtDirectInvoice::STATE_SEEN,
            UsdtDirectInvoice::STATE_EXPIRED,
            UsdtDirectInvoice::STATE_MANUAL_REVIEW,
        ];
    }

    public static function hasMonitoredUsdtDirectInvoice(?int $paymentId = null): bool
    {
        if (!Schema::hasTable('v2_usdt_direct_invoice')) {
            return false;
        }

        $query = UsdtDirectInvoice::query()
            ->whereIn('state', self::monitoredUsdtDirectInvoiceStates());
        if ($paymentId !== null) {
            $query->where('payment_id', $paymentId);
        }

        return $query->exists();
    }

    public static function hasUsdtDirectInvoiceForPayment(int $paymentId): bool
    {
        return Schema::hasTable('v2_usdt_direct_invoice')
            && UsdtDirectInvoice::query()->where('payment_id', $paymentId)->exists();
    }

    /**
     * Claim (or reuse) one CoinPayments invoice for an order/payment pair.
     *
     * The provider request deliberately happens after this transaction. The
     * durable `creating` claim prevents another worker/reload from issuing the
     * same non-idempotent POST. A stale claim is made uncertain, never retried.
     *
     * @return array{cached: bool, order: Order, amount: int, claim_token?: string, configuration_snapshot?: array, type?: int, data?: string}
     */
    public static function beginCoinPaymentsCheckout(
        int $userId,
        string $tradeNo,
        Payment $payment
    ): array {
        // SQLite ignores SELECT ... FOR UPDATE. The configured cache lock is
        // shared by all PHP workers using the same SQLite deployment, while
        // the unique database key remains the final cross-process backstop.
        $lock = Cache::lock(self::paymentCheckoutLockKey($userId, $tradeNo), 10);
        if (!$lock->get()) {
            throw new ApiException(__('Request failed, please try again later'));
        }

        try {
            $result = DB::transaction(function () use ($userId, $tradeNo, $payment): array {
            $order = Order::where('trade_no', $tradeNo)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();
            if (!$order || (int) $order->status !== Order::STATUS_PENDING) {
                throw new ApiException(__('Order does not exist or has been paid'));
            }
            if ((int) $order->total_amount <= 0) {
                throw new ApiException(__('Order amount is invalid'));
            }
            $freshPayment = Payment::whereKey($payment->id)->lockForUpdate()->first();
            if (!$freshPayment
                || !(bool) $freshPayment->enable
                || $freshPayment->payment !== 'CoinPayments') {
                throw new ApiException(__('Payment method is not available'));
            }
            // Repeat the validation under the payment-row lock. The fast
            // controller check avoids work for obvious bad rows; this closes
            // the race where an administrator disables or invalidates the
            // method between that check and the durable provider claim.
            $paymentService = new PaymentService($freshPayment->payment, $freshPayment->id);
            $paymentService->validateConfiguration();
            $configurationSnapshot = $paymentService->coinPaymentsConfigurationSnapshot();

            // A worker that vanished after the POST began is ambiguous. Mark
            // stale claims before selecting this payment's durable record.
            DB::table(self::CHECKOUT_TABLE)
                ->where('order_id', $order->id)
                ->where('state', self::CHECKOUT_CREATING)
                ->where('attempted_at', '<', time() - self::CHECKOUT_CLAIM_TTL)
                ->update([
                    'state' => self::CHECKOUT_UNCERTAIN,
                    'claim_token' => null,
                    'updated_at' => time(),
                ]);

            $checkout = DB::table(self::CHECKOUT_TABLE)
                ->where('order_id', $order->id)
                ->where('payment_id', $freshPayment->id)
                ->lockForUpdate()
                ->first();

            if ($checkout && $checkout->state === self::CHECKOUT_READY) {
                $data = json_decode((string) $checkout->response_data, true);
                $validData = self::isCoinPaymentsCheckoutUrl($data);
                $validType = (int) $checkout->response_type === 1;
                $sameOrderAmount = (int) $checkout->base_amount === (int) $order->total_amount;
                $sameProvider = hash_equals((string) $checkout->provider, (string) $freshPayment->payment);
                $validProviderInvoice = is_string($checkout->provider_invoice_id)
                    && trim($checkout->provider_invoice_id) !== '';
                $validProviderExpiry = is_numeric($checkout->provider_expires_at)
                    && (int) $checkout->provider_expires_at > 0;
                $anotherActiveCheckout = DB::table(self::CHECKOUT_TABLE)
                    ->where('order_id', $order->id)
                    ->where('id', '!=', $checkout->id)
                    ->whereIn('state', self::activeCoinPaymentsStates())
                    ->exists();
                if (!$validData
                    || !$validType
                    || !$sameOrderAmount
                    || !$sameProvider
                    || !$validProviderInvoice
                    || !$validProviderExpiry
                    || $anotherActiveCheckout) {
                    DB::table(self::CHECKOUT_TABLE)->where('id', $checkout->id)->update([
                        'state' => self::CHECKOUT_UNCERTAIN,
                        'claim_token' => null,
                        'updated_at' => time(),
                    ]);
                    return ['blocked' => true];
                }

                // The local clock may hide an expired checkout URL, but it can
                // never prove that the provider invoice is terminal. Only an
                // authenticated CoinPayments terminal event (or explicit
                // administrator reconciliation) may release this claim.
                if ((int) $checkout->provider_expires_at <= time()) {
                    return ['blocked' => true];
                }

                $order->payment_id = $freshPayment->id;
                $order->handling_amount = $checkout->handling_amount;
                $order->save();

                return [
                    'cached' => true,
                    'order' => $order,
                    'amount' => (int) $checkout->base_amount + (int) ($checkout->handling_amount ?? 0),
                    'type' => (int) $checkout->response_type,
                    'data' => $data,
                ];
            }

            // READY is payable just like an in-flight or uncertain invoice.
            // A customer must never open another gateway (or another
            // CoinPayments method) while any one of those paths exists.
            $orderHasBlockingCheckout = DB::table(self::CHECKOUT_TABLE)
                ->where('order_id', $order->id)
                ->whereIn('state', self::activeCoinPaymentsStates())
                ->exists();
            if ($orderHasBlockingCheckout) {
                return ['blocked' => true];
            }

            if ($checkout && in_array($checkout->state, [self::CHECKOUT_UNCERTAIN, self::CHECKOUT_CLOSED], true)) {
                return ['blocked' => true];
            }

            $handlingAmount = null;
            if ($freshPayment->handling_fee_fixed || $freshPayment->handling_fee_percent) {
                $handlingAmount = (int) round(
                    ($order->total_amount * ($freshPayment->handling_fee_percent / 100))
                    + $freshPayment->handling_fee_fixed
                );
            }
            try {
                $expectedAmount = CoinPaymentsCheckoutSnapshot::expectedAmount(
                    (int) $order->total_amount,
                    $handlingAmount,
                    $configurationSnapshot['coinpayments_cny_invoice_rate'],
                    $configurationSnapshot['coinpayments_invoice_currency_id']
                );
            } catch (\UnexpectedValueException $exception) {
                throw new ApiException(__('CoinPayments invoice amount is too small for the configured invoice currency.'), 400);
            }
            $claimToken = bin2hex(random_bytes(16));
            $now = time();
            $values = [
                'provider' => (string) $freshPayment->payment,
                'payment_uuid' => (string) $configurationSnapshot['payment_uuid'],
                'config_snapshot' => CoinPaymentsCheckoutSnapshot::encrypt($configurationSnapshot),
                'provider_invoice_id' => null,
                'provider_expires_at' => null,
                'expected_amount' => $expectedAmount,
                'state' => self::CHECKOUT_CREATING,
                'claim_token' => $claimToken,
                'base_amount' => (int) $order->total_amount,
                'handling_amount' => $handlingAmount,
                'response_type' => null,
                'response_data' => null,
                'attempted_at' => $now,
                'updated_at' => $now,
            ];
            if ($checkout) {
                DB::table(self::CHECKOUT_TABLE)->where('id', $checkout->id)->update($values);
            } else {
                $inserted = DB::table(self::CHECKOUT_TABLE)->insertOrIgnore($values + [
                    'order_id' => $order->id,
                    'payment_id' => $freshPayment->id,
                    'created_at' => $now,
                ]);
                if ($inserted !== 1) {
                    // A database contender won despite the outer lock (for
                    // example, differently configured app replicas). Re-read
                    // on the next request; never leak a raw unique violation or
                    // issue the provider POST from this worker.
                    return ['blocked' => true];
                }
            }

            $order->payment_id = $freshPayment->id;
            $order->handling_amount = $handlingAmount;
            $order->save();

            return [
                'cached' => false,
                'order' => $order,
                'amount' => (int) $order->total_amount + (int) ($handlingAmount ?? 0),
                'claim_token' => $claimToken,
                'configuration_snapshot' => $configurationSnapshot,
            ];
            });
        } finally {
            $lock->release();
        }

        if (!empty($result['blocked'])) {
            // The outcome may already exist remotely. Retrying the POST here
            // would be less safe than requiring cancellation/reconciliation.
            throw new ApiException(__('Payment verification is still in progress. Do not retry or cancel this order. Please contact support if it does not update.'));
        }

        return $result;
    }

    /**
     * Claim (or reuse) one checkout for a non-CoinPayments gateway.
     *
     * Provider creation happens after this transaction. Persisting both the
     * in-flight claim and the successful response is what prevents a reload,
     * another standard gateway, or CoinPayments from creating a second
     * payable path for the same order.
     *
     * @return array{cached: bool, order: Order, payment: Payment, amount: int, claim_token?: string, type?: int, data?: string}
     */
    public static function beginStandardPaymentCheckout(
        int $userId,
        string $tradeNo,
        Payment $payment
    ): array {
        $lock = Cache::lock(self::paymentCheckoutLockKey($userId, $tradeNo), 10);
        if (!$lock->get()) {
            throw new ApiException(__('Request failed, please try again later'));
        }

        try {
            $result = DB::transaction(function () use ($userId, $tradeNo, $payment): array {
                $order = Order::where('trade_no', $tradeNo)
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();
                if (!$order || (int) $order->status !== Order::STATUS_PENDING) {
                    throw new ApiException(__('Order does not exist or has been paid'));
                }
                if ((int) $order->total_amount <= 0) {
                    throw new ApiException(__('Order amount is invalid'));
                }

                $freshPayment = Payment::whereKey($payment->id)->lockForUpdate()->first();
                if (!$freshPayment
                    || !(bool) $freshPayment->enable
                    || in_array((string) $freshPayment->payment, ['CoinPayments', 'UsdtDirect'], true)) {
                    throw new ApiException(__('Payment method is not available'));
                }

                DB::table(self::CHECKOUT_TABLE)
                    ->where('order_id', $order->id)
                    ->where('state', self::CHECKOUT_CREATING)
                    ->where('attempted_at', '<', time() - self::CHECKOUT_CLAIM_TTL)
                    ->update([
                        'state' => self::CHECKOUT_UNCERTAIN,
                        'claim_token' => null,
                        'updated_at' => time(),
                    ]);

                $checkout = DB::table(self::CHECKOUT_TABLE)
                    ->where('order_id', $order->id)
                    ->where('payment_id', $freshPayment->id)
                    ->lockForUpdate()
                    ->first();

                if ($checkout && $checkout->state === self::CHECKOUT_READY) {
                    $data = json_decode((string) $checkout->response_data, true);
                    $validType = in_array((int) $checkout->response_type, [0, 1], true);
                    $validData = is_string($data) && trim($data) !== '';
                    $sameOrderAmount = (int) $checkout->base_amount === (int) $order->total_amount;
                    $sameProvider = hash_equals((string) $checkout->provider, (string) $freshPayment->payment);
                    $anotherActiveCheckout = DB::table(self::CHECKOUT_TABLE)
                        ->where('order_id', $order->id)
                        ->where('id', '!=', $checkout->id)
                        ->whereIn('state', self::activeCoinPaymentsStates())
                        ->exists();
                    if (!$validType
                        || !$validData
                        || !$sameOrderAmount
                        || !$sameProvider
                        || $anotherActiveCheckout) {
                        DB::table(self::CHECKOUT_TABLE)->where('id', $checkout->id)->update([
                            'state' => self::CHECKOUT_UNCERTAIN,
                            'claim_token' => null,
                            'updated_at' => time(),
                        ]);
                        return ['blocked' => true];
                    }

                    $order->payment_id = $freshPayment->id;
                    $order->handling_amount = $checkout->handling_amount;
                    $order->saveOrFail();

                    return [
                        'cached' => true,
                        'order' => $order,
                        'payment' => $freshPayment,
                        'amount' => (int) $checkout->base_amount + (int) ($checkout->handling_amount ?? 0),
                        'type' => (int) $checkout->response_type,
                        'data' => $data,
                    ];
                }

                $hasBlockingCheckout = DB::table(self::CHECKOUT_TABLE)
                    ->where('order_id', $order->id)
                    ->whereIn('state', self::activeCoinPaymentsStates())
                    ->exists();
                if ($hasBlockingCheckout) {
                    // Return instead of throwing so a stale creating ->
                    // uncertain transition is committed for reconciliation.
                    return ['blocked' => true];
                }

                if ($checkout && $checkout->state === self::CHECKOUT_UNCERTAIN) {
                    return ['blocked' => true];
                }

                $handlingAmount = null;
                if ($freshPayment->handling_fee_fixed || $freshPayment->handling_fee_percent) {
                    $handlingAmount = (int) round(
                        ($order->total_amount * ($freshPayment->handling_fee_percent / 100))
                        + $freshPayment->handling_fee_fixed
                    );
                }
                $claimToken = bin2hex(random_bytes(16));
                $now = time();
                $values = [
                    'provider' => (string) $freshPayment->payment,
                    'payment_uuid' => (string) $freshPayment->uuid,
                    'config_snapshot' => null,
                    'provider_invoice_id' => null,
                    'provider_expires_at' => null,
                    'expected_amount' => null,
                    'state' => self::CHECKOUT_CREATING,
                    'claim_token' => $claimToken,
                    'base_amount' => (int) $order->total_amount,
                    'handling_amount' => $handlingAmount,
                    'response_type' => null,
                    'response_data' => null,
                    'attempted_at' => $now,
                    'updated_at' => $now,
                ];
                if ($checkout) {
                    DB::table(self::CHECKOUT_TABLE)->where('id', $checkout->id)->update($values);
                } else {
                    $inserted = DB::table(self::CHECKOUT_TABLE)->insertOrIgnore($values + [
                        'order_id' => $order->id,
                        'payment_id' => $freshPayment->id,
                        'created_at' => $now,
                    ]);
                    if ($inserted !== 1) {
                        return ['blocked' => true];
                    }
                }

                $order->payment_id = $freshPayment->id;
                $order->handling_amount = $handlingAmount;
                $order->saveOrFail();

                return [
                    'cached' => false,
                    'order' => $order,
                    'payment' => $freshPayment,
                    'amount' => (int) $order->total_amount + (int) ($handlingAmount ?? 0),
                    'claim_token' => $claimToken,
                ];
            });
        } finally {
            $lock->release();
        }

        if (!empty($result['blocked'])) {
            throw new ApiException(__('Payment verification is still in progress. Do not retry or cancel this order. Please contact support if it does not update.'));
        }

        return $result;
    }

    /** @param array{type: mixed, data: mixed} $result */
    public static function completeStandardPaymentCheckout(
        int $orderId,
        int $paymentId,
        string $claimToken,
        array $result
    ): void {
        $type = filter_var($result['type'] ?? null, FILTER_VALIDATE_INT);
        $data = $result['data'] ?? null;
        if ($type === false
            || !in_array((int) $type, [0, 1], true)
            || !is_string($data)
            || trim($data) === '') {
            throw new ApiException(__('Request failed, please try again later'), 503);
        }
        $encodedData = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $completed = DB::transaction(function () use (
            $orderId,
            $paymentId,
            $claimToken,
            $type,
            $encodedData
        ): bool {
            $order = Order::whereKey($orderId)->lockForUpdate()->first();
            $checkout = DB::table(self::CHECKOUT_TABLE)
                ->where('order_id', $orderId)
                ->where('payment_id', $paymentId)
                ->where('state', self::CHECKOUT_CREATING)
                ->where('claim_token', $claimToken)
                ->lockForUpdate()
                ->first();
            if (!$order || !$checkout) {
                return false;
            }
            if ((int) $order->status !== Order::STATUS_PENDING) {
                self::closePaymentCheckouts($orderId);
                return false;
            }

            return DB::table(self::CHECKOUT_TABLE)->where('id', $checkout->id)->update([
                'state' => self::CHECKOUT_READY,
                'claim_token' => null,
                'response_type' => (int) $type,
                'response_data' => $encodedData,
                'updated_at' => time(),
            ]) === 1;
        });

        if (!$completed) {
            // The provider call has already returned a checkout. Failure to
            // persist it is ambiguous and must never authorize another POST.
            throw new ApiException(__('Request failed, please try again later'), 503);
        }
    }

    public static function failStandardPaymentCheckout(
        int $orderId,
        int $paymentId,
        string $claimToken,
        bool $ambiguous
    ): void {
        DB::table(self::CHECKOUT_TABLE)
            ->where('order_id', $orderId)
            ->where('payment_id', $paymentId)
            ->where('state', self::CHECKOUT_CREATING)
            ->where('claim_token', $claimToken)
            ->update([
                'state' => $ambiguous ? self::CHECKOUT_UNCERTAIN : self::CHECKOUT_FAILED,
                'claim_token' => null,
                'updated_at' => time(),
            ]);
    }

    /** @param array{type: mixed, data: mixed, provider_invoice_id?: mixed, provider_expires_at?: mixed, expected_amount?: mixed} $result */
    public static function completeCoinPaymentsCheckout(
        int $orderId,
        int $paymentId,
        string $claimToken,
        array $result
    ): void {
        $type = filter_var($result['type'] ?? null, FILTER_VALIDATE_INT);
        $data = $result['data'] ?? null;
        $providerInvoiceIdValue = $result['provider_invoice_id'] ?? null;
        $providerInvoiceId = (is_scalar($providerInvoiceIdValue) || $providerInvoiceIdValue === null)
            ? trim((string) $providerInvoiceIdValue)
            : '';
        $expectedAmountValue = $result['expected_amount'] ?? null;
        $expectedAmount = (is_scalar($expectedAmountValue) || $expectedAmountValue === null)
            ? trim((string) $expectedAmountValue)
            : '';
        $providerExpiresAt = filter_var(
            $result['provider_expires_at'] ?? null,
            FILTER_VALIDATE_INT
        );
        if ($type !== 1
            || !self::isCoinPaymentsCheckoutUrl($data)
            || $providerInvoiceId === ''
            || $providerExpiresAt === false
            || (int) $providerExpiresAt <= time()
            || !is_numeric($expectedAmount)
            || (float) $expectedAmount <= 0) {
            throw new ApiException(__('Request failed, please try again later'), 503);
        }
        $providerExpiresAt = (int) $providerExpiresAt;

        $completed = DB::transaction(function () use (
            $orderId,
            $paymentId,
            $claimToken,
            $type,
            $data,
            $providerInvoiceId,
            $providerExpiresAt,
            $expectedAmount
        ): bool {
            $order = Order::whereKey($orderId)->lockForUpdate()->first();
            $checkout = DB::table(self::CHECKOUT_TABLE)
                ->where('order_id', $orderId)
                ->where('payment_id', $paymentId)
                ->where('state', self::CHECKOUT_CREATING)
                ->where('claim_token', $claimToken)
                ->lockForUpdate()
                ->first();
            if (!$order || !$checkout) {
                return false;
            }
            if ((int) $order->status !== Order::STATUS_PENDING) {
                self::closePaymentCheckouts($orderId);
                return false;
            }
            if ($checkout->expected_amount === null
                || !hash_equals((string) $checkout->expected_amount, $expectedAmount)) {
                return false;
            }

            return DB::table(self::CHECKOUT_TABLE)->where('id', $checkout->id)->update([
                'state' => self::CHECKOUT_READY,
                'claim_token' => null,
                'provider_invoice_id' => $providerInvoiceId,
                'provider_expires_at' => $providerExpiresAt,
                'response_type' => (int) $type,
                'response_data' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => time(),
            ]) === 1;
        });

        if (!$completed) {
            throw new ApiException(__('Request failed, please try again later'), 503);
        }
    }

    public static function failCoinPaymentsCheckout(
        int $orderId,
        int $paymentId,
        string $claimToken,
        bool $ambiguous
    ): void {
        DB::table(self::CHECKOUT_TABLE)
            ->where('order_id', $orderId)
            ->where('payment_id', $paymentId)
            ->where('state', self::CHECKOUT_CREATING)
            ->where('claim_token', $claimToken)
            ->update([
                'state' => $ambiguous ? self::CHECKOUT_UNCERTAIN : self::CHECKOUT_FAILED,
                'claim_token' => null,
                'updated_at' => time(),
            ]);
    }

    /**
     * Apply an authenticated CoinPayments lifecycle event while holding the
     * order and checkout rows. Authentication happens in the plugin, but the
     * order status decision must be made in the same transaction as the state
     * change so a concurrent cancellation can never be acknowledged silently.
     *
     * @param array{event: string, trade_no: string, callback_no: string, checkout_id: int, payment_uuid: string, provider_invoice_id: string} $notification
     * @return array{order: Order, transitioned: bool, cancelled: bool}
     */
    public static function handleCoinPaymentsNotification(array $notification): array
    {
        $event = (string) ($notification['event'] ?? '');
        $tradeNo = trim((string) ($notification['trade_no'] ?? ''));
        $callbackNo = trim((string) ($notification['callback_no'] ?? ''));
        $checkoutId = filter_var($notification['checkout_id'] ?? null, FILTER_VALIDATE_INT);
        $paymentUuid = trim((string) ($notification['payment_uuid'] ?? ''));
        $providerInvoiceId = trim((string) ($notification['provider_invoice_id'] ?? ''));
        if (!in_array($event, ['completed', 'timed_out', 'cancelled'], true)
            || $tradeNo === ''
            || $callbackNo === ''
            || $checkoutId === false
            || (int) $checkoutId <= 0
            || $paymentUuid === ''
            || $providerInvoiceId === '') {
            throw new ApiException(__('CoinPayments invoice does not match the payment checkout.'), 400);
        }

        $outcome = DB::transaction(function () use (
            $event,
            $tradeNo,
            $callbackNo,
            $checkoutId,
            $paymentUuid,
            $providerInvoiceId
        ): array {
            $order = Order::where('trade_no', $tradeNo)->lockForUpdate()->first();
            if (!$order) {
                throw new ApiException(__('Order does not exist'), 400);
            }

            $checkout = DB::table(self::CHECKOUT_TABLE)
                ->where('id', (int) $checkoutId)
                ->where('order_id', $order->id)
                ->where('provider', 'CoinPayments')
                ->where('payment_uuid', $paymentUuid)
                ->lockForUpdate()
                ->first();
            if (!$checkout) {
                throw new ApiException(__('CoinPayments invoice does not match the payment checkout.'), 400);
            }

            $storedProviderInvoiceId = trim((string) ($checkout->provider_invoice_id ?? ''));
            $legacyUnboundInvoice = $storedProviderInvoiceId === '';
            if (!$legacyUnboundInvoice
                && !hash_equals($storedProviderInvoiceId, $providerInvoiceId)) {
                throw new ApiException(__('CoinPayments invoice identifier does not match.'), 400);
            }

            $bindProviderInvoice = static function () use (
                $checkout,
                $legacyUnboundInvoice,
                $providerInvoiceId
            ): void {
                if ($legacyUnboundInvoice) {
                    DB::table(self::CHECKOUT_TABLE)->where('id', $checkout->id)->update([
                        'provider_invoice_id' => $providerInvoiceId,
                        'updated_at' => time(),
                    ]);
                }
            };

            if ($event !== 'completed') {
                // A signed terminal event is provider authority that this one
                // payment path is no longer payable. Preserve its concrete
                // invoice identity, then close the local order exactly once so
                // the unique checkout row is never overwritten by a new remote
                // invoice with a different identity.
                $bindProviderInvoice();
                $status = (int) $order->status;
                if ($status === Order::STATUS_PENDING) {
                    $anotherActiveCheckout = DB::table(self::CHECKOUT_TABLE)
                        ->where('order_id', $order->id)
                        ->where('id', '!=', $checkout->id)
                        ->whereIn('state', self::activeCoinPaymentsStates())
                        ->exists();
                    if ($anotherActiveCheckout) {
                        DB::table(self::CHECKOUT_TABLE)->where('id', $checkout->id)->update([
                            'state' => self::CHECKOUT_CLOSED,
                            'claim_token' => null,
                            'response_data' => null,
                            'updated_at' => time(),
                        ]);
                        return [
                            'order' => $order,
                            'transitioned' => false,
                            'cancelled' => false,
                            'conflict' => true,
                        ];
                    }

                    HookManager::call('order.cancel.before', $order);
                    $order->status = Order::STATUS_CANCELLED;
                    $order->saveOrFail();
                    self::closePaymentCheckouts($order->id);
                    if ($order->balance_amount) {
                        $userService = new UserService();
                        if (!$userService->addBalance($order->user_id, $order->balance_amount)) {
                            throw new \RuntimeException('Failed to add balance.');
                        }
                    }

                    return [
                        'order' => $order,
                        'transitioned' => false,
                        'cancelled' => true,
                        'conflict' => false,
                    ];
                }

                DB::table(self::CHECKOUT_TABLE)->where('id', $checkout->id)->update([
                    'state' => self::CHECKOUT_CLOSED,
                    'claim_token' => null,
                    'response_data' => null,
                    'updated_at' => time(),
                ]);
                if ($status === Order::STATUS_CANCELLED) {
                    return [
                        'order' => $order,
                        'transitioned' => false,
                        'cancelled' => false,
                        'conflict' => false,
                    ];
                }
                if (in_array($status, [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED], true)
                    && (int) $order->payment_id === (int) $checkout->payment_id) {
                    return [
                        'order' => $order,
                        'transitioned' => false,
                        'cancelled' => false,
                        'conflict' => true,
                    ];
                }

                return [
                    'order' => $order,
                    'transitioned' => false,
                    'cancelled' => false,
                    'conflict' => false,
                ];
            }

            $status = (int) $order->status;
            if ($status === Order::STATUS_PENDING) {
                $bindProviderInvoice();
                if (!in_array(
                    (string) $checkout->state,
                    [self::CHECKOUT_CREATING, self::CHECKOUT_READY, self::CHECKOUT_UNCERTAIN],
                    true
                )) {
                    return [
                        'order' => $order,
                        'transitioned' => false,
                        'cancelled' => false,
                        'conflict' => true,
                    ];
                }
                $anotherActiveCheckout = DB::table(self::CHECKOUT_TABLE)
                    ->where('order_id', $order->id)
                    ->where('id', '!=', $checkout->id)
                    ->whereIn('state', self::activeCoinPaymentsStates())
                    ->exists();
                if ($anotherActiveCheckout) {
                    return [
                        'order' => $order,
                        'transitioned' => false,
                        'cancelled' => false,
                        'conflict' => true,
                    ];
                }

                $order->payment_id = (int) $checkout->payment_id;
                $order->handling_amount = $checkout->handling_amount;
                $order->status = Order::STATUS_PROCESSING;
                $order->paid_at = time();
                $order->callback_no = $callbackNo;
                $order->saveOrFail();
                self::closePaymentCheckouts($order->id);

                return [
                    'order' => $order,
                    'transitioned' => true,
                    'cancelled' => false,
                    'conflict' => false,
                ];
            }

            // Only a checkout already bound to this concrete provider invoice
            // can be acknowledged as an idempotent replay. A cancelled order,
            // another gateway's settlement, or an unbound legacy row requires
            // operator reconciliation and must keep returning non-2xx.
            $sameSettledInvoice = in_array($status, [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED], true)
                && (int) $order->payment_id === (int) $checkout->payment_id
                && hash_equals((string) $order->callback_no, $providerInvoiceId);
            $bindProviderInvoice();
            if ($sameSettledInvoice) {
                return [
                    'order' => $order,
                    'transitioned' => false,
                    'cancelled' => false,
                    'conflict' => false,
                ];
            }

            return [
                'order' => $order,
                'transitioned' => false,
                'cancelled' => false,
                'conflict' => true,
            ];
        });

        if (!empty($outcome['conflict'])) {
            // Throw only after the transaction commits the provider invoice ID
            // as reconciliation evidence. Throwing inside would roll it back.
            throw new ApiException(
                __('A completed CoinPayments invoice requires manual order reconciliation.'),
                409
            );
        }
        if ($outcome['transitioned']) {
            OrderHandleJob::dispatchSync($outcome['order']->trade_no);
        }
        if ($outcome['cancelled']) {
            HookManager::call('order.cancel.after', $outcome['order']);
        }

        return $outcome;
    }

    /**
     * Create or reuse one self-custody USDT invoice for an order.
     *
     * This operation performs no remote POST. Address/amount allocation and
     * the checkout transition can therefore be committed together, while a
     * global allocation lock serializes the exact-amount discriminator across
     * users on SQLite deployments.
     *
     * @return array{cached: bool, order: Order, payment: Payment, invoice: UsdtDirectInvoice, type: int, data: string, amount_raw: string}
     */
    public static function beginUsdtDirectCheckout(
        int $userId,
        string $tradeNo,
        Payment $payment
    ): array {
        $allocationLock = Cache::lock('usdt-direct-invoice-allocation', 15);
        if (!$allocationLock->get()) {
            throw new ApiException(__('Request failed, please try again later'));
        }
        $orderLock = Cache::lock(self::paymentCheckoutLockKey($userId, $tradeNo), 15);
        if (!$orderLock->get()) {
            $allocationLock->release();
            throw new ApiException(__('Request failed, please try again later'));
        }

        try {
            return DB::transaction(function () use ($userId, $tradeNo, $payment): array {
                $order = Order::query()
                    ->where('trade_no', $tradeNo)
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();
                if (!$order || (int) $order->status !== Order::STATUS_PENDING) {
                    throw new ApiException(__('Order does not exist or has been paid'));
                }
                if ((int) $order->total_amount <= 0) {
                    throw new ApiException(__('Order amount is invalid'));
                }

                $freshPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();
                if (!$freshPayment || (string) $freshPayment->payment !== 'UsdtDirect') {
                    throw new ApiException(__('Payment method is not available'));
                }

                $existingInvoice = UsdtDirectInvoice::query()
                    ->where('order_id', $order->id)
                    ->lockForUpdate()
                    ->first();
                $checkout = DB::table(self::CHECKOUT_TABLE)
                    ->where('order_id', $order->id)
                    ->where('payment_id', $freshPayment->id)
                    ->lockForUpdate()
                    ->first();

                if ($existingInvoice && $checkout
                    && (int) $existingInvoice->checkout_id === (int) $checkout->id
                    && (int) $existingInvoice->payment_id === (int) $freshPayment->id
                    && hash_equals((string) $existingInvoice->payment_uuid, (string) $freshPayment->uuid)
                    && in_array((string) $existingInvoice->state, [
                        UsdtDirectInvoice::STATE_AWAITING,
                        UsdtDirectInvoice::STATE_SEEN,
                    ], true)
                    && in_array((string) $checkout->state, [
                        self::CHECKOUT_CREATING,
                        self::CHECKOUT_READY,
                        self::CHECKOUT_UNCERTAIN,
                    ], true)) {
                    $snapshot = self::decryptUsdtDirectSnapshot((string) $existingInvoice->config_snapshot);
                    if ((int) ($snapshot['payment_id'] ?? 0) !== (int) $existingInvoice->payment_id
                        || !hash_equals((string) $existingInvoice->payment_uuid, (string) ($snapshot['payment_uuid'] ?? ''))
                        || !hash_equals((string) $existingInvoice->public_token_hash, (string) ($snapshot['public_token_hash'] ?? ''))
                        || !hash_equals((string) $existingInvoice->expected_amount_raw, (string) ($snapshot['expected_amount_raw'] ?? ''))
                        || !hash_equals((string) $existingInvoice->receiving_address, (string) ($snapshot['receiving_address'] ?? ''))
                        || !self::usdtDirectCheckoutMatchesInvoice($existingInvoice, $checkout, $order)) {
                        throw new ApiException(__('USDT invoice configuration could not be verified.'), 409);
                    }
                    $checkoutUrl = source_base_url('/pay/usdt/' . rawurlencode($snapshot['public_token']));
                    DB::table(self::CHECKOUT_TABLE)->where('id', $checkout->id)->update([
                        'state' => self::CHECKOUT_READY,
                        'claim_token' => null,
                        'provider_invoice_id' => (string) $existingInvoice->public_token_hash,
                        'provider_expires_at' => (int) $existingInvoice->expires_at,
                        'expected_amount' => (string) $existingInvoice->expected_amount_raw,
                        'response_type' => 1,
                        // The raw capability token belongs only in the
                        // encrypted snapshot and the immediate API response.
                        'response_data' => null,
                        'updated_at' => time(),
                    ]);
                    $order->payment_id = $freshPayment->id;
                    $order->handling_amount = $checkout->handling_amount;
                    $order->saveOrFail();

                    return [
                        'cached' => true,
                        'order' => $order,
                        'payment' => $freshPayment,
                        'invoice' => $existingInvoice,
                        'type' => 1,
                        'data' => $checkoutUrl,
                        'amount_raw' => (string) $existingInvoice->expected_amount_raw,
                    ];
                }
                if ($existingInvoice) {
                    throw new ApiException(__('This USDT invoice requires manual reconciliation before it can be replaced.'), 409);
                }
                if (!(bool) $freshPayment->enable) {
                    throw new ApiException(__('Payment method is not available'));
                }
                // New allocations freeze the currently enabled method. Reuse
                // above deliberately needs only the encrypted snapshot: a
                // later config rotation/disable must not orphan an invoice the
                // customer already received.
                (new PaymentService($freshPayment->payment, $freshPayment->id))
                    ->validateConfiguration();

                $anotherActiveCheckout = DB::table(self::CHECKOUT_TABLE)
                    ->where('order_id', $order->id)
                    ->whereIn('state', self::activeCoinPaymentsStates())
                    ->exists();
                if ($anotherActiveCheckout) {
                    throw new ApiException(__('Payment verification is still in progress. Do not retry or cancel this order. Please contact support if it does not update.'));
                }

                $config = is_array($freshPayment->config) ? $freshPayment->config : [];
                $network = strtolower(self::usdtDirectConfigText($config, 'usdt_network', 'tron'));
                $contract = self::usdtDirectConfigText(
                    $config,
                    'usdt_token_contract',
                    'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'
                );
                $address = self::usdtDirectConfigText($config, 'usdt_receive_address');
                $rate = self::usdtDirectConfigText($config, 'usdt_cny_usdt_rate');
                if ($network !== 'tron' || $contract === '' || $address === '' || $rate === '') {
                    throw new ApiException(__('USDT payment configuration is incomplete.'), 400);
                }
                $ttlMinutes = self::usdtDirectConfigInteger(
                    $config,
                    'usdt_invoice_ttl_minutes',
                    30,
                    5,
                    120
                );
                $requiredConfirmations = self::usdtDirectConfigInteger(
                    $config,
                    'usdt_required_confirmations',
                    20,
                    1,
                    100
                );
                $handlingAmount = null;
                if ($freshPayment->handling_fee_fixed || $freshPayment->handling_fee_percent) {
                    $handlingAmount = (int) round(
                        ($order->total_amount * ($freshPayment->handling_fee_percent / 100))
                        + $freshPayment->handling_fee_fixed
                    );
                }
                $baseAmountRaw = self::usdtDirectExpectedAmountRaw(
                    (int) $order->total_amount + (int) ($handlingAmount ?? 0),
                    $rate
                );
                $expectedAmountRaw = self::allocateUsdtDirectAmountRaw(
                    $network,
                    $contract,
                    $address,
                    $baseAmountRaw,
                    (string) $order->trade_no
                );
                $now = time();
                $expiresAt = $now + ($ttlMinutes * 60);
                $publicToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                $publicTokenHash = hash('sha256', $publicToken);
                $snapshot = [
                    'snapshot_version' => 1,
                    'payment_id' => (int) $freshPayment->id,
                    'payment_uuid' => (string) $freshPayment->uuid,
                    'network' => $network,
                    'token_contract' => $contract,
                    'receiving_address' => $address,
                    'expected_amount_raw' => $expectedAmountRaw,
                    'exchange_rate' => $rate,
                    'required_confirmations' => $requiredConfirmations,
                    'expires_at' => $expiresAt,
                    'public_token' => $publicToken,
                    'public_token_hash' => $publicTokenHash,
                ];
                $encryptedSnapshot = Crypt::encryptString(json_encode(
                    $snapshot,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ));
                $checkoutValues = [
                    'provider' => 'UsdtDirect',
                    'payment_uuid' => (string) $freshPayment->uuid,
                    'config_snapshot' => $encryptedSnapshot,
                    'provider_invoice_id' => $publicTokenHash,
                    'provider_expires_at' => $expiresAt,
                    'expected_amount' => $expectedAmountRaw,
                    'state' => self::CHECKOUT_READY,
                    'claim_token' => null,
                    'base_amount' => (int) $order->total_amount,
                    'handling_amount' => $handlingAmount,
                    'response_type' => 1,
                    'attempted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $checkoutUrl = source_base_url('/pay/usdt/' . rawurlencode($publicToken));
                // Never persist the raw capability URL in the generic
                // checkout row. Retry reconstructs it from config_snapshot,
                // which is encrypted with APP_KEY.
                $checkoutValues['response_data'] = null;

                if ($checkout) {
                    DB::table(self::CHECKOUT_TABLE)->where('id', $checkout->id)->update($checkoutValues);
                    $checkoutId = (int) $checkout->id;
                } else {
                    $checkoutId = (int) DB::table(self::CHECKOUT_TABLE)->insertGetId($checkoutValues + [
                        'order_id' => $order->id,
                        'payment_id' => $freshPayment->id,
                    ]);
                }

                $invoice = UsdtDirectInvoice::query()->create([
                    'order_id' => $order->id,
                    'checkout_id' => $checkoutId,
                    'payment_id' => $freshPayment->id,
                    'payment_uuid' => (string) $freshPayment->uuid,
                    'public_token_hash' => $publicTokenHash,
                    'network' => $network,
                    'token_contract' => $contract,
                    'receiving_address' => $address,
                    'expected_amount_raw' => $expectedAmountRaw,
                    'exchange_rate' => $rate,
                    'required_confirmations' => $requiredConfirmations,
                    'state' => UsdtDirectInvoice::STATE_AWAITING,
                    'expires_at' => $expiresAt,
                    'config_snapshot' => $encryptedSnapshot,
                ]);
                $order->payment_id = $freshPayment->id;
                $order->handling_amount = $handlingAmount;
                $order->saveOrFail();

                return [
                    'cached' => false,
                    'order' => $order,
                    'payment' => $freshPayment,
                    'invoice' => $invoice,
                    'type' => 1,
                    'data' => $checkoutUrl,
                    'amount_raw' => $expectedAmountRaw,
                ];
            });
        } finally {
            $orderLock->release();
            $allocationLock->release();
        }
    }

    /**
     * Atomically record and, once final, settle one verified USDT TRC20 event.
     *
     * The scanner is responsible for obtaining the chain event, but this
     * boundary independently binds its immutable identity, amount, destination
     * and confirmation count to the frozen invoice. No caller may credit an
     * order by invoking the generic paid() helper with an arbitrary txid.
     *
     * @param array{
     *   network:mixed, token_contract:mixed, txid:mixed, log_index:mixed,
     *   from_address?:mixed, to_address:mixed, amount_raw:mixed,
     *   block_number:mixed, block_hash?:mixed, block_timestamp:mixed,
     *   confirmations?:mixed, successful?:mixed, receipt_result?:mixed,
     *   solidified?:mixed, confirmed?:mixed, raw_payload_hash?:mixed
     * } $event
     * @return array{invoice: UsdtDirectInvoice, order: Order, transitioned: bool, manual_review: bool, pending_confirmation: bool, replay: bool}
     */
    public static function settleUsdtDirectTransfer(int $invoiceId, array $event): array
    {
        $event = self::normaliseUsdtDirectEvent($event);

        $outcome = DB::transaction(function () use ($invoiceId, $event): array {
            $invoice = UsdtDirectInvoice::query()->whereKey($invoiceId)->lockForUpdate()->first();
            if (!$invoice) {
                throw new ApiException(__('USDT payment invoice does not exist.'), 404);
            }
            $order = Order::query()->whereKey($invoice->order_id)->lockForUpdate()->first();
            if (!$order) {
                throw new ApiException(__('Order does not exist'), 400);
            }

            $checkout = DB::table(self::CHECKOUT_TABLE)
                ->where('id', $invoice->checkout_id)
                ->where('order_id', $order->id)
                ->where('payment_id', $invoice->payment_id)
                ->where('provider', 'UsdtDirect')
                ->lockForUpdate()
                ->first();

            // Match the frozen allocation before touching an existing chain
            // identity. A malformed scanner call must not be able to push an
            // unrelated invoice into manual review.
            if (!hash_equals((string) $invoice->network, $event['network'])
                || !hash_equals((string) $invoice->token_contract, $event['token_contract'])
                || !hash_equals((string) $invoice->receiving_address, $event['to_address'])
                || !hash_equals((string) $invoice->expected_amount_raw, $event['amount_raw'])) {
                throw new ApiException(__('USDT transfer does not match the payment invoice.'), 409);
            }

            $identityQuery = UsdtDirectTransfer::query()
                ->where('network', $event['network'])
                ->where('token_contract', $event['token_contract'])
                ->where('txid', $event['txid'])
                ->where('log_index', $event['log_index']);
            $transfer = $identityQuery->lockForUpdate()->first();
            $transferWasExisting = $transfer !== null;
            if ($transfer && $transfer->invoice_id !== null
                && (int) $transfer->invoice_id !== (int) $invoice->id) {
                return self::manualReviewUsdtDirect(
                    $invoice,
                    $order,
                    $checkout,
                    'transfer_already_bound'
                );
            }

            $transferValues = [
                'invoice_id' => $invoice->id,
                'network' => $event['network'],
                'token_contract' => $event['token_contract'],
                'txid' => $event['txid'],
                'log_index' => $event['log_index'],
                'from_address' => $event['from_address'],
                'to_address' => $event['to_address'],
                'amount_raw' => $event['amount_raw'],
                'block_number' => $event['block_number'],
                'block_hash' => $event['block_hash'],
                'block_timestamp' => $event['block_timestamp'],
                'confirmations' => $event['confirmations'],
                'manual_review_reason' => null,
                'raw_payload_hash' => $event['raw_payload_hash'],
                'updated_at' => time(),
            ];

            if (!$transfer) {
                // Reserve the on-chain identity before any state transition.
                // insertOrIgnore plus the database unique key closes the race
                // where two invoices are processed by different workers.
                $inserted = DB::table('v2_usdt_direct_transfer')->insertOrIgnore($transferValues + [
                    'state' => UsdtDirectTransfer::STATE_SEEN,
                    'created_at' => time(),
                ]);
                // If another worker won the unique-chain-identity race, the
                // row we are about to re-fetch is pre-existing evidence and
                // must pass the immutable conflict check below.
                $transferWasExisting = $inserted === 0;
                $transfer = UsdtDirectTransfer::query()
                    ->where('network', $event['network'])
                    ->where('token_contract', $event['token_contract'])
                    ->where('txid', $event['txid'])
                    ->where('log_index', $event['log_index'])
                    ->lockForUpdate()
                    ->first();
                if (!$transfer || ($transfer->invoice_id !== null
                    && (int) $transfer->invoice_id !== (int) $invoice->id)) {
                    return self::manualReviewUsdtDirect(
                        $invoice,
                        $order,
                        $checkout,
                        'transfer_already_bound'
                    );
                }
                if ($transfer->invoice_id === null) {
                    $transfer->invoice_id = $invoice->id;
                    $transfer->saveOrFail();
                }
            }

            if ($transferWasExisting && self::usdtDirectTransferConflicts($transfer, $event)) {
                $transfer->state = UsdtDirectTransfer::STATE_MANUAL_REVIEW;
                $transfer->manual_review_reason = 'transfer_evidence_changed';
                $transfer->confirmations = max(
                    (int) $transfer->confirmations,
                    (int) $event['confirmations']
                );
                $transfer->saveOrFail();

                return self::manualReviewUsdtDirect(
                    $invoice,
                    $order,
                    $checkout,
                    'transfer_evidence_changed'
                );
            }

            // Optional evidence can become available on later scans, but it
            // must never be erased. Confirmation counts are monotonic in our
            // record even if one TronGrid replica momentarily lags another.
            $transferValues['from_address'] ??= $transfer->from_address;
            $transferValues['block_hash'] ??= $transfer->block_hash;
            $transferValues['raw_payload_hash'] ??= $transfer->raw_payload_hash;
            $transferValues['confirmations'] = max(
                (int) $transfer->confirmations,
                (int) $event['confirmations']
            );

            if (!$checkout) {
                self::persistUsdtDirectTransfer(
                    $transfer,
                    $transferValues + ['state' => UsdtDirectTransfer::STATE_MANUAL_REVIEW]
                );
                return self::manualReviewUsdtDirect(
                    $invoice,
                    $order,
                    null,
                    'payment_checkout_missing'
                );
            }
            if (!self::usdtDirectCheckoutMatchesInvoice($invoice, $checkout, $order)) {
                self::persistUsdtDirectTransfer(
                    $transfer,
                    $transferValues + ['state' => UsdtDirectTransfer::STATE_MANUAL_REVIEW]
                );
                return self::manualReviewUsdtDirect(
                    $invoice,
                    $order,
                    $checkout,
                    'payment_checkout_mismatch'
                );
            }

            $callbackNo = self::usdtDirectCallbackNo($event);
            $sameSettledTransfer = in_array(
                (int) $order->status,
                [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED],
                true
            )
                && (int) $order->payment_id === (int) $invoice->payment_id
                && hash_equals((string) $order->callback_no, $callbackNo)
                && (string) $invoice->state === UsdtDirectInvoice::STATE_CONFIRMED;
            if ($sameSettledTransfer) {
                $transfer->fill($transferValues + [
                    'state' => UsdtDirectTransfer::STATE_SETTLED,
                ])->saveOrFail();

                return [
                    'invoice' => $invoice,
                    'order' => $order,
                    'transitioned' => false,
                    'manual_review' => false,
                    'pending_confirmation' => false,
                    'replay' => true,
                ];
            }

            if (!$event['successful']) {
                self::persistUsdtDirectTransfer(
                    $transfer,
                    $transferValues + ['state' => UsdtDirectTransfer::STATE_REVERTED]
                );

                return [
                    'invoice' => $invoice,
                    'order' => $order,
                    'transitioned' => false,
                    'manual_review' => false,
                    'pending_confirmation' => false,
                    'replay' => false,
                ];
            }

            // Fulfillment is irreversible at this boundary. A duplicate
            // payment or a second identical Transfer log is operator evidence,
            // but it must never downgrade a confirmed invoice or re-dispatch
            // the order. Preserve it as a transfer-level review item instead.
            if ((string) $invoice->state === UsdtDirectInvoice::STATE_CONFIRMED) {
                self::persistUsdtDirectTransfer(
                    $transfer,
                    array_replace($transferValues, [
                        'state' => UsdtDirectTransfer::STATE_MANUAL_REVIEW,
                        'manual_review_reason' => 'additional_transfer_after_settlement',
                    ])
                );

                return [
                    'invoice' => $invoice,
                    'order' => $order,
                    'transitioned' => false,
                    'manual_review' => true,
                    'pending_confirmation' => false,
                    'replay' => false,
                ];
            }

            if ((int) $event['block_timestamp'] < (int) $invoice->created_at
                || (int) $event['block_timestamp'] > (int) $invoice->expires_at) {
                self::persistUsdtDirectTransfer(
                    $transfer,
                    $transferValues + ['state' => UsdtDirectTransfer::STATE_MANUAL_REVIEW]
                );
                return self::manualReviewUsdtDirect(
                    $invoice,
                    $order,
                    $checkout,
                    'transfer_outside_invoice_window'
                );
            }

            if (!in_array((string) $invoice->state, [
                UsdtDirectInvoice::STATE_AWAITING,
                UsdtDirectInvoice::STATE_SEEN,
            ], true)) {
                self::persistUsdtDirectTransfer(
                    $transfer,
                    $transferValues + ['state' => UsdtDirectTransfer::STATE_MANUAL_REVIEW]
                );
                $reason = match ((string) $invoice->state) {
                    UsdtDirectInvoice::STATE_EXPIRED => 'invoice_expired',
                    UsdtDirectInvoice::STATE_CLOSED => 'invoice_closed',
                    UsdtDirectInvoice::STATE_CONFIRMED => 'invoice_settlement_conflict',
                    default => (string) ($invoice->manual_review_reason ?: 'invoice_requires_manual_review'),
                };
                return self::manualReviewUsdtDirect(
                    $invoice,
                    $order,
                    $checkout,
                    $reason
                );
            }

            if ((int) $order->status !== Order::STATUS_PENDING) {
                self::persistUsdtDirectTransfer(
                    $transfer,
                    $transferValues + ['state' => UsdtDirectTransfer::STATE_MANUAL_REVIEW]
                );
                return self::manualReviewUsdtDirect(
                    $invoice,
                    $order,
                    $checkout,
                    (int) $order->status === Order::STATUS_CANCELLED
                        ? 'order_cancelled'
                        : 'order_already_settled'
                );
            }

            if (!in_array((string) $checkout->state, [
                self::CHECKOUT_CREATING,
                self::CHECKOUT_READY,
                self::CHECKOUT_UNCERTAIN,
            ], true)) {
                self::persistUsdtDirectTransfer(
                    $transfer,
                    $transferValues + ['state' => UsdtDirectTransfer::STATE_MANUAL_REVIEW]
                );
                return self::manualReviewUsdtDirect(
                    $invoice,
                    $order,
                    $checkout,
                    'payment_checkout_closed'
                );
            }

            if (!$event['solidified']
                && $event['confirmations'] < (int) $invoice->required_confirmations) {
                $transfer = self::persistUsdtDirectTransfer(
                    $transfer,
                    $transferValues + ['state' => UsdtDirectTransfer::STATE_SEEN]
                );
                $invoice->state = UsdtDirectInvoice::STATE_SEEN;
                $invoice->seen_at ??= time();
                $invoice->txid = $transfer->txid;
                $invoice->log_index = $transfer->log_index;
                $invoice->block_number = $transfer->block_number;
                $invoice->block_hash = $transfer->block_hash;
                $invoice->block_timestamp = $transfer->block_timestamp;
                $invoice->saveOrFail();

                return [
                    'invoice' => $invoice,
                    'order' => $order,
                    'transitioned' => false,
                    'manual_review' => false,
                    'pending_confirmation' => true,
                    'replay' => false,
                ];
            }

            $transfer = self::persistUsdtDirectTransfer(
                $transfer,
                $transferValues + [
                    'state' => UsdtDirectTransfer::STATE_SETTLED,
                    'manual_review_reason' => null,
                ]
            );
            $now = time();
            $invoice->state = UsdtDirectInvoice::STATE_CONFIRMED;
            $invoice->seen_at ??= $now;
            $invoice->confirmed_at = $now;
            $invoice->manual_review_reason = null;
            $invoice->txid = $transfer->txid;
            $invoice->log_index = $transfer->log_index;
            $invoice->block_number = $transfer->block_number;
            $invoice->block_hash = $transfer->block_hash;
            $invoice->block_timestamp = $transfer->block_timestamp;
            $invoice->saveOrFail();

            $order->payment_id = (int) $invoice->payment_id;
            $order->status = Order::STATUS_PROCESSING;
            $order->paid_at = $now;
            $order->callback_no = $callbackNo;
            $order->saveOrFail();
            self::closePaymentCheckouts((int) $order->id);

            return [
                'invoice' => $invoice,
                'order' => $order,
                'transitioned' => true,
                'manual_review' => false,
                'pending_confirmation' => false,
                'replay' => false,
            ];
        });

        if ($outcome['transitioned']) {
            OrderHandleJob::dispatchSync($outcome['order']->trade_no);
            $outcome['order']->refresh();
            $outcome['invoice']->refresh();
        }

        return $outcome;
    }

    /**
     * Expire an unpaid invoice without reusing its address/amount assignment.
     * A seen transfer remains open for finality even if confirmations arrive
     * after the customer-facing countdown reaches zero.
     */
    public static function expireUsdtDirectInvoice(int $invoiceId, ?int $now = null): bool
    {
        $now ??= time();

        return DB::transaction(function () use ($invoiceId, $now): bool {
            $invoice = UsdtDirectInvoice::query()->whereKey($invoiceId)->lockForUpdate()->first();
            if (!$invoice
                || (string) $invoice->state !== UsdtDirectInvoice::STATE_AWAITING
                || (int) $invoice->expires_at > $now) {
                return false;
            }

            $hasSeenTransfer = UsdtDirectTransfer::query()
                ->where('invoice_id', $invoice->id)
                ->whereIn('state', [
                    UsdtDirectTransfer::STATE_SEEN,
                    UsdtDirectTransfer::STATE_CONFIRMED,
                    UsdtDirectTransfer::STATE_SETTLED,
                ])
                ->lockForUpdate()
                ->exists();
            if ($hasSeenTransfer) {
                return false;
            }

            $invoice->state = UsdtDirectInvoice::STATE_EXPIRED;
            $invoice->saveOrFail();
            DB::table(self::CHECKOUT_TABLE)
                ->where('id', $invoice->checkout_id)
                ->where('order_id', $invoice->order_id)
                ->where('payment_id', $invoice->payment_id)
                ->where('provider', 'UsdtDirect')
                ->update([
                    'state' => self::CHECKOUT_CLOSED,
                    'claim_token' => null,
                    'response_data' => null,
                    'updated_at' => $now,
                ]);

            return true;
        });
    }

    private static function usdtDirectConfigText(
        array $config,
        string $key,
        string $default = ''
    ): string {
        $value = $config[$key] ?? $default;
        if (!is_string($value) && !is_int($value)) {
            throw new ApiException(__("USDT configuration {$key} must be text."), 400);
        }

        return trim((string) $value);
    }

    private static function usdtDirectConfigInteger(
        array $config,
        string $key,
        int $default,
        int $minimum,
        int $maximum
    ): int {
        $value = $config[$key] ?? $default;
        if ((!is_string($value) && !is_int($value))
            || filter_var($value, FILTER_VALIDATE_INT) === false
            || (int) $value < $minimum
            || (int) $value > $maximum) {
            throw new ApiException(__("USDT configuration {$key} is invalid."), 400);
        }

        return (int) $value;
    }

    /** Convert CNY cents * USDT-per-CNY rate to six-decimal token units. */
    private static function usdtDirectExpectedAmountRaw(int $amountCnyCents, string $rate): string
    {
        if ($amountCnyCents <= 0
            || !preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/D', $rate)) {
            throw new ApiException(__('USDT exchange rate is invalid.'), 400);
        }

        try {
            // XBoard amounts are CNY cents. Multiplication by 10,000 is
            // therefore equivalent to /100 CNY * 1,000,000 token base units.
            // Brick math keeps the calculation exact even when a rate has
            // more than six decimals or the raw amount exceeds native INT.
            $amountRaw = BigDecimal::of((string) $amountCnyCents)
                ->multipliedBy($rate)
                ->multipliedBy('10000')
                ->toScale(0, RoundingMode::Ceiling)
                ->getUnscaledValue();
        } catch (\Throwable $exception) {
            throw new ApiException(__('USDT payment amount is outside the supported range.'), 400);
        }
        $raw = (string) $amountRaw;
        if ($amountRaw->isLessThanOrEqualTo(BigInteger::zero()) || strlen($raw) > 40) {
            throw new ApiException(__('USDT payment amount is invalid.'), 400);
        }

        return $raw;
    }

    /** Allocate a permanent exact-amount discriminator within 0.009999 USDT. */
    private static function allocateUsdtDirectAmountRaw(
        string $network,
        string $contract,
        string $address,
        string $baseAmountRaw,
        string $tradeNo
    ): string {
        $seed = (int) hexdec(substr(hash('sha256', $tradeNo), 0, 7));
        $base = BigInteger::of($baseAmountRaw);
        for ($attempt = 0; $attempt < 10_000; $attempt++) {
            $candidate = (string) $base->plus(($seed + $attempt) % 10_000);
            if (strlen($candidate) > 40) {
                break;
            }
            $exists = UsdtDirectInvoice::query()
                ->where('network', $network)
                ->where('token_contract', $contract)
                ->where('receiving_address', $address)
                ->where('expected_amount_raw', $candidate)
                ->exists();
            if (!$exists) {
                return $candidate;
            }
        }

        throw new ApiException(__('No unique USDT invoice amount is currently available.'), 503);
    }

    /** @return array<string, mixed> */
    private static function decryptUsdtDirectSnapshot(string $encrypted): array
    {
        try {
            $snapshot = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new ApiException(__('USDT invoice configuration could not be verified.'), 409);
        }
        if (!is_array($snapshot)
            || (int) ($snapshot['snapshot_version'] ?? 0) !== 1
            || !is_string($snapshot['public_token'] ?? null)
            || !hash_equals(
                hash('sha256', (string) $snapshot['public_token']),
                (string) ($snapshot['public_token_hash'] ?? '')
            )) {
            throw new ApiException(__('USDT invoice configuration could not be verified.'), 409);
        }

        return $snapshot;
    }

    private static function usdtDirectCheckoutMatchesInvoice(
        UsdtDirectInvoice $invoice,
        object $checkout,
        Order $order
    ): bool {
        try {
            if (!hash_equals((string) $invoice->config_snapshot, (string) $checkout->config_snapshot)) {
                return false;
            }
            $snapshot = self::decryptUsdtDirectSnapshot((string) $invoice->config_snapshot);
        } catch (\Throwable $exception) {
            return false;
        }

        $textMatches = [
            [(string) ($checkout->provider ?? ''), 'UsdtDirect'],
            [(string) ($checkout->payment_uuid ?? ''), (string) $invoice->payment_uuid],
            [(string) ($checkout->provider_invoice_id ?? ''), (string) $invoice->public_token_hash],
            [(string) ($checkout->expected_amount ?? ''), (string) $invoice->expected_amount_raw],
            [(string) ($snapshot['payment_uuid'] ?? ''), (string) $invoice->payment_uuid],
            [(string) ($snapshot['network'] ?? ''), (string) $invoice->network],
            [(string) ($snapshot['token_contract'] ?? ''), (string) $invoice->token_contract],
            [(string) ($snapshot['receiving_address'] ?? ''), (string) $invoice->receiving_address],
            [(string) ($snapshot['expected_amount_raw'] ?? ''), (string) $invoice->expected_amount_raw],
            [(string) ($snapshot['exchange_rate'] ?? ''), (string) $invoice->exchange_rate],
            [(string) ($snapshot['public_token_hash'] ?? ''), (string) $invoice->public_token_hash],
        ];
        foreach ($textMatches as [$actual, $expected]) {
            if (!hash_equals($expected, $actual)) {
                return false;
            }
        }

        return (int) ($checkout->payment_id ?? 0) === (int) $invoice->payment_id
            && (int) ($checkout->base_amount ?? 0) === (int) $order->total_amount
            && (int) ($checkout->provider_expires_at ?? 0) === (int) $invoice->expires_at
            && (int) ($snapshot['payment_id'] ?? 0) === (int) $invoice->payment_id
            && (int) ($snapshot['required_confirmations'] ?? 0) === (int) $invoice->required_confirmations
            && (int) ($snapshot['expires_at'] ?? 0) === (int) $invoice->expires_at;
    }

    /** @param array<string, int|string|bool|null> $event */
    private static function usdtDirectTransferConflicts(
        UsdtDirectTransfer $transfer,
        array $event
    ): bool {
        $requiredText = [
            [(string) $transfer->network, (string) $event['network']],
            [(string) $transfer->token_contract, (string) $event['token_contract']],
            [(string) $transfer->txid, (string) $event['txid']],
            [(string) $transfer->to_address, (string) $event['to_address']],
            [(string) $transfer->amount_raw, (string) $event['amount_raw']],
        ];
        foreach ($requiredText as [$stored, $incoming]) {
            if (!hash_equals($stored, $incoming)) {
                return true;
            }
        }
        if ((int) $transfer->log_index !== (int) $event['log_index']
            || (int) $transfer->block_number !== (int) $event['block_number']
            || (int) $transfer->block_timestamp !== (int) $event['block_timestamp']) {
            return true;
        }

        foreach (['from_address', 'block_hash', 'raw_payload_hash'] as $field) {
            $stored = $transfer->{$field};
            $incoming = $event[$field];
            if ($stored !== null && $incoming !== null
                && !hash_equals((string) $stored, (string) $incoming)) {
                return true;
            }
        }

        $state = (string) $transfer->state;
        if ($state === UsdtDirectTransfer::STATE_MANUAL_REVIEW) {
            return true;
        }
        if ((bool) $event['successful']) {
            return $state === UsdtDirectTransfer::STATE_REVERTED;
        }

        return $state !== UsdtDirectTransfer::STATE_REVERTED;
    }

    /** @return array<string, int|string|bool|null> */
    private static function normaliseUsdtDirectEvent(array $event): array
    {
        $text = static function (mixed $value, string $field, int $maxLength): string {
            if (!is_string($value) && !is_int($value)) {
                throw new ApiException(__("USDT transfer {$field} is invalid."), 400);
            }
            $value = trim((string) $value);
            if ($value === '' || strlen($value) > $maxLength) {
                throw new ApiException(__("USDT transfer {$field} is invalid."), 400);
            }
            return $value;
        };
        $unsignedInteger = static function (mixed $value, string $field): int {
            if ((!is_string($value) && !is_int($value))
                || !preg_match('/^(?:0|[1-9][0-9]*)$/', (string) $value)
                || filter_var($value, FILTER_VALIDATE_INT) === false
                || (int) $value < 0) {
                throw new ApiException(__("USDT transfer {$field} is invalid."), 400);
            }
            return (int) $value;
        };

        $amountRaw = $text($event['amount_raw'] ?? null, 'amount', 40);
        if (!preg_match('/^[1-9][0-9]*$/', $amountRaw)) {
            throw new ApiException(__('USDT transfer amount is invalid.'), 400);
        }
        $payloadHash = $event['raw_payload_hash'] ?? null;
        if ($payloadHash !== null) {
            $payloadHash = strtolower($text($payloadHash, 'payload hash', 64));
            if (!preg_match('/^[a-f0-9]{64}$/', $payloadHash)) {
                throw new ApiException(__('USDT transfer payload hash is invalid.'), 400);
            }
        }

        $receiptResult = $event['receipt_result'] ?? null;
        $successful = array_key_exists('successful', $event)
            ? filter_var($event['successful'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            : null;
        if ($successful === null && is_string($receiptResult)) {
            $successful = strtoupper(trim($receiptResult)) === 'SUCCESS';
        }
        $solidified = false;
        foreach (['solidified', 'confirmed'] as $key) {
            if (!array_key_exists($key, $event)) {
                continue;
            }
            $value = filter_var($event[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($value === true) {
                $solidified = true;
            }
        }
        $blockTimestamp = $unsignedInteger($event['block_timestamp'] ?? null, 'block timestamp');
        if ($blockTimestamp > 9_999_999_999) {
            $blockTimestamp = intdiv($blockTimestamp, 1000);
        }

        return [
            'network' => strtolower($text($event['network'] ?? null, 'network', 16)),
            'token_contract' => $text(
                $event['token_contract'] ?? $event['contract'] ?? null,
                'contract',
                64
            ),
            'txid' => strtolower($text($event['txid'] ?? null, 'transaction ID', 128)),
            'log_index' => $unsignedInteger($event['log_index'] ?? null, 'log index'),
            'from_address' => isset($event['from_address'])
                ? $text($event['from_address'], 'sender address', 64)
                : null,
            'to_address' => $text($event['to_address'] ?? null, 'receiving address', 64),
            'amount_raw' => $amountRaw,
            'block_number' => $unsignedInteger($event['block_number'] ?? null, 'block number'),
            'block_hash' => isset($event['block_hash'])
                ? $text($event['block_hash'], 'block hash', 128)
                : null,
            'block_timestamp' => $blockTimestamp,
            'confirmations' => $unsignedInteger($event['confirmations'] ?? 0, 'confirmation count'),
            'successful' => $successful === true,
            'solidified' => $solidified,
            'raw_payload_hash' => $payloadHash,
        ];
    }

    private static function usdtDirectCallbackNo(array $event): string
    {
        return 'usdt_' . $event['network'] . ':' . $event['txid'] . ':' . $event['log_index'];
    }

    private static function persistUsdtDirectTransfer(
        ?UsdtDirectTransfer $transfer,
        array $values
    ): UsdtDirectTransfer {
        if ($transfer) {
            $transfer->fill($values)->saveOrFail();
            return $transfer;
        }

        return UsdtDirectTransfer::query()->create($values + ['created_at' => time()]);
    }

    /** @return array{invoice: UsdtDirectInvoice, order: Order, transitioned: false, manual_review: true, pending_confirmation: false, replay: false} */
    private static function manualReviewUsdtDirect(
        UsdtDirectInvoice $invoice,
        Order $order,
        ?object $checkout,
        string $reason
    ): array {
        // A confirmed invoice is immutable: fulfillment may already have
        // provisioned service. Keep later contradictions on the transfer
        // evidence instead of reopening or downgrading the paid checkout.
        if ((string) $invoice->state !== UsdtDirectInvoice::STATE_CONFIRMED) {
            $invoice->state = UsdtDirectInvoice::STATE_MANUAL_REVIEW;
            $invoice->manual_review_reason = $reason;
            $invoice->saveOrFail();
            if ($checkout) {
                DB::table(self::CHECKOUT_TABLE)->where('id', $checkout->id)->update([
                    'state' => self::CHECKOUT_UNCERTAIN,
                    'claim_token' => null,
                    'updated_at' => time(),
                ]);
            }
        }

        return [
            'invoice' => $invoice,
            'order' => $order,
            'transitioned' => false,
            'manual_review' => true,
            'pending_confirmation' => false,
            'replay' => false,
        ];
    }

    private static function closePaymentCheckouts(int $orderId): void
    {
        DB::table(self::CHECKOUT_TABLE)
            ->where('order_id', $orderId)
            ->update([
                'state' => self::CHECKOUT_CLOSED,
                'claim_token' => null,
                'response_data' => null,
                'updated_at' => time(),
            ]);
    }

    public function wasPaymentTransitioned(): bool
    {
        return $this->paymentTransitioned;
    }

    public function cancel(): bool
    {
        return $this->cancelInternal(false);
    }

    /**
     * Admin-only recovery after every active provider checkout has been
     * checked and, if necessary, cancelled or refunded manually.
     */
    public function cancelAfterManualPaymentReconciliation(): bool
    {
        return $this->cancelInternal(true);
    }

    private function cancelInternal(bool $allowUncertainCheckout): bool
    {
        $lock = Cache::lock(self::paymentCheckoutLockKey(
            (int) $this->order->user_id,
            (string) $this->order->trade_no
        ), 10);
        if (!$lock->get()) {
            throw new ApiException(__('Request failed, please try again later'));
        }

        try {
            $cancelledOrder = DB::transaction(function () use ($allowUncertainCheckout) {
                $order = $this->lockCurrentOrderWhenStatus(Order::STATUS_PENDING);
                if (!$order) {
                    return null;
                }

                $activeCheckout = DB::table(self::CHECKOUT_TABLE)
                    ->where('order_id', $order->id)
                    ->where('state', self::CHECKOUT_CREATING)
                    ->where('attempted_at', '>=', time() - self::CHECKOUT_CLAIM_TTL)
                    ->exists();
                if ($activeCheckout) {
                    return null;
                }

                // A worker that disappeared while creating an invoice leaves
                // an ambiguous provider outcome. It becomes reconcilable, but
                // only the explicit admin path may close it.
                DB::table(self::CHECKOUT_TABLE)
                    ->where('order_id', $order->id)
                    ->where('state', self::CHECKOUT_CREATING)
                    ->update([
                        'state' => self::CHECKOUT_UNCERTAIN,
                        'claim_token' => null,
                        'updated_at' => time(),
                    ]);

                $hasPayableCheckout = DB::table(self::CHECKOUT_TABLE)
                    ->where('order_id', $order->id)
                    ->whereIn('state', [self::CHECKOUT_READY, self::CHECKOUT_UNCERTAIN])
                    ->exists();
                if ($hasPayableCheckout && !$allowUncertainCheckout) {
                    throw new ApiException(__('Payment verification is still in progress. Do not retry or cancel this order. Please contact support if it does not update.'));
                }

                HookManager::call('order.cancel.before', $order);

                $order->status = Order::STATUS_CANCELLED;
                if (!$order->save()) {
                    throw new \RuntimeException('Failed to save order status.');
                }
                if ($allowUncertainCheckout && Schema::hasTable('v2_usdt_direct_invoice')) {
                    $invoice = UsdtDirectInvoice::query()
                        ->where('order_id', $order->id)
                        ->whereIn('state', [
                            UsdtDirectInvoice::STATE_AWAITING,
                            UsdtDirectInvoice::STATE_SEEN,
                            UsdtDirectInvoice::STATE_EXPIRED,
                            UsdtDirectInvoice::STATE_MANUAL_REVIEW,
                        ])
                        ->lockForUpdate()
                        ->first();
                    if ($invoice) {
                        $invoice->state = UsdtDirectInvoice::STATE_CLOSED;
                        $invoice->manual_review_reason = 'cancelled_after_manual_reconciliation';
                        $invoice->saveOrFail();
                    }
                }
                self::closePaymentCheckouts($order->id);
                if ($order->balance_amount) {
                    $userService = new UserService();
                    if (!$userService->addBalance($order->user_id, $order->balance_amount)) {
                        throw new \RuntimeException('Failed to add balance.');
                    }
                }

                return $order;
            });

            if (!$cancelledOrder) {
                return false;
            }

            $this->order = $cancelledOrder;
            HookManager::call('order.cancel.after', $cancelledOrder);
            return true;
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error($e);
            return false;
        } finally {
            $lock->release();
        }
    }

    private function setSpeedLimit($speedLimit)
    {
        $this->user->speed_limit = $speedLimit;
    }

    private function setDeviceLimit($deviceLimit)
    {
        $this->user->device_limit = $deviceLimit;
    }

    private function buyByPeriod(Order $order, Plan $plan)
    {
        // change plan process
        if ((int) $order->type === Order::TYPE_UPGRADE) {
            $this->user->expired_at = time();
        }
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        // 从一次性转换到循环或者新购的时候，重置流量
        if ($this->user->expired_at === NULL || $order->type === Order::TYPE_NEW_PURCHASE)
            app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER);
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = $this->getTime($order->period, $this->user->expired_at);
    }

    private function buyByOneTime(Plan $plan)
    {
        app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER);
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = NULL;
    }

    /**
     * 计算套餐到期时间
     * @param string $periodKey
     * @param int $timestamp
     * @return int
     * @throws ApiException
     */
    private function getTime(string $periodKey, ?int $timestamp = null): int
    {
        $timestamp = $timestamp < time() ? time() : $timestamp;
        $periodKey = PlanService::getPeriodKey($periodKey);

        if (isset(self::STR_TO_TIME[$periodKey])) {
            $months = self::STR_TO_TIME[$periodKey];
            return Carbon::createFromTimestamp($timestamp)->addMonths($months)->timestamp;
        }

        throw new ApiException(__('Invalid subscription period'));
    }

    private function openEvent($eventId)
    {
        switch ((int) $eventId) {
            case 0:
                break;
            case 1:
                app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER);
                break;
        }
    }

    protected function applyCoupon(string $couponCode): void
    {
        $couponService = new CouponService($couponCode);
        if (!$couponService->use($this->order)) {
            throw new ApiException(__('Coupon failed'));
        }
        $this->order->coupon_id = $couponService->getId();
    }

    /**
     * Summary of handleUserBalance
     * @param User $user
     * @param UserService $userService
     * @return void
     */
    protected function handleUserBalance(User $user, UserService $userService): void
    {
        $remainingBalance = $user->balance - $this->order->total_amount;

        if ($remainingBalance >= 0) {
            if (!$userService->addBalance($this->order->user_id, -$this->order->total_amount)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $this->order->balance_amount = $this->order->total_amount;
            $this->order->total_amount = 0;
        } else {
            if (!$userService->addBalance($this->order->user_id, -$user->balance)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $this->order->balance_amount = $user->balance;
            $this->order->total_amount = $this->order->total_amount - $user->balance;
        }
    }
}
