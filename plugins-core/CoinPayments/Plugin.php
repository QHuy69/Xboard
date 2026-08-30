<?php

namespace Plugin\CoinPayments;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Http;

/** CoinPayments Invoice API v2 integration. */
class Plugin extends AbstractPlugin implements PaymentInterface
{
    private const DEFAULT_API_BASE = 'https://a-api.coinpayments.net';

    public function boot(): void
    {
        $this->filter('available_payment_methods', function (array $methods): array {
            if ($this->isConfiguredAndEnabled()) {
                $methods['CoinPayments'] = [
                    'name' => $this->getConfig('display_name', 'CoinPayments'),
                    'icon' => $this->getConfig('icon', '💰'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin',
                ];
            }
            return $methods;
        });
    }

    private function isConfiguredAndEnabled(): bool
    {
        $enabled = $this->getConfig('enabled', true);
        if (!is_bool($enabled)) {
            $enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }

        return $enabled
            && trim((string) $this->getConfig('coinpayments_client_id', '')) !== ''
            && trim((string) $this->getConfig('coinpayments_client_secret', '')) !== ''
            && trim((string) $this->getConfig('coinpayments_invoice_currency', '')) !== ''
            && trim((string) $this->getConfig('coinpayments_invoice_currency_id', '')) !== ''
            && (float) $this->getConfig('coinpayments_cny_invoice_rate', 0) > 0;
    }

    public function form(): array
    {
        return [
            'coinpayments_client_id' => [
                'label' => 'Integration Client ID', 'type' => 'string', 'required' => true,
                'description' => 'Client ID của API Integration mới trên CoinPayments.',
            ],
            'coinpayments_client_secret' => [
                'label' => 'Integration Client Secret', 'type' => 'password', 'required' => true,
                'description' => 'Secret dùng ký HMAC-SHA256. Không chia sẻ hoặc gửi secret này qua webhook.',
            ],
            'coinpayments_invoice_currency' => [
                'label' => 'Invoice currency', 'type' => 'string', 'required' => true, 'default' => 'USD',
                'description' => 'Mã tiền dùng lập hóa đơn, ví dụ USD.',
            ],
            'coinpayments_invoice_currency_id' => [
                'label' => 'Invoice currency ID', 'type' => 'string', 'required' => true,
                'description' => 'ID loại tiền CoinPayments trả về trong webhook, dùng để chống callback sai loại tiền.',
            ],
            'coinpayments_payment_currency' => [
                'label' => 'Preferred payment currency', 'type' => 'string', 'required' => false, 'default' => '',
                'description' => 'Tùy chọn. Để trống cho khách tự chọn loại tiền thanh toán.',
            ],
            'coinpayments_cny_invoice_rate' => [
                'label' => 'CNY → invoice currency rate', 'type' => 'string', 'required' => true,
                'description' => 'Tỷ giá thủ công: 1 CNY bằng bao nhiêu đơn vị tiền hóa đơn.',
            ],
            'coinpayments_api_base' => [
                'label' => 'API base URL', 'type' => 'string', 'required' => false, 'default' => self::DEFAULT_API_BASE,
                'description' => 'Giữ mặc định trừ khi CoinPayments cấp endpoint khác.',
            ],
            'coinpayments_webhook_url' => [
                'label' => 'Exact webhook URL', 'type' => 'string', 'required' => false, 'default' => '',
                'description' => 'URL callback public chính xác; nên đặt khi chạy sau Cloudflare/reverse proxy.',
            ],
            'coinpayments_webhook_max_age' => [
                'label' => 'Webhook max age (seconds)', 'type' => 'number', 'required' => false, 'default' => 300,
                'description' => 'Giới hạn chống phát lại webhook. Khuyến nghị 300 giây.',
            ],
        ];
    }

    public function pay($order): array
    {
        $clientId = $this->requiredConfig('coinpayments_client_id', 'CoinPayments Client ID is not configured');
        $secret = $this->requiredConfig('coinpayments_client_secret', 'CoinPayments Client Secret is not configured');
        $invoiceCurrency = strtoupper($this->requiredConfig('coinpayments_invoice_currency', 'CoinPayments invoice currency is not configured'));
        $rate = (float) $this->getConfig('coinpayments_cny_invoice_rate', 0);
        if ($rate <= 0) {
            throw new ApiException('CoinPayments CNY exchange rate is not configured');
        }

        $amount = number_format(((int) $order['total_amount'] / 100) * $rate, 8, '.', '');
        if ((float) $amount <= 0) {
            throw new ApiException('CoinPayments invoice amount is invalid');
        }

        $user = User::find((int) $order['user_id']);
        if (!$user || !filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('CoinPayments buyer email is invalid');
        }

        $apiBase = rtrim((string) $this->getConfig('coinpayments_api_base', self::DEFAULT_API_BASE), '/');
        if (!filter_var($apiBase, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($apiBase), 'https://')) {
            throw new ApiException('CoinPayments API URL is invalid');
        }
        $url = $apiBase . '/api/v2/merchant/invoices';
        $notifyUrl = trim((string) $this->getConfig('coinpayments_webhook_url', '')) ?: (string) $order['notify_url'];
        $paymentCurrency = trim((string) $this->getConfig('coinpayments_payment_currency', ''));

        $payload = [
            'currency' => $invoiceCurrency,
            'items' => [[
                'customId' => (string) $order['trade_no'],
                'name' => 'XBoard order ' . $order['trade_no'],
                'quantity' => ['value' => 1, 'type' => 'quantity'],
                'amount' => $amount,
            ]],
            'amount' => ['breakdown' => ['subtotal' => $amount], 'total' => $amount],
            'isEmailDelivery' => false,
            'invoiceId' => (string) $order['trade_no'],
            'buyer' => ['emailAddress' => $user->email],
            'description' => 'XBoard order ' . $order['trade_no'],
            'customData' => ['trade_no' => (string) $order['trade_no']],
            'metadata' => ['integration' => 'XBoard CoinPayments v2'],
            'webhooks' => [[
                'notificationsUrl' => $notifyUrl,
                'notifications' => ['invoiceCompleted'],
            ]],
            'payment' => array_filter([
                'paymentCurrency' => $paymentCurrency !== '' ? $paymentCurrency : null,
                'refundEmail' => $user->email,
                'hideShoppingCart' => true,
                'successUrl' => (string) $order['return_url'],
                'cancelUrl' => (string) $order['return_url'],
            ], static fn ($value) => $value !== null),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = gmdate('Y-m-d\TH:i:s');
        $signature = self::signature('POST', $url, $clientId, $timestamp, $json, $secret);

        $response = Http::timeout(30)->retry(2, 500)->withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-CoinPayments-Client' => $clientId,
            'X-CoinPayments-Timestamp' => $timestamp,
            'X-CoinPayments-Signature' => $signature,
        ])->withBody($json, 'application/json')->post($url);

        if (!$response->successful()) {
            throw new ApiException('CoinPayments could not create the invoice (HTTP ' . $response->status() . ')');
        }

        $invoice = data_get($response->json(), 'invoices.0');
        $checkoutUrl = is_array($invoice) ? ($invoice['checkoutLink'] ?? $invoice['link'] ?? null) : null;
        if (!is_string($checkoutUrl) || !filter_var($checkoutUrl, FILTER_VALIDATE_URL)) {
            throw new ApiException('CoinPayments returned an invalid checkout link');
        }
        return ['type' => 1, 'data' => $checkoutUrl];
    }

