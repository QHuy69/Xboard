<?php

namespace App\Http\Controllers\V1\Guest;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\Plugin\HookManager;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        HookManager::call('payment.notify.before', [$method, $uuid, $request]);
        try {
            $paymentService = new PaymentService(
                $method,
                null,
                $uuid,
                $method === 'CoinPayments'
            );
            $verify = $paymentService->notify($request->input());
            if (!$verify) {
                HookManager::call('payment.notify.failed', [$method, $uuid, $request]);
                return $this->fail([422, __('Payment notification verification failed.')]);
            }
            // Signed, non-terminal payment progress callbacks must receive a
            // successful acknowledgement without being treated as an order.
            if (is_string($verify)) {
                return $verify;
            }
            // Some authenticated webhook events are intentionally ignored but
            // still require a provider-specific JSON acknowledgement. Return
            // that response before verified/success hooks or order handling so
            // an acknowledgement-only event cannot trigger payment effects.
            if (is_array($verify)
                && ($verify['ack_only'] ?? false) === true
                && array_key_exists('custom_result', $verify)) {
                return $verify['custom_result'];
            }
            HookManager::call('payment.notify.verified', $verify);
            if ($method === 'CoinPayments' && isset($verify['event'])) {
                $outcome = OrderService::handleCoinPaymentsNotification($verify);
                if ($outcome['transitioned']) {
                    HookManager::call('payment.notify.success', $outcome['order']);
                }
                return (isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
            }
            if (!$this->handle($verify['trade_no'], $verify['callback_no'])) {
                return $this->fail([400, __('Unable to process payment notification.')]);
            }
            return (isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (ApiException $e) {
            // Invalid signatures, stale timestamps and malformed payment
            // references are client errors. Returning 500 asks providers to
            // retry a callback that can never succeed and hides the true
            // operational status from monitoring.
            $status = (int) $e->getCode();
            if ($status < 400 || $status > 499) {
                $status = 400;
            }
            Log::warning('Payment notification rejected.', [
                'method' => $method,
                'uuid' => $uuid,
                'status' => $status,
                'reason' => $e->getMessage(),
            ]);
            return $this->fail([$status, __('Payment gateway request failed')]);
        } catch (\JsonException $e) {
            Log::warning('Payment notification contained invalid JSON.', [
                'method' => $method,
                'uuid' => $uuid,
            ]);
            return $this->fail([400, __('Payment gateway request failed')]);
        } catch (\Throwable $e) {
            Log::error($e);
            return $this->fail([500, __('Payment notification could not be processed.')]);
        }
    }

    private function handle($tradeNo, $callbackNo): bool
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            return false;
        }
        if ((int) $order->status !== Order::STATUS_PENDING)
            return true;
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }

        // A replay or a concurrent duplicate is a successful no-op. Only the
        // request that atomically moved Pending -> Processing may emit external
        // success side effects such as Telegram notifications.
        if (!$orderService->wasPaymentTransitioned()) {
            return true;
        }

        HookManager::call('payment.notify.success', $orderService->order);
        return true;
    }
}
