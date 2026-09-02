<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function getPaymentMethods()
    {
        $methods = [];

        $pluginMethods = PaymentService::getAllPaymentMethodNames();
        $methods = array_merge($methods, $pluginMethods);

        return $this->success(array_unique($methods));
    }

    public function fetch()
    {
        $payments = Payment::orderBy('sort', 'ASC')->get()->makeVisible('config');
        foreach ($payments as $k => $v) {
            $config = is_array($v->config) ? $v->config : [];
            try {
                $paymentService = new PaymentService($v->payment, $v->id);
                $config = $paymentService->redactPasswordConfig($config);
            } catch (\Throwable $exception) {
                // A disabled or removed plugin may no longer be constructible,
                // but its historical config must still never expose secrets.
                $config = $this->redactSensitiveConfigFallback($config);
            }
            $payments[$k]['config'] = $config;

            $notifyUrl = url("/api/v1/guest/payment/notify/{$v->payment}/{$v->uuid}");
            if ($v->notify_domain) {
                $parseUrl = parse_url($notifyUrl);
                $notifyUrl = rtrim((string) $v->notify_domain, '/') . $parseUrl['path'];
            }
            $payments[$k]['notify_url'] = $notifyUrl;
        }
        return $this->success($payments);
    }

    public function getPaymentForm(Request $request)
    {
        try {
            $paymentService = new PaymentService($request->input('payment'), $request->input('id'));
            return $this->success(collect($paymentService->form()));
        } catch (\Throwable $e) {
            return $this->fail([400, __('Payment method does not exist or is not enabled.')]);
        }
    }

    public function show(Request $request)
    {
        try {
            $updated = DB::transaction(function () use ($request): ?bool {
                $payment = Payment::whereKey($request->input('id'))->lockForUpdate()->first();
                if (!$payment) {
                    return null;
                }

                // Validate and expose the row atomically. Concurrent save()
                // uses the same row lock, so invalid credentials cannot land
                // between validation and the enable write.
                if (!$payment->enable) {
                    (new PaymentService($payment->payment, $payment->id))
                        ->validateConfiguration();
                }
                $payment->enable = !$payment->enable;
                $payment->saveOrFail();

                return true;
            });
        } catch (ApiException|\InvalidArgumentException $exception) {
            return $this->fail([400, $exception->getMessage()]);
        } catch (\Throwable $exception) {
            Log::error($exception);
            return $this->fail([500, __('Unable to save payment method.')]);
        }

        if ($updated === null) {
            return $this->fail([400202, __('Payment method does not exist.')]);
        }

        return $this->success(true);
    }

    public function save(Request $request)
    {
        if (!admin_setting('app_url')) {
            return $this->fail([400, __('Please configure the website URL in site settings.')]);
        }
        $params = $request->validate([
            'name' => 'required|string',
            'icon' => 'nullable|string',
            'payment' => 'required|string',
            'config' => 'required|array',
            'notify_domain' => 'nullable|url:https',
            'handling_fee_fixed' => 'nullable|integer',
            'handling_fee_percent' => 'nullable|numeric|between:0,100'
        ], [
            'name.required' => __('Display name is required.'),
            'payment.required' => __('Payment gateway is required.'),
            'config.required' => __('Payment configuration is required.'),
            'config.array' => __('Payment configuration must be an object.'),
            'notify_domain.url' => __('Custom notification domain is invalid.'),
            'handling_fee_fixed.integer' => __('Fixed handling fee must be an integer.'),
            'handling_fee_percent.between' => __('Percentage handling fee must be between 0 and 100.')
        ]);

        return DB::transaction(function () use ($request, $params) {
            $existingPayment = null;
            if ($request->input('id')) {
                $existingPayment = Payment::whereKey($request->input('id'))->lockForUpdate()->first();
                if (!$existingPayment) {
                    return $this->fail([400202, __('Payment method does not exist.')]);
                }
                if ($existingPayment->payment === 'CoinPayments'
                    && $params['payment'] !== 'CoinPayments'
                    && OrderService::hasActiveCoinPaymentsCheckoutForPayment((int) $existingPayment->id)) {
                    return $this->fail([409, __('CoinPayments has an active invoice. Reconcile it before changing the gateway.')]);
                }
                if ($existingPayment->payment === 'UsdtDirect'
                    && $params['payment'] !== 'UsdtDirect'
                    && OrderService::hasUsdtDirectInvoiceForPayment((int) $existingPayment->id)) {
                    return $this->fail([409, __(
                        'USDT Direct invoices are retained for settlement audit. This payment method cannot change gateways.'
                    )]);
                }
            }

            if (is_array($params['config'])) {
                $sameGateway = $existingPayment
                    && hash_equals((string) $existingPayment->payment, (string) $params['payment']);
                $existingConfig = $sameGateway && is_array($existingPayment->config)
                    ? $existingPayment->config
                    : [];
                $paymentService = null;
                try {
                    $paymentService = new PaymentService(
                        $params['payment'],
                        $sameGateway ? $existingPayment->id : null
                    );
                } catch (\Throwable $exception) {
                    // A new record or gateway change must resolve to an enabled
                    // integration. Only an unchanged historical gateway may use
                    // the compatibility path for a plugin that was later removed.
                    if (!$sameGateway) {
                        return $this->fail([400, __('Payment method does not exist or is not enabled.')]);
                    }
                    // Preserve compatibility with disabled/removed third-party
                    // plugins while retaining obvious historical secrets.
                    $params['config'] = $this->preserveSensitiveConfigFallback(
                        $params['config'],
                        $existingConfig
                    );
                }
                if ($paymentService instanceof PaymentService) {
                    try {
                        $submittedConfig = $paymentService->onlyKnownConfigFields($params['config']);
                        // Validate the raw known fields before blank password
                        // values are replaced by stored secrets. Otherwise a
                        // crafted array/object secret is indistinguishable from
                        // the intentional empty-string "keep current" marker.
                        $paymentService->validateConfigurationShape($submittedConfig);
                        $existingConfig = $paymentService->onlyKnownConfigFields($existingConfig);
                        $params['config'] = $paymentService->preserveBlankPasswords(
                            $submittedConfig,
                            $existingConfig
                        );
                        if ($existingPayment?->enable) {
                            $paymentService->validateConfiguration($params['config']);
                        }
                    } catch (\Throwable $exception) {
                        return $this->fail([400, $exception->getMessage()]);
                    }
                }
            }

            if ($existingPayment) {
                try {
                    $existingPayment->updateOrFail($params);
                } catch (\Throwable $exception) {
                    Log::error($exception);
                    return $this->fail([500, __('Unable to save payment method.')]);
                }
                return $this->success(true);
            }
            $params['uuid'] = Helper::randomChar(8);
            if (!Payment::create($params)) {
                return $this->fail([500, __('Unable to save payment method.')]);
            }
            return $this->success(true);
        });
    }

    private function redactSensitiveConfigFallback(array $config): array
    {
        foreach ($config as $key => $value) {
            if ($this->isSensitiveConfigKey((string) $key)) {
                $config[$key] = '';
            }
        }

        return $config;
    }

    private function preserveSensitiveConfigFallback(array $submitted, array $existing): array
    {
        foreach (array_unique(array_merge(array_keys($submitted), array_keys($existing))) as $key) {
            if (!$this->isSensitiveConfigKey((string) $key)) {
                continue;
            }
            $value = $submitted[$key] ?? null;
            if ((is_scalar($value) || $value === null) && trim((string) $value) !== '') {
                continue;
            }
            $existingValue = $existing[$key] ?? null;
            if ((is_scalar($existingValue) || $existingValue === null)
                && trim((string) $existingValue) !== '') {
                $submitted[$key] = $existingValue;
            } else {
                unset($submitted[$key]);
            }
        }

        return $submitted;
    }

    private function isSensitiveConfigKey(string $key): bool
    {
        return PaymentService::isSensitiveConfigKey($key);
    }

    public function drop(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $payment = Payment::whereKey($request->input('id'))->lockForUpdate()->first();
            if (!$payment) {
                return $this->fail([400202, __('Payment method does not exist.')]);
            }
            if ($payment->payment === 'CoinPayments'
                && OrderService::hasActiveCoinPaymentsCheckoutForPayment((int) $payment->id)) {
                return $this->fail([409, __('CoinPayments has an active invoice. Reconcile it before deleting the payment method.')]);
            }
            if ($payment->payment === 'UsdtDirect'
                && OrderService::hasUsdtDirectInvoiceForPayment((int) $payment->id)) {
                return $this->fail([409, __(
                    'USDT Direct invoices are retained for settlement audit. This payment method cannot be deleted.'
                )]);
            }

            return $this->success($payment->delete());
        });
    }


    public function sort(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|integer|distinct'
        ], [
            'ids.required' => __('Sorting list is required.'),
            'ids.array' => __('Sorting list is invalid.'),
            'ids.*.required' => __('Sorting list is invalid.'),
            'ids.*.integer' => __('Sorting list is invalid.'),
            'ids.*.distinct' => __('Sorting list is invalid.')
        ]);
        try {
            DB::beginTransaction();
            foreach ($request->input('ids') as $k => $v) {
                $payment = Payment::find($v);
                if (!$payment || !$payment->update(['sort' => $k + 1])) {
                    throw new \RuntimeException();
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->fail([500, __('Unable to save payment method order.')]);
        }

        return $this->success(true);
    }
}
