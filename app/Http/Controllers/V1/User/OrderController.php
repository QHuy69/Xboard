<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\OrderSave;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PlanService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function fetch(Request $request)
    {
        $request->validate([
            'status' => 'nullable|integer|in:0,1,2,3',
        ]);
        $orders = Order::with('plan')
            ->where('user_id', $request->user()->id)
            ->when($request->input('status') !== null, function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->orderBy('created_at', 'DESC')
            ->get();

        return $this->success(OrderResource::collection($orders));
    }

    public function detail(Request $request)
    {
        $request->validate([
            'trade_no' => 'required|string',
        ]);
        $order = Order::with(['payment', 'plan'])
            ->where('user_id', $request->user()->id)
            ->where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist or has been paid')]);
        }
        $order['try_out_plan_id'] = (int) admin_setting('try_out_plan_id');
        if (!$order->plan) {
            return $this->fail([400, __('Subscription plan does not exist')]);
        }
        if ($order->surplus_order_ids) {
            $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        }
        return $this->success(OrderResource::make($order));
    }

    public function save(OrderSave $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:App\Models\Plan,id',
            'period' => 'required|string'
        ]);

        $user = User::findOrFail($request->user()->id);
        $userService = app(UserService::class);

        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            throw new ApiException(__('You have an unpaid or pending order, please try again later or cancel it'));
        }

        $plan = Plan::findOrFail($request->input('plan_id'));
        $planService = new PlanService($plan);

        $planService->validatePurchase($user, $request->input('period'));

        $order = OrderService::createFromRequest(
            $user,
            $plan,
            $request->input('period'),
            $request->input('coupon_code')
        );

        return $this->success($order->trade_no);
    }

    protected function applyCoupon(Order $order, string $couponCode): void
    {
        $couponService = new CouponService($couponCode);
        if (!$couponService->use($order)) {
            throw new ApiException(__('Coupon failed'));
        }
        $order->coupon_id = $couponService->getId();
    }

    protected function handleUserBalance(Order $order, User $user, UserService $userService): void
    {
        $remainingBalance = $user->balance - $order->total_amount;

        if ($remainingBalance > 0) {
            if (!$userService->addBalance($order->user_id, -$order->total_amount)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $order->balance_amount = $order->total_amount;
            $order->total_amount = 0;
        } else {
            if (!$userService->addBalance($order->user_id, -$user->balance)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $order->balance_amount = $user->balance;
            $order->total_amount = $order->total_amount - $user->balance;
        }
    }

    public function checkout(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $method = $request->input('method');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->where('status', 0)
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist or has been paid')]);
        }
        if ($order->total_amount < 0) {
            return $this->fail([400, __('Order amount is invalid')]);
        }
        // free process
        if ($order->total_amount <= 0) {
            $orderService = new OrderService($order);
            if (!$orderService->paid($order->trade_no))
                return $this->fail([400, __('Payment failed')]);
            return response([
                'type' => -1,
                'data' => true
            ]);
        }
        $payment = Payment::find($method);
        if (!$payment || !$payment->enable) {
            return $this->fail([400, __('Payment method is not available')]);
        }

        // A database row can outlive a disabled/removed plugin. Resolve every
        // gateway before mutating the order so customers receive a controlled
        // availability error rather than an undefined-class/network error.
        try {
            $paymentService = new PaymentService($payment->payment, $payment->id);
            if ($payment->payment === 'CoinPayments') {
                $paymentService->validateConfiguration();
            }
        } catch (\Throwable $exception) {
            return $this->fail([400, __('Payment method is not available')]);
        }

        if ($payment->payment === 'CoinPayments') {
            $checkout = OrderService::beginCoinPaymentsCheckout(
                (int) $request->user()->id,
                (string) $tradeNo,
                $payment
            );
            if ($checkout['cached']) {
                return response([
                    'type' => $checkout['type'],
                    'data' => $checkout['data'],
                ]);
            }

            try {
                // The locked claim freezes credentials, exchange rate and
                // callback URL. Later admin edits must affect only future
                // invoices, never this already-started provider request.
                $paymentService = new PaymentService($payment->payment, $payment->id, null, true);
                $paymentService->useCoinPaymentsConfigurationSnapshot(
                    $checkout['configuration_snapshot']
                );
                $result = $paymentService->pay([
                    'trade_no' => $tradeNo,
                    'total_amount' => $checkout['amount'],
                    'user_id' => $checkout['order']->user_id,
                    'stripe_token' => $request->input('token'),
                ]);
                OrderService::completeCoinPaymentsCheckout(
                    (int) $checkout['order']->id,
                    (int) $payment->id,
                    (string) $checkout['claim_token'],
                    $result
                );
            } catch (\Throwable $exception) {
                // 5xx/transport/malformed-success failures may occur after the
                // provider created an invoice. Keep them blocked as uncertain;
                // only a known 4xx/local validation failure may be tried again.
                $status = (int) $exception->getCode();
                $ambiguous = !($exception instanceof ApiException)
                    || $status < 400
                    || $status >= 500;
                OrderService::failCoinPaymentsCheckout(
                    (int) $checkout['order']->id,
                    (int) $payment->id,
                    (string) $checkout['claim_token'],
                    $ambiguous
                );
                throw $exception;
            }

            return response([
                'type' => $result['type'],
                'data' => $result['data'],
            ]);
        }

        $checkout = OrderService::beginStandardPaymentCheckout(
            (int) $request->user()->id,
            (string) $tradeNo,
            $payment
        );
        $order = $checkout['order'];
        $payment = $checkout['payment'];
        if ($checkout['cached']) {
            return response([
                'type' => $checkout['type'],
                'data' => $checkout['data'],
            ]);
        }

        try {
            $paymentService = new PaymentService($payment->payment, $payment->id);
            $result = $paymentService->pay([
                'trade_no' => $tradeNo,
                'total_amount' => $checkout['amount'],
                'user_id' => $order->user_id,
                'stripe_token' => $request->input('token')
            ]);
        } catch (\Throwable $exception) {
            $status = (int) $exception->getCode();
            $ambiguous = !($exception instanceof ApiException)
                || $status < 400
                || $status >= 500;
            OrderService::failStandardPaymentCheckout(
                (int) $order->id,
                (int) $payment->id,
                (string) $checkout['claim_token'],
                $ambiguous
            );
            throw $exception;
        }

        try {
            OrderService::completeStandardPaymentCheckout(
                (int) $order->id,
                (int) $payment->id,
                (string) $checkout['claim_token'],
                $result
            );
        } catch (\Throwable $exception) {
            // The provider already returned a payable result. Any local
            // persistence failure is ambiguous regardless of its exception
            // type and must keep the order locked for reconciliation.
            OrderService::failStandardPaymentCheckout(
                (int) $order->id,
                (int) $payment->id,
                (string) $checkout['claim_token'],
                true
            );
            throw $exception;
        }
        return response([
            'type' => $result['type'],
            'data' => $result['data']
        ]);
    }

    public function check(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist')]);
        }
        return $this->success($order->status);
    }

    public function getPaymentMethod()
    {
        $methods = Payment::select([
            'id',
            'name',
            'payment',
            'icon',
            'handling_fee_fixed',
            'handling_fee_percent'
        ])
            ->where('enable', 1)
            ->orderBy('sort', 'ASC')
            ->get()
            ->filter(function (Payment $payment): bool {
                try {
                    $paymentService = new PaymentService($payment->payment, $payment->id);
                    if ($payment->payment === 'CoinPayments') {
                        $paymentService->validateConfiguration();
                    }
                    return true;
                } catch (\Throwable $exception) {
                    return false;
                }
            })
            ->values();

        return $this->success($methods);
    }

    public function cancel(Request $request)
    {
        if (empty($request->input('trade_no'))) {
            return $this->fail([422, __('Invalid parameter')]);
        }
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist')]);
        }
        if ($order->status !== 0) {
            return $this->fail([400, __('You can only cancel pending orders')]);
        }
        $orderService = new OrderService($order);
        if (!$orderService->cancel()) {
            return $this->fail([400, __('Cancel failed')]);
        }
        return $this->success(true);
    }
}