    public function notify($params): array|string
    {
        $request = request();
        $rawBody = (string) $request->getContent();
        $clientId = trim((string) $request->header('X-CoinPayments-Client', ''));
        $timestamp = trim((string) $request->header('X-CoinPayments-Timestamp', ''));
        $providedSignature = trim((string) $request->header('X-CoinPayments-Signature', ''));
        $expectedClientId = $this->requiredConfig('coinpayments_client_id', 'CoinPayments Client ID is not configured');
        $secret = $this->requiredConfig('coinpayments_client_secret', 'CoinPayments Client Secret is not configured');

        if ($clientId === '' || !hash_equals($expectedClientId, $clientId) || $timestamp === '' || $providedSignature === '') {
            throw new ApiException('CoinPayments webhook authentication failed', 400);
        }
        $parsedTimestamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $timestamp, new DateTimeZone('UTC'));
        $maxAge = max(60, min(900, (int) $this->getConfig('coinpayments_webhook_max_age', 300)));
        if (!$parsedTimestamp || abs(time() - $parsedTimestamp->getTimestamp()) > $maxAge) {
            throw new ApiException('CoinPayments webhook timestamp is stale', 400);
        }

        $configuredUrl = trim((string) $this->getConfig('coinpayments_webhook_url', ''));
        $signedUrl = $configuredUrl !== '' ? $configuredUrl : $request->fullUrl();
        $expectedSignature = self::signature($request->method(), $signedUrl, $clientId, $timestamp, $rawBody, $secret);
        if (!hash_equals($expectedSignature, $providedSignature)) {
            throw new ApiException('CoinPayments webhook signature does not match', 400);
        }

        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        $eventType = strtolower((string) ($payload['type'] ?? ''));
        $invoiceState = strtolower((string) data_get($payload, 'invoice.state', ''));
        if ($eventType !== 'invoicecompleted' || $invoiceState !== 'completed') {
            return 'CoinPayments webhook accepted: pending';
        }

