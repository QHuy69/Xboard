<?php

namespace Plugin\Sepay;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Services\Plugin\AbstractPlugin;

/**
 * SePay Vietcombank payment integration.
 *
 * Orders are priced in CNY by XBoard. The manually maintained CNY -> VND
 * rate is used to create a VietQR image. SePay's incoming-transfer webhook
 * then verifies the transfer and settles the matching order automatically.
 */
class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['SePay'] = [
                    'name' => $this->getConfig('display_name', 'SePay (Vietcombank)'),
                    'icon' => $this->getConfig('icon', '🏦'),
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
            'sepay_account_number' => [
                'label' => 'Vietcombank account number',
                'type' => 'string',
                'required' => true,
                'description' => 'The Vietcombank account that receives customer transfers.'
            ],
            'sepay_account_name' => [
                'label' => 'Account holder name',
                'type' => 'string',
                'required' => true,
                'description' => 'Account holder name shown with the QR payment details.'
            ],
            'sepay_bank_code' => [
                'label' => 'Bank code',
                'type' => 'string',
                'required' => true,
                'default' => 'Vietcombank',
                'description' => 'SePay gateway/bank name. Keep Vietcombank for VCB transfers.'
            ],
            'sepay_api_key' => [
                'label' => 'SePay webhook API key',
                'type' => 'string',
                'required' => true,
                'description' => 'The key SePay sends as Authorization: Apikey <key>.'
            ],
            'sepay_cny_vnd_rate' => [
                'label' => 'CNY → VND exchange rate',
                'type' => 'string',
                'required' => true,
                'description' => 'Manual rate: how many VND equal 1 CNY. Update this when needed.'
            ],
            'sepay_transfer_prefix' => [
                'label' => 'Transfer description prefix',
                'type' => 'string',
                'required' => true,
                'default' => 'XBOARD',
                'description' => 'Customers must keep this prefix and the order number in the transfer description.'
            ]
        ];
    }

    public function pay($order): array
    {
        $account = trim((string) $this->getConfig('sepay_account_number', ''));
        $accountName = trim((string) $this->getConfig('sepay_account_name', ''));
        $bank = trim((string) $this->getConfig('sepay_bank_code', 'Vietcombank'));
        $rate = (float) $this->getConfig('sepay_cny_vnd_rate', 0);
        $prefix = trim((string) $this->getConfig('sepay_transfer_prefix', 'XBOARD'));

        if ($account === '' || $accountName === '' || $bank === '' || $prefix === '') {
            throw new ApiException('SePay bank account settings are incomplete');
        }
        if ($rate <= 0) {
            throw new ApiException('SePay CNY to VND exchange rate is not configured');
        }

        $amountVnd = (int) round(((int) $order['total_amount'] / 100) * $rate);
        if ($amountVnd < 1) {
            throw new ApiException('SePay payment amount is invalid');
        }

        $description = $prefix . ' ' . $order['trade_no'];
        $qrUrl = 'https://vietqr.app/img?' . http_build_query([
            'acc' => $account,
            'bank' => $bank,
            'amount' => $amountVnd,
            'des' => $description,
            'template' => 'compact',
            'showinfo' => 'true',
            'holder' => $accountName
        ]);

        return [
            'type' => 0,
            'data' => $qrUrl
        ];
    }

    public function notify($params): array|string
    {
        $this->verifyWebhookAuthorization();

        if (strtolower(trim((string) ($params['transferType'] ?? ''))) !== 'in') {
            return 'ignored';
        }

        $configuredBank = $this->normalise((string) $this->getConfig('sepay_bank_code', 'Vietcombank'));
        $gateway = $this->normalise((string) ($params['gateway'] ?? ''));
        if ($configuredBank !== '' && $gateway !== '' && $configuredBank !== $gateway) {
            throw new ApiException('SePay transfer bank does not match', 400);
        }

        $tradeNo = $this->extractTradeNo($params);
        if ($tradeNo === '') {
            throw new ApiException('Order number not found in SePay transfer description', 400);
        }

        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            throw new ApiException('Order does not exist', 400);
        }

        $rate = (float) $this->getConfig('sepay_cny_vnd_rate', 0);
        if ($rate <= 0) {
            throw new ApiException('SePay CNY to VND exchange rate is not configured', 400);
        }
        $expected = (int) round((($order->total_amount + (int) ($order->handling_amount ?? 0)) / 100) * $rate);
        $received = (int) round((float) ($params['transferAmount'] ?? 0));
        if ($received < $expected) {
            throw new ApiException('SePay transfer amount is insufficient', 400);
        }

        $callbackNo = trim((string) ($params['referenceCode'] ?? $params['id'] ?? ''));
        if ($callbackNo === '') {
            throw new ApiException('SePay callback reference is missing', 400);
        }

        return [
            'trade_no' => $tradeNo,
            'callback_no' => $callbackNo,
            'custom_result' => 'success'
        ];
    }

    private function verifyWebhookAuthorization(): void
    {
        $expected = trim((string) $this->getConfig('sepay_api_key', ''));
        if ($expected === '') {
            throw new ApiException('SePay webhook API key is not configured', 400);
        }

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $provided = '';
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'authorization') {
                $provided = trim((string) $value);
                break;
            }
        }
        if (stripos($provided, 'apikey ') === 0) {
            $provided = trim(substr($provided, 7));
        }
        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new ApiException('SePay webhook authorization failed', 401);
        }
    }

    private function extractTradeNo(array $params): string
    {
        $prefix = trim((string) $this->getConfig('sepay_transfer_prefix', 'XBOARD'));
        $values = [
            $params['code'] ?? '',
            $params['content'] ?? '',
            $params['description'] ?? '',
            $params['transferDescription'] ?? ''
        ];
        $pattern = '/'.preg_quote($prefix, '/').'\s*([A-Za-z0-9][A-Za-z0-9_-]*)/i';
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if (preg_match($pattern, $value, $matches)) {
                return $matches[1];
            }
        }
        return '';
    }

    private function normalise(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', trim($value)));
    }
}
