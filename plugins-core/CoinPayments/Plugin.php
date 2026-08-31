<?php

namespace Plugin\CoinPayments;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plugin as PluginModel;
use App\Models\User;
use App\Services\CoinPaymentsCheckoutSnapshot;
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
    private const API_HOSTS = [
        'a-api.coinpayments.net',
        'b-api.coinpayments.net',
        'c-api.coinpayments.net',
        'api.coinpayments.net',
    ];
    private const CHECKOUT_HOSTS = [
        'checkout.coinpayments.net',
        'a-checkout.coinpayments.net',
        'b-checkout.coinpayments.net',
        'c-checkout.coinpayments.net',
    ];

    public function boot(): void
    {
        $this->filter('available_payment_methods', function (array $methods): array {
            // Enabling the plugin only makes the gateway available to the
            // payment-method editor. Credentials belong to each concrete
            // payment record and are validated before that record can be
            // enabled for customers.
            $methods['CoinPayments'] = [
                'name' => $this->getConfig('display_name', 'CoinPayments'),
                'icon' => $this->getConfig('icon', '💰'),
                'plugin_code' => $this->getPluginCode(),
                'type' => 'plugin',
            ];
            return $methods;
        });
    }

    /**
     * Plugin activation intentionally has no credential requirement. The
     * administrator first enables the gateway plugin, then creates a disabled
     * payment method and configures the CoinPayments NEW credentials there.
     */
    public function validateActivation(): void
    {
        // Payment-record validation is handled by
        // validatePaymentConfiguration().
    }

    /** Validate the effective configuration before a payment record is enabled. */
    public function validatePaymentConfiguration(): void
    {
        $required = [
            'coinpayments_client_id' => __('CoinPayments Client ID'),
            'coinpayments_client_secret' => __('CoinPayments Client Secret'),
            'coinpayments_invoice_currency_id' => __('CoinPayments invoice currency ID'),
        ];
        $missing = [];
        foreach ($required as $key => $label) {
            if ($this->stringConfig($key) === '') {
                $missing[] = $label;
            }
        }
        $rateValue = $this->getConfig('coinpayments_cny_invoice_rate', 0);
        if (!is_scalar($rateValue) || !is_numeric($rateValue) || (float) $rateValue <= 0) {
            $missing[] = __('CoinPayments CNY-to-invoice exchange rate');
        }
        if ($missing !== []) {
            throw new \InvalidArgumentException(__('CoinPayments payment method cannot be enabled. Please configure: :fields.', [
                'fields' => implode(', ', $missing),
            ]));
        }

        if ($this->normalizedApiBase() === null) {
            throw new \InvalidArgumentException(__('CoinPayments API base URL must be an official CoinPayments HTTPS endpoint.'));
        }

        $webhookUrl = $this->resolvedWebhookUrl();
        if ($webhookUrl !== ''
            && (!filter_var($webhookUrl, FILTER_VALIDATE_URL)
                || !str_starts_with(strtolower($webhookUrl), 'https://'))) {
            throw new \InvalidArgumentException(__('CoinPayments webhook URL must be a valid HTTPS URL.'));
        }
    }

    /** CoinPayments credentials are intentionally isolated per payment row. */
    public function usesGlobalPaymentConfiguration(): bool
    {
        return false;
    }

    /**
     * Move credentials saved by versions <= 2.3 into each CoinPayments
     * payment record, then remove them from plugin-admin storage. Existing
     * installations keep their effective values without sharing one secret
     * implicitly across every new payment method.
     */
    public function update(string $oldVersion, string $newVersion): void
    {
        if (version_compare($oldVersion, '2.4.0', '>=')) {
            return;
        }

        $pluginRow = PluginModel::query()
            ->where('code', $this->getPluginCode())
            ->first();
        if (!$pluginRow) {
            return;
        }

        $legacyConfig = json_decode((string) $pluginRow->config, true);
        $legacyConfig = is_array($legacyConfig) ? $legacyConfig : [];
        $legacyPaymentDefaults = array_intersect_key(
            $legacyConfig,
            array_flip(array_keys($this->form()))
        );

        DB::transaction(function () use ($pluginRow, $legacyConfig, $legacyPaymentDefaults): void {
            Payment::query()
                ->where('payment', 'CoinPayments')
                ->lockForUpdate()
                ->get()
                ->each(function (Payment $payment) use ($legacyPaymentDefaults): void {
                    $recordConfig = is_array($payment->config) ? $payment->config : [];
                    foreach ($legacyPaymentDefaults as $key => $value) {
                        // Blank password overrides historically fell back to
                        // the plugin secret. Preserve that effective value.
                        if ($key === 'coinpayments_client_secret') {
                            if (trim((string) ($recordConfig[$key] ?? '')) === '') {
                                $recordConfig[$key] = $value;
                            }
                            continue;
                        }
                        if (!array_key_exists($key, $recordConfig)) {
                            $recordConfig[$key] = $value;
                        }
                    }
                    $payment->config = $recordConfig;
                    $payment->save();
                });

            $pluginRow->config = json_encode(array_intersect_key(
                $legacyConfig,
                ['display_name' => true]
            ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $pluginRow->save();
        });
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
                'label' => 'Webhook URL override (optional)', 'type' => 'string', 'required' => false, 'default' => '',
                'description' => 'Leave blank to use this site\'s main HTTPS domain. Set this only when CoinPayments must call a different public endpoint.',
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
        $rateValue = $this->getConfig('coinpayments_cny_invoice_rate', 0);
        if (!is_scalar($rateValue) || !is_numeric($rateValue) || (float) $rateValue <= 0) {
            throw new ApiException(__('CoinPayments CNY exchange rate is not configured.'));
        }
        $rate = (float) $rateValue;

        $amount = number_format(((int) $order['total_amount'] / 100) * $rate, 8, '.', '');
        if ((float) $amount <= 0) {
            throw new ApiException(__('CoinPayments invoice amount is invalid.'));
        }

        $user = User::find((int) $order['user_id']);
        if (!$user || !filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException(__('CoinPayments buyer email is invalid.'));
        }

        $apiBase = $this->normalizedApiBase();
        if ($apiBase === null) {
            throw new ApiException(__('CoinPayments API URL is invalid.'));
        }
        $url = $apiBase . '/api/v2/merchant/invoices';
        $notifyUrl = $this->stringConfig('coinpayments_webhook_url') ?: (string) $order['notify_url'];
        $notifyParts = parse_url($notifyUrl);
        if (!filter_var($notifyUrl, FILTER_VALIDATE_URL)
            || !is_array($notifyParts)
            || strtolower((string) ($notifyParts['scheme'] ?? '')) !== 'https') {
            throw new ApiException(__('CoinPayments webhook URL must be a valid HTTPS URL.'));
        }
        $paymentCurrency = $this->stringConfig('coinpayments_payment_currency');

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
                'notifications' => [
                    'invoiceCompleted',
                    'invoiceTimedOut',
                    'invoiceCancelled',
                ],
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
        // Only checkoutLink is intended for embedding. The ordinary `link`
        // can lead to a different hosted flow and must never become an iframe
        // source on the customer dashboard.
        $checkoutUrl = is_array($invoice) ? ($invoice['checkoutLink'] ?? null) : null;
        if (!self::isAllowedCheckoutUrl($checkoutUrl)) {
            // A 2xx response may already represent a created invoice. Do not
            // turn an unusable/malformed success response into a second POST.
            throw new ApiException(__('CoinPayments returned an invalid checkout link.'), 503);
        }
        $providerInvoiceIdValue = is_array($invoice) ? ($invoice['id'] ?? null) : null;
        $providerInvoiceId = (is_scalar($providerInvoiceIdValue) || $providerInvoiceIdValue === null)
            ? trim((string) $providerInvoiceIdValue)
            : '';
        if ($providerInvoiceId === '') {
            throw new ApiException(__('CoinPayments returned an invalid invoice identifier.'), 503);
        }

        $providerExpiryValue = is_array($invoice) ? data_get($invoice, 'payment.expires') : null;
        if (!is_scalar($providerExpiryValue) || trim((string) $providerExpiryValue) === '') {
            throw new ApiException(__('CoinPayments returned an invalid invoice expiry.'), 503);
        }
        try {
            $providerExpiresAt = (new DateTimeImmutable((string) $providerExpiryValue))->getTimestamp();
        } catch (\Throwable $exception) {
            // A 2xx response already may have created a payable invoice.
            // Treat malformed metadata as ambiguous; never issue another
            // non-idempotent POST automatically.
            throw new ApiException(__('CoinPayments returned an invalid invoice expiry.'), 503);
        }
        if ($providerExpiresAt <= time()) {
            throw new ApiException(__('CoinPayments returned an invalid invoice expiry.'), 503);
        }

        return [
            'type' => 1,
            'data' => $checkoutUrl,
            'provider_invoice_id' => $providerInvoiceId,
            'provider_expires_at' => $providerExpiresAt,
            'expected_amount' => $amount,
        ];
    }

    public function notify($params): array|string
    {
        $request = request();
        $rawBody = (string) $request->getContent();
        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        $tradeNo = trim((string) (data_get($payload, 'invoice.customData.trade_no')
            ?: data_get($payload, 'invoice.invoiceId')
            ?: data_get($payload, 'invoice.invoiceNumber')));
        if ($tradeNo === '') {
            throw new ApiException(__('CoinPayments webhook is missing the order reference.'), 400);
        }

        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            throw new ApiException(__('Order does not exist'), 400);
        }

        $paymentUuid = $this->stringConfig('uuid');
        $paymentId = filter_var($this->getConfig('id'), FILTER_VALIDATE_INT);
        $checkoutQuery = DB::table('v2_order_payment_checkout')
            ->where('order_id', $order->id)
            ->where('provider', 'CoinPayments');
        if ($paymentUuid !== '') {
            $checkoutQuery->where('payment_uuid', $paymentUuid);
        } elseif ($paymentId !== false) {
            $checkoutQuery->where('payment_id', (int) $paymentId);
        } else {
            throw new ApiException(__('CoinPayments invoice does not match the payment checkout.'), 400);
        }
        $checkout = $checkoutQuery->first();
        if (!$checkout || (int) $checkout->base_amount !== (int) $order->total_amount) {
            throw new ApiException(__('CoinPayments invoice does not match the payment checkout.'), 400);
        }

        $snapshot = null;
        if ($checkout->config_snapshot !== null) {
            try {
                $snapshot = CoinPaymentsCheckoutSnapshot::decrypt((string) $checkout->config_snapshot);
            } catch (\Throwable $exception) {
                throw new ApiException(__('CoinPayments checkout configuration could not be verified.'), 400);
            }
            if ((int) $snapshot['payment_id'] !== (int) $checkout->payment_id
                || ($paymentUuid !== '' && !hash_equals((string) $snapshot['payment_uuid'], $paymentUuid))) {
                throw new ApiException(__('CoinPayments invoice does not match the payment checkout.'), 400);
            }
        }

        $clientId = trim((string) $request->header('X-CoinPayments-Client', ''));
        $timestamp = trim((string) $request->header('X-CoinPayments-Timestamp', ''));
        $providedSignature = trim((string) $request->header('X-CoinPayments-Signature', ''));
        $expectedClientId = $snapshot !== null
            ? trim((string) $snapshot['coinpayments_client_id'])
            : $this->requiredConfig('coinpayments_client_id', __('CoinPayments Client ID is not configured.'));
        $secret = $snapshot !== null
            ? trim((string) $snapshot['coinpayments_client_secret'])
            : $this->requiredConfig('coinpayments_client_secret', __('CoinPayments Client Secret is not configured.'));

        if ($clientId === '' || !hash_equals($expectedClientId, $clientId) || $timestamp === '' || $providedSignature === '') {
            throw new ApiException(__('CoinPayments webhook authentication failed.'), 400);
        }
        $parsedTimestamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $timestamp, new DateTimeZone('UTC'));
        $maxAge = $snapshot !== null
            ? (int) $snapshot['coinpayments_webhook_max_age']
            : max(60, min(900, (int) $this->getConfig('coinpayments_webhook_max_age', 300)));
        if (!$parsedTimestamp || abs(time() - $parsedTimestamp->getTimestamp()) > $maxAge) {
            throw new ApiException(__('CoinPayments webhook timestamp is stale.'), 400);
        }

        $signedUrl = $snapshot !== null
            ? (string) $snapshot['coinpayments_webhook_url']
            : ($this->stringConfig('coinpayments_webhook_url') ?: $request->fullUrl());
        $expectedSignature = self::signature($request->method(), $signedUrl, $clientId, $timestamp, $rawBody, $secret);
        if (!hash_equals($expectedSignature, $providedSignature)) {
            throw new ApiException(__('CoinPayments webhook signature does not match.'), 400);
        }

        $eventType = strtolower((string) ($payload['type'] ?? ''));
        $invoiceState = preg_replace(
            '/[^a-z]/',
            '',
            strtolower((string) data_get($payload, 'invoice.state', ''))
        );
        $event = match ($eventType) {
            'invoicecompleted' => 'completed',
            'invoicetimedout' => 'timed_out',
            'invoicecancelled' => 'cancelled',
            default => null,
        };
        if ($event === null) {
            return 'success';
        }
        $expectedState = match ($event) {
            'completed' => 'completed',
            'timed_out' => 'timedout',
            'cancelled' => 'cancelled',
        };
        if ($invoiceState !== $expectedState) {
            throw new ApiException(__('CoinPayments invoice does not match the payment checkout.'), 400);
        }

        $receivedProviderInvoiceIdValue = data_get($payload, 'invoice.id');
        $receivedProviderInvoiceId = (is_scalar($receivedProviderInvoiceIdValue) || $receivedProviderInvoiceIdValue === null)
            ? trim((string) $receivedProviderInvoiceIdValue)
            : '';
        $topLevelInvoiceIdValue = $payload['id'] ?? null;
        $topLevelInvoiceId = (is_scalar($topLevelInvoiceIdValue) || $topLevelInvoiceIdValue === null)
            ? trim((string) $topLevelInvoiceIdValue)
            : '';
        if ($receivedProviderInvoiceId === ''
            || ($topLevelInvoiceId !== ''
                && !hash_equals($receivedProviderInvoiceId, $topLevelInvoiceId))) {
            throw new ApiException(__('CoinPayments invoice identifier does not match.'), 400);
        }
        if ($checkout->provider_invoice_id !== null
            && ($receivedProviderInvoiceId === ''
                || !hash_equals((string) $checkout->provider_invoice_id, $receivedProviderInvoiceId))) {
            throw new ApiException(__('CoinPayments invoice identifier does not match.'), 400);
        }

        $expectedCurrencyId = $snapshot !== null
            ? trim((string) $snapshot['coinpayments_invoice_currency_id'])
            : $this->stringConfig('coinpayments_invoice_currency_id');
        $receivedCurrencyId = trim((string) data_get($payload, 'invoice.amount.currencyId', ''));
        if ($expectedCurrencyId === '' || $receivedCurrencyId === '' || !hash_equals($expectedCurrencyId, $receivedCurrencyId)) {
            throw new ApiException(__('CoinPayments invoice currency does not match.'), 400);
        }

        $expectedAmount = $checkout->expected_amount;
        if ($expectedAmount === null) {
            $rateValue = $snapshot !== null
                ? $snapshot['coinpayments_cny_invoice_rate']
                : $this->getConfig('coinpayments_cny_invoice_rate', 0);
            try {
                $expectedAmount = CoinPaymentsCheckoutSnapshot::expectedAmount(
                    (int) $checkout->base_amount,
                    isset($checkout->handling_amount) ? (int) $checkout->handling_amount : null,
                    $rateValue
                );
            } catch (\Throwable $exception) {
                throw new ApiException(__('CoinPayments invoice amount could not be verified.'), 400);
            }
        }
        $receivedAmountValue = data_get($payload, 'invoice.amount.total');
        if (!is_scalar($receivedAmountValue)
            || !is_numeric($receivedAmountValue)
            || !is_numeric($expectedAmount)
            || !is_finite((float) $receivedAmountValue)
            || (float) $receivedAmountValue + 0.00000001 < (float) $expectedAmount) {
            throw new ApiException(__('CoinPayments invoice amount is insufficient.'), 400);
        }

        return [
            'event' => $event,
            'trade_no' => $tradeNo,
            // CoinPayments documents both top-level id and invoice.id as the
            // same system invoice identity. Store that stable identity on the
            // order rather than inventing a separate webhook-event key.
            'callback_no' => $receivedProviderInvoiceId,
            'checkout_id' => (int) $checkout->id,
            'payment_id' => (int) $checkout->payment_id,
            'payment_uuid' => (string) $checkout->payment_uuid,
            'provider_invoice_id' => $receivedProviderInvoiceId,
            'custom_result' => 'success',
        ];
    }

    public static function signature(string $method, string $url, string $clientId, string $timestamp, string $payload, string $secret): string
    {
        $canonical = "\xEF\xBB\xBF" . strtoupper($method) . $url . $clientId . $timestamp . $payload;
        return base64_encode(hash_hmac('sha256', $canonical, $secret, true));
    }

    private function requiredConfig(string $key, string $error): string
    {
        $value = $this->stringConfig($key);
        if ($value === '') {
            throw new ApiException($error);
        }
        return $value;
    }

    private function stringConfig(string $key, string $default = ''): string
    {
        $value = $this->getConfig($key, $default);

        return is_scalar($value) || $value === null
            ? trim((string) $value)
            : '';
    }

    private function resolvedWebhookUrl(): string
    {
        $configured = $this->stringConfig('coinpayments_webhook_url');
        if ($configured !== '') {
            return $configured;
        }

        $uuid = $this->stringConfig('uuid');
        if ($uuid === '') {
            return '';
        }

        $url = url('/api/v1/guest/payment/notify/CoinPayments/' . rawurlencode($uuid));
        $notifyDomain = rtrim($this->stringConfig('notify_domain'), '/');
        if ($notifyDomain !== '') {
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $url = $notifyDomain . $path;
            }
        }

        return $url;
    }

    private function normalizedApiBase(): ?string
    {
        $url = $this->stringConfig('coinpayments_api_base', self::DEFAULT_API_BASE);
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !in_array(strtolower((string) ($parts['host'] ?? '')), self::API_HOSTS, true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || !in_array((string) ($parts['path'] ?? ''), ['', '/'], true)
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return null;
        }

        return 'https://' . strtolower((string) $parts['host']);
    }

    private static function isAllowedCheckoutUrl(mixed $url): bool
    {
        if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && in_array(strtolower((string) ($parts['host'] ?? '')), self::CHECKOUT_HOSTS, true)
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && (!isset($parts['port']) || (int) $parts['port'] === 443);
    }
}
