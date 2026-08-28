<?php

namespace Plugin\CoinPayments;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Models\Order;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['CoinPayments'] = [
                    'name' => $this->getConfig('display_name', 'CoinPayments'),
                    'icon' => $this->getConfig('icon', '💰'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin'
                ];
            }
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'coinpayments_merchant_id' => [
                'label' => 'Merchant ID',
                'type' => 'string',
                'required' => true,
                'description' => '商户 ID，填写您在 Account Settings 中得到的 ID'
            ],
            'coinpayments_ipn_secret' => [
                'label' => 'IPN Secret',
                'type' => 'string',
                'required' => true,
                'description' => '通知密钥，填写您在 Merchant Settings 中自行设置的值'
            ],
            'coinpayments_currency' => [
                'label' => '货币代码',
                'type' => 'string',
                'required' => true,
                'default' => 'USDT.TRC20',
                'description' => '填写您的货币代码（大写），例如 USDT.TRC20'
            ],
            'coinpayments_cny_usdt_rate' => [
                'label' => 'CNY → USDT 汇率',
                'type' => 'string',
                'required' => true,
                'description' => '手动维护汇率：1 CNY 等于多少 USDT。订单金额和手续费会先按 CNY 计算，再换算成 USDT。'
            ]
        ];
    }

    public function pay($order): array
    {
        $parseUrl = parse_url($order['return_url']);
        $port = isset($parseUrl['port']) ? ":{$parseUrl['port']}" : '';
        $successUrl = "{$parseUrl['scheme']}://{$parseUrl['host']}{$port}";

        $rate = (float) $this->getConfig('coinpayments_cny_usdt_rate', 0);
        if ($rate <= 0) {
            throw new ApiException('CoinPayments CNY to USDT exchange rate is not configured');
        }
        $currency = strtoupper(trim((string) $this->getConfig('coinpayments_currency', 'USDT.TRC20')));
        if ($currency === '') {
            throw new ApiException('CoinPayments currency is not configured');
        }

        $amountUsdt = ($order['total_amount'] / 100) * $rate;
        $params = [
            'cmd' => '_pay_simple',
            'reset' => 1,
            'merchant' => $this->getConfig('coinpayments_merchant_id'),
            'item_name' => $order['trade_no'],
            'item_number' => $order['trade_no'],
            'want_shipping' => 0,
            'currency' => $currency,
            // Keep six decimals for USDT so small CNY orders are not rounded away.
            'amountf' => number_format($amountUsdt, 6, '.', ''),
            'success_url' => $successUrl,
            'cancel_url' => $order['return_url'],
            'ipn_url' => $order['notify_url']
        ];

        $params_string = http_build_query($params);

        return [
            'type' => 1,
            'data' => 'https://www.coinpayments.net/index.php?' . $params_string
        ];
    }

    public function notify($params): array|string
    {
        if (!isset($params['merchant']) || $params['merchant'] != trim($this->getConfig('coinpayments_merchant_id'))) {
            throw new ApiException('No or incorrect Merchant ID passed');
        }

        $headers = getallheaders();

        ksort($params);
        reset($params);
        $request = stripslashes(http_build_query($params));

        $headerName = 'Hmac';
        $signHeader = isset($headers[$headerName]) ? $headers[$headerName] : '';

        $hmac = hash_hmac("sha512", $request, trim($this->getConfig('coinpayments_ipn_secret')));

        if (!hash_equals($hmac, $signHeader)) {
            throw new ApiException('HMAC signature does not match', 400);
        }

        $status = (int) ($params['status'] ?? 0);
        if ($status >= 100 || $status == 2) {
            $tradeNo = trim((string) ($params['item_number'] ?? ''));
            $txnId = trim((string) ($params['txn_id'] ?? ''));
            if ($tradeNo === '' || $txnId === '') {
                throw new ApiException('Invalid CoinPayments callback payload', 400);
            }

            $configuredCurrency = strtoupper(trim((string) $this->getConfig('coinpayments_currency', 'USDT.TRC20')));
            $callbackCurrency = strtoupper(trim((string) ($params['currency1'] ?? $params['currency'] ?? $configuredCurrency)));
            if ($callbackCurrency !== $configuredCurrency) {
                throw new ApiException('CoinPayments currency does not match', 400);
            }

            $rate = (float) $this->getConfig('coinpayments_cny_usdt_rate', 0);
            if ($rate <= 0) {
                throw new ApiException('CoinPayments CNY to USDT exchange rate is not configured', 400);
            }
            $order = Order::where('trade_no', $tradeNo)->first();
            if (!$order) {
                throw new ApiException('Order does not exist', 400);
            }
            $expected = (($order->total_amount + (int) ($order->handling_amount ?? 0)) / 100) * $rate;
            $requested = (float) ($params['amount1'] ?? 0);
            $received = (float) ($params['amount2'] ?? $requested);
            // amount1 is the amount requested by the invoice; amount2 is what arrived.
            // Allow a tiny rounding tolerance, but never accept an underpayment.
            if ($requested + 0.000001 < $expected || $received + 0.000001 < $expected) {
                throw new ApiException('CoinPayments payment amount is insufficient', 400);
            }
            return [
                'trade_no' => $tradeNo,
                'callback_no' => $txnId,
                'custom_result' => 'IPN OK'
            ];
        } else if ($status < 0) {
            throw new ApiException('Payment Timed Out or Error');
        } else {
            return 'IPN OK: pending';
        }
    }
}
