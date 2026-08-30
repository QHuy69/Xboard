<?php

namespace Plugin\CoinPayments;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
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
            && trim((string) $this->getConfig('coinpayments_invoice_currency_id', '')) !== ''
            && (float) $this->getConfig('coinpayments_cny_invoice_rate', 0) > 0;
    }

    public function validateActivation(): void
    {
        $required = [
            'coinpayments_client_id' => __('CoinPayments Client ID'),
            'coinpayments_client_secret' => __('CoinPayments Client Secret'),
            'coinpayments_invoice_currency_id' => __('CoinPayments invoice currency ID'),
        ];
        $missing = [];
        foreach ($required as $key => $label) {
            if (trim((string) $this->getConfig($key, '')) === '') {
                $missing[] = $label;
            }
        }
        if ((float) $this->getConfig('coinpayments_cny_invoice_rate', 0) <= 0) {
            $missing[] = __('CoinPayments CNY-to-invoice exchange rate');
        }
        if ($missing !== []) {
            throw new \InvalidArgumentException(__('CoinPayments cannot be enabled. Please configure: :fields.', [
                'fields' => implode(', ', $missing),
            ]));
        }

        $apiBase = trim((string) $this->getConfig('coinpayments_api_base', self::DEFAULT_API_BASE));
        if (!filter_var($apiBase, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($apiBase), 'https://')) {
            throw new \InvalidArgumentException(__('CoinPayments API base URL must be a valid HTTPS URL.'));
        }

        $webhookUrl = trim((string) $this->getConfig('coinpayments_webhook_url', ''));
        if ($webhookUrl !== ''
            && (!filter_var($webhookUrl, FILTER_VALIDATE_URL)
                || !str_starts_with(strtolower($webhookUrl), 'https://'))) {
            throw new \InvalidArgumentException(__('CoinPayments webhook URL must be a valid HTTPS URL.'));
        }
    }

    public function form(): array
    {
        return [
            'coinpayments_client_id' => [
                'label' => 'Integration Client ID', 'type' => 'string', 'required' => true,
                'description' => 'The Client ID from your CoinPayments API integration.',
            ],
            'coinpayments_client_secret' => [
                'label' => 'Integration Client Secret', 'type' => 'password', 'required' => true,
                'description' => 'Used to sign HMAC-SHA256 requests. Keep it secret; leave blank while editing to preserve the saved value.',
            ],
            'coinpayments_invoice_currency_id' => [
                'label' => 'Invoice currency ID', 'type' => 'string', 'required' => true,
                'description' => 'The CoinPayments NEW currency ID used to create invoices and verify webhooks, for example 5057 for USD on Instance A.',
            ],
            'coinpayments_payment_currency' => [
                'label' => 'Preferred payment currency ID', 'type' => 'string', 'required' => false, 'default' => '',
                'description' => 'Optional CoinPayments NEW currency ID (for example 4:0x... for a token); leave blank to let customers choose.',
            ],
            'coinpayments_cny_invoice_rate' => [
                'label' => 'CNY-to-invoice exchange rate', 'type' => 'string', 'required' => true,
                'description' => 'How many invoice-currency units equal one CNY.',
            ],
            'coinpayments_api_base' => [
                'label' => 'API base URL', 'type' => 'string', 'required' => false, 'default' => self::DEFAULT_API_BASE,
                'description' => 'Keep the default unless CoinPayments provides a different endpoint.',
            ],
            'coinpayments_webhook_url' => [
                'label' => 'Exact webhook URL', 'type' => 'string', 'required' => false, 'default' => '',
                'description' => 'The exact public callback URL; configure it when using Cloudflare or a reverse proxy.',
            ],
            'coinpayments_webhook_max_age' => [
                'label' => 'Webhook validity window (seconds)', 'type' => 'number', 'required' => false, 'default' => 300,
                'description' => 'Webhook replay-protection window; 300 seconds is recommended.',
            ],
        ];
    }

    public function pay($order): array
    {
        $clientId = $this->requiredConfig('coinpayments_client_id', __('CoinPayments Client ID is not configured.'));
        $secret = $this->requiredConfig('coinpayments_client_secret', __('CoinPayments Client Secret is not configured.'));
        $invoiceCurrencyId = $this->requiredConfig(
            'coinpayments_invoice_currency_id',
            __('CoinPayments invoice currency ID is not configured.')
        );
        $rate = (float) $this->getConfig('coinpayments_cny_invoice_rate', 0);
        if ($rate <= 0) {
            throw new ApiException(__('CoinPayments CNY exchange rate is not configured.'));
        }

        $amount = number_format(((int) $order['total_amount'] / 100) * $rate, 8, '.', '');
        if ((float) $amount <= 0) {
            throw new ApiException(__('CoinPayments invoice amount is invalid.'));
        }

        $user = User::find((int) $order['user_id']);
        if (!$user || !filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException(__('CoinPayments buyer email is invalid.'));
        }

        $apiBase = rtrim((string) $this->getConfig('coinpayments_api_base', self::DEFAULT_API_BASE), '/');
        if (!filter_var($apiBase, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($apiBase), 'https://')) {
            throw new ApiException(__('CoinPayments API URL is invalid.'));
        }
        $url = $apiBase . '/api/v2/merchant/invoices';
        $notifyUrl = trim((string) $this->getConfig('coinpayments_webhook_url', '')) ?: (string) $order['notify_url'];
        $paymentCurrency = trim((string) $this->getConfig('coinpayments_payment_currency', ''));

        $payload = [
            'currency' => $invoiceCurrencyId,
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
            // CoinPayments documents poNumber as unique per merchant. It is a
            // provider-side backstop in addition to XBoard's durable claim.
            'poNumber' => (string) $order['trade_no'],
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

        // Invoice creation is a non-idempotent POST and CoinPayments does not
        // document an idempotency header for this route. An automatic retry
        // after an ambiguous timeout can create two payable invoices for one
        // XBoard order, so surface the failure and let reconciliation decide.
        try {
            $response = Http::connectTimeout(10)->timeout(30)->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-CoinPayments-Client' => $clientId,
                'X-CoinPayments-Timestamp' => $timestamp,
                'X-CoinPayments-Signature' => $signature,
            ])->withBody($json, 'application/json')->post($url);
        } catch (ConnectionException $exception) {
            // The request may have reached CoinPayments even though no
            // response reached us. Code 503 tells the order claim to remain
            // uncertain and forbids another non-idempotent POST.
            throw new ApiException(__('Request failed, please try again later'), 503);
        }

        if (!$response->successful()) {
            throw new ApiException(__('CoinPayments could not create the invoice (HTTP :status).', [
                'status' => $response->status(),
            ]), $response->serverError() ? 503 : 400);
        }

        $invoice = data_get($response->json(), 'invoices.0');
        $checkoutUrl = is_array($invoice) ? ($invoice['checkoutLink'] ?? $invoice['link'] ?? null) : null;
        $checkoutParts = is_string($checkoutUrl) ? parse_url($checkoutUrl) : false;
        if (!is_string($checkoutUrl)
            || filter_var($checkoutUrl, FILTER_VALIDATE_URL) === false
            || !is_array($checkoutParts)
            || strtolower((string) ($checkoutParts['scheme'] ?? '')) !== 'https'
            || trim((string) ($checkoutParts['host'] ?? '')) === '') {
            // A 2xx response may already represent a created invoice. Do not
            // turn an unusable/malformed success response into a second POST.
            throw new ApiException(__('CoinPayments returned an invalid checkout link.'), 503);
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
        $expectedClientId = $this->requiredConfig('coinpayments_client_id', __('CoinPayments Client ID is not configured.'));
        $secret = $this->requiredConfig('coinpayments_client_secret', __('CoinPayments Client Secret is not configured.'));

        if ($clientId === '' || !hash_equals($expectedClientId, $clientId) || $timestamp === '' || $providedSignature === '') {
            throw new ApiException(__('CoinPayments webhook authentication failed.'), 400);
        }
        $parsedTimestamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $timestamp, new DateTimeZone('UTC'));
        $maxAge = max(60, min(900, (int) $this->getConfig('coinpayments_webhook_max_age', 300)));
        if (!$parsedTimestamp || abs(time() - $parsedTimestamp->getTimestamp()) > $maxAge) {
            throw new ApiException(__('CoinPayments webhook timestamp is stale.'), 400);
        }

        $configuredUrl = trim((string) $this->getConfig('coinpayments_webhook_url', ''));
        $signedUrl = $configuredUrl !== '' ? $configuredUrl : $request->fullUrl();
        $expectedSignature = self::signature($request->method(), $signedUrl, $clientId, $timestamp, $rawBody, $secret);
        if (!hash_equals($expectedSignature, $providedSignature)) {
            throw new ApiException(__('CoinPayments webhook signature does not match.'), 400);
        }

        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        $eventType = strtolower((string) ($payload['type'] ?? ''));
        $invoiceState = strtolower((string) data_get($payload, 'invoice.state', ''));
        if ($eventType !== 'invoicecompleted' || $invoiceState !== 'completed') {
            return 'success';
        }

        $tradeNo = trim((string) (data_get($payload, 'invoice.customData.trade_no')
            ?: data_get($payload, 'invoice.invoiceId')
            ?: data_get($payload, 'invoice.invoiceNumber')));
        $callbackNo = trim((string) ($payload['id'] ?? data_get($payload, 'invoice.id', '')));
        if ($tradeNo === '' || $callbackNo === '') {
            throw new ApiException(__('CoinPayments webhook is missing the order reference.'), 400);
        }

        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            throw new ApiException(__('Order does not exist'), 400);
        }

        // The order's payment_id/handling_amount can change when a customer
        // switches gateways after this invoice was created. Authenticate the
        // callback against the immutable order/payment claim and use its
        // frozen amount, or a legitimately paid older invoice can be rejected
        // (or checked against another gateway's fee).
        $paymentId = filter_var($this->getConfig('id'), FILTER_VALIDATE_INT);
        $checkout = $paymentId
            ? DB::table('v2_order_payment_checkout')
                ->where('order_id', $order->id)
                ->where('payment_id', (int) $paymentId)
                ->where('provider', 'CoinPayments')
                ->first()
            : null;
        if (!$checkout
            || (int) $checkout->base_amount !== (int) $order->total_amount) {
            throw new ApiException(__('CoinPayments invoice does not match the payment checkout.'), 400);
        }
        $expectedCurrencyId = trim((string) $this->getConfig('coinpayments_invoice_currency_id', ''));
        $receivedCurrencyId = trim((string) data_get($payload, 'invoice.amount.currencyId', ''));
        if ($expectedCurrencyId === '' || $receivedCurrencyId === '' || !hash_equals($expectedCurrencyId, $receivedCurrencyId)) {
            throw new ApiException(__('CoinPayments invoice currency does not match.'), 400);
        }

        $rate = (float) $this->getConfig('coinpayments_cny_invoice_rate', 0);
        $expectedAmount = (((int) $checkout->base_amount + (int) ($checkout->handling_amount ?? 0)) / 100) * $rate;
        $receivedAmount = (float) data_get($payload, 'invoice.amount.total', 0);
        if ($rate <= 0 || $receivedAmount + 0.000001 < $expectedAmount) {
            throw new ApiException(__('CoinPayments invoice amount is insufficient.'), 400);
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