        $tradeNo = trim((string) (data_get($payload, 'invoice.customData.trade_no')
            ?: data_get($payload, 'invoice.invoiceId')
            ?: data_get($payload, 'invoice.invoiceNumber')));
        $callbackNo = trim((string) ($payload['id'] ?? data_get($payload, 'invoice.id', '')));
        if ($tradeNo === '' || $callbackNo === '') {
            throw new ApiException('CoinPayments webhook is missing the order reference', 400);
        }

        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            throw new ApiException('Order does not exist', 400);
        }
        $expectedCurrencyId = trim((string) $this->getConfig('coinpayments_invoice_currency_id', ''));
        $receivedCurrencyId = trim((string) data_get($payload, 'invoice.amount.currencyId', ''));
        if ($expectedCurrencyId === '' || $receivedCurrencyId === '' || !hash_equals($expectedCurrencyId, $receivedCurrencyId)) {
            throw new ApiException('CoinPayments invoice currency does not match', 400);
        }

        $rate = (float) $this->getConfig('coinpayments_cny_invoice_rate', 0);
        $expectedAmount = (($order->total_amount + (int) ($order->handling_amount ?? 0)) / 100) * $rate;
        $receivedAmount = (float) data_get($payload, 'invoice.amount.total', 0);
        if ($rate <= 0 || $receivedAmount + 0.000001 < $expectedAmount) {
            throw new ApiException('CoinPayments invoice amount is insufficient', 400);
        }

        return ['trade_no' => $tradeNo, 'callback_no' => $callbackNo, 'custom_result' => 'success'];
    }

    public static function signature(string $method, string $url, string $clientId, string $timestamp, string $payload, string $secret): string
    {
        $canonical = "\xEF\xBB\xBF" . strtoupper($method) . $url . $clientId . $timestamp . $payload;
        return base64_encode(hash_hmac('sha256', $canonical, $secret, true));
    }

    private function requiredConfig(string $key, string $error): string
    {
        $value = trim((string) $this->getConfig($key, ''));
        if ($value === '') {
            throw new ApiException($error);
        }
        return $value;
    }
}
