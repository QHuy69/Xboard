<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Services\Plugin\PluginConfigService;
use App\Services\Plugin\PluginManager;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentService
{
    public $method;
    protected $config;
    protected array $pluginPaymentDefaults = [];
    protected $payment;
    protected $pluginManager;
    protected $class;

    public function __construct($method, $id = NULL, $uuid = NULL, bool $allowDisabledPlugin = false)
    {
        $this->method = $method;
        $this->pluginManager = app(PluginManager::class);

        if ($method === 'temp') {
            return;
        }

        if ($id) {
            $paymentModel = Payment::find($id);
            if (!$paymentModel) {
                throw new ApiException('payment not found');
            }
            $payment = $paymentModel->makeVisible('config')->toArray();
        }
        if ($uuid) {
            $paymentModel = Payment::where('uuid', $uuid)->first();
            if ($paymentModel) {
                $payment = $paymentModel->makeVisible('config')->toArray();
            } elseif ($allowDisabledPlugin
                && $method === 'CoinPayments'
                && Schema::hasTable('v2_order_payment_checkout')
                && Schema::hasColumn('v2_order_payment_checkout', 'payment_uuid')) {
                // A pre-guard deployment may have deleted the payment row
                // while a provider invoice was still payable. The encrypted
                // checkout snapshot is sufficient to authenticate that late
                // callback without reviving the method for new purchases.
                $checkout = DB::table('v2_order_payment_checkout')
                    ->where('payment_uuid', (string) $uuid)
                    ->where('provider', 'CoinPayments')
                    ->whereNotNull('config_snapshot')
                    ->orderByDesc('id')
                    ->first();
                if ($checkout) {
                    $snapshot = CoinPaymentsCheckoutSnapshot::decrypt((string) $checkout->config_snapshot);
                    $payment = [
                        'payment' => 'CoinPayments',
                        'config' => $snapshot,
                        'enable' => false,
                        'id' => (int) $snapshot['payment_id'],
                        'uuid' => (string) $snapshot['payment_uuid'],
                        'notify_domain' => '',
                    ];
                }
            }
            if (!isset($payment)) {
                throw new ApiException('payment not found');
            }
        }

        // The route/form supplies both a gateway name and a concrete payment
        // record. Never combine the UUID/id, enabled flag or credentials from
        // one record with a different plugin selected by the caller.
        if (isset($payment)
            && !hash_equals((string) ($payment['payment'] ?? ''), (string) $this->method)) {
            throw new ApiException(__('Payment method does not exist or is not enabled.'));
        }

        $this->config = [];
        if (isset($payment)) {
            $paymentConfig = is_string($payment['config'])
                ? json_decode($payment['config'], true)
                : $payment['config'];
            $this->config = is_array($paymentConfig) ? $paymentConfig : [];
            $this->config['enable'] = $payment['enable'];
            $this->config['id'] = $payment['id'];
            $this->config['uuid'] = $payment['uuid'];
            $this->config['notify_domain'] = $payment['notify_domain'] ?? '';
        }

        $paymentMethods = $this->getAvailablePaymentMethods();
        if (isset($paymentMethods[$this->method])) {
            $pluginCode = $paymentMethods[$this->method]['plugin_code'];
            $paymentPlugins = $this->pluginManager->getEnabledPaymentPlugins();
            foreach ($paymentPlugins as $plugin) {
                if ($plugin->getPluginCode() === $pluginCode) {
                    // PluginManager caches one instance per plugin. Apply a
                    // payment record's overrides to a clone so one gateway
                    // record cannot leak its credentials/config into another
                    // record later in the same request.
                    $paymentPlugin = clone $plugin;
                    // Some legacy gateways allow plugin-admin defaults. New
                    // gateways can opt out so credentials stay isolated in
                    // each concrete payment record.
                    $pluginConfig = $plugin->usesGlobalPaymentConfiguration()
                        ? $plugin->getConfig()
                        : [];
                    $this->pluginPaymentDefaults = is_array($pluginConfig)
                        ? $pluginConfig
                        : [];
                    $paymentConfig = $this->withoutBlankPasswordOverrides(
                        $this->config,
                        $paymentPlugin->form()
                    );
                    $this->config = array_replace(
                        $this->pluginPaymentDefaults,
                        $paymentConfig
                    );
                    $paymentPlugin->setConfig($this->config);
                    $this->payment = $paymentPlugin;
                    return;
                }
            }
        }

        if ($allowDisabledPlugin && $this->method === 'CoinPayments') {
            $plugin = $this->pluginManager->getPlugin('coin_payments');
            if ($plugin) {
                $paymentPlugin = clone $plugin;
                $this->pluginPaymentDefaults = [];
                $paymentPlugin->setConfig($this->config);
                $this->payment = $paymentPlugin;
                return;
            }
        }

        throw new ApiException(__('Payment method does not exist or is not enabled.'));
    }

    public function notify($params)
    {
        // CoinPayments invoices can complete after an administrator disables
        // new checkouts. Its signed callback is still tied to an immutable
        // durable claim and must be allowed to credit an already-paid order.
        if (!$this->config['enable'] && $this->method !== 'CoinPayments')
            throw new ApiException('gate is not enable');
        return $this->payment->notify($params);
    }

    public function pay($order)
    {
        if (empty($this->config['enable'])) {
            throw new ApiException(__('Payment method is not available'));
        }

        // custom notify domain name
        $notifyUrl = $this->resolvedNotifyUrl();
        $returnUrl = $this->method === 'CoinPayments'
            ? source_base_url('/orders?trade_no=' . rawurlencode((string) $order['trade_no']))
            : source_base_url('/#/order/' . $order['trade_no']);

        return $this->payment->pay([
            'notify_url' => $notifyUrl,
            'return_url' => $returnUrl,
            'trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'user_id' => $order['user_id'],
            'stripe_token' => $order['stripe_token']
        ]);
    }

    /**
     * Freeze every value that can affect invoice creation or callback
     * authentication. The returned array is encrypted before persistence.
     */
    public function coinPaymentsConfigurationSnapshot(): array
    {
        if ($this->method !== 'CoinPayments') {
            throw new \LogicException('CoinPayments configuration snapshot requested for another gateway.');
        }

        $this->validateConfiguration();
        $apiBase = trim((string) ($this->config['coinpayments_api_base'] ?? ''));
        if ($apiBase === '') {
            $apiBase = 'https://a-api.coinpayments.net';
        }
        $apiParts = parse_url($apiBase);
        if (!is_array($apiParts) || empty($apiParts['host'])) {
            throw new \UnexpectedValueException('CoinPayments API base URL is invalid.');
        }

        $maxAgeValue = $this->config['coinpayments_webhook_max_age'] ?? 300;
        if ($maxAgeValue === null || (is_string($maxAgeValue) && trim($maxAgeValue) === '')) {
            $maxAgeValue = 300;
        }
        $maxAge = filter_var($maxAgeValue, FILTER_VALIDATE_INT);
        if ($maxAge === false || $maxAge < 60 || $maxAge > 900) {
            throw new \UnexpectedValueException('CoinPayments webhook validity window is invalid.');
        }

        $snapshot = [
            'snapshot_version' => CoinPaymentsCheckoutSnapshot::VERSION,
            'payment_id' => (int) ($this->config['id'] ?? 0),
            'payment_uuid' => trim((string) ($this->config['uuid'] ?? '')),
            'coinpayments_client_id' => trim((string) ($this->config['coinpayments_client_id'] ?? '')),
            'coinpayments_client_secret' => trim((string) ($this->config['coinpayments_client_secret'] ?? '')),
            'coinpayments_invoice_currency_id' => trim((string) ($this->config['coinpayments_invoice_currency_id'] ?? '')),
            'coinpayments_payment_currency' => trim((string) ($this->config['coinpayments_payment_currency'] ?? '')),
            'coinpayments_cny_invoice_rate' => (string) ($this->config['coinpayments_cny_invoice_rate'] ?? ''),
            'coinpayments_api_base' => 'https://' . strtolower((string) $apiParts['host']),
            'coinpayments_webhook_url' => $this->resolvedNotifyUrl(),
            'coinpayments_webhook_max_age' => (int) $maxAge,
        ];
        CoinPaymentsCheckoutSnapshot::assertValid($snapshot);

        return $snapshot;
    }

    public function useCoinPaymentsConfigurationSnapshot(array $snapshot): self
    {
        if ($this->method !== 'CoinPayments') {
            throw new \LogicException('CoinPayments configuration snapshot applied to another gateway.');
        }
        CoinPaymentsCheckoutSnapshot::assertValid($snapshot);

        $currentPaymentId = (int) ($this->config['id'] ?? 0);
        if ($currentPaymentId > 0 && $currentPaymentId !== (int) $snapshot['payment_id']) {
            throw new \UnexpectedValueException('CoinPayments checkout snapshot belongs to another payment method.');
        }

        $this->config = array_replace($this->config ?? [], $snapshot, [
            'id' => (int) $snapshot['payment_id'],
            'uuid' => (string) $snapshot['payment_uuid'],
            'enable' => true,
            'notify_domain' => '',
        ]);
        $this->payment->setConfig($this->config);

        return $this;
    }

    private function resolvedNotifyUrl(): string
    {
        $configured = trim((string) ($this->config['coinpayments_webhook_url'] ?? ''));
        if ($this->method === 'CoinPayments' && $configured !== '') {
            return $configured;
        }

        $notifyUrl = url("/api/v1/guest/payment/notify/{$this->method}/{$this->config['uuid']}");
        $notifyDomain = rtrim(trim((string) ($this->config['notify_domain'] ?? '')), '/');
        if ($notifyDomain !== '') {
            $parseUrl = parse_url($notifyUrl);
            if (is_array($parseUrl) && isset($parseUrl['path'])) {
                $notifyUrl = $notifyDomain . $parseUrl['path'];
            }
        }

        return $notifyUrl;
    }

    public function form()
    {
        $form = $this->payment->form();
        $result = [];
        foreach ($form as $key => $field) {
            $type = (string) ($field['type'] ?? 'string');
            $storedValue = $this->config[$key] ?? $field['default'] ?? '';
            // A plugin-declared string field must cross the admin API as a
            // string even when an older payment row stored a numeric scalar.
            // Otherwise the generated form's Zod schema rejects the value
            // before it can be submitted back to this controller.
            if ($type === 'string' && (is_int($storedValue) || is_float($storedValue))) {
                $storedValue = (string) $storedValue;
            }
            $isSensitive = $this->isSensitiveField((string) $key, $field);
            $placeholder = $field['placeholder']
                ?? ($isSensitive && $this->hasNonBlankValue($storedValue)
                    ? 'Leave blank to keep the current saved value.'
                    : '');
            $result[$key] = [
                'type' => $isSensitive ? 'password' : $type,
                'label' => $this->translateFormMetadata($field['label'] ?? ''),
                'placeholder' => $this->translateFormMetadata($placeholder),
                'description' => $this->translateFormMetadata($field['description'] ?? ''),
                // Payment forms are another admin API surface. Never leak a
                // plugin-default or per-payment secret through this endpoint,
                // including legacy plugins that declared API keys as strings.
                'value' => $isSensitive ? '' : $storedValue,
                'has_value' => $isSensitive && $this->hasNonBlankValue($storedValue),
                'options' => $this->translateFormOptions(
                    $field['select_options'] ?? $field['options'] ?? []
                )
            ];
        }
        return $result;
    }

    /**
     * Validate the effective configuration that would actually be persisted.
     * Existing record values must not make a partial/crafted replacement look
     * valid; only explicit plugin defaults and the submitted record are used.
     */
    public function validateConfiguration(?array $overrides = null): void
    {
        if (!method_exists($this->payment, 'validatePaymentConfiguration')) {
            return;
        }

        $candidate = clone $this->payment;
        if ($overrides !== null) {
            $candidate->setConfig(array_replace(
                $this->pluginPaymentDefaults,
                array_intersect_key($this->config ?? [], [
                    'id' => true,
                    'uuid' => true,
                    'enable' => true,
                    'notify_domain' => true,
                ]),
                $overrides
            ));
        }
        $candidate->validatePaymentConfiguration();
    }

    /** Reject malformed submitted values without requiring a complete draft. */
    public function validateConfigurationShape(?array $overrides = null): void
    {
        if (!method_exists($this->payment, 'validatePaymentConfigurationShape')) {
            return;
        }

        $candidate = clone $this->payment;
        if ($overrides !== null) {
            $candidate->setConfig(array_replace(
                $this->pluginPaymentDefaults,
                array_intersect_key($this->config ?? [], [
                    'id' => true,
                    'uuid' => true,
                    'enable' => true,
                    'notify_domain' => true,
                ]),
                $overrides
            ));
        }
        $candidate->validatePaymentConfigurationShape();
    }

    private function translateFormMetadata(mixed $value): string
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }

        $text = (string) $value;
        return $text === '' ? '' : __($text);
    }

    private function translateFormOptions(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }

        $isList = array_is_list($options);
        $normalized = [];
        foreach ($options as $key => $option) {
            if (is_array($option)) {
                $value = $option['value'] ?? ($isList ? null : $key);
                if ($value === null || (!is_scalar($value) && $value !== null)) {
                    continue;
                }
                $value = (string) $value;
                if ($value === '') {
                    continue;
                }
                $label = $option['label'] ?? $value;
                $translatedLabel = $this->translateFormMetadata($label);
                $normalized[] = array_replace($option, [
                    'value' => $value,
                    'label' => $translatedLabel === '' ? $value : $translatedLabel,
                ]);
                continue;
            }

            if (is_scalar($option) && $option !== false) {
                // The generated admin form always calls options.map(). JSON
                // encodes PHP value=>label maps as objects, which crashed the
                // payment editor. Normalize both shorthand maps and scalar
                // lists to the frontend's [{value,label}] contract.
                $value = $isList ? $option : $key;
                $value = (string) $value;
                $label = $this->translateFormMetadata($option);
                if ($value === '' || $label === '') {
                    continue;
                }
                $normalized[] = [
                    'value' => $value,
                    'label' => $label,
                ];
            }
        }

        return $normalized;
    }

    /**
     * Remove password values before a payment record is serialized to an
     * admin response. Presence is intentionally not exposed in this raw
     * config shape; getPaymentForm() supplies the safe has_value marker.
     */
    public function redactPasswordConfig(array $config): array
    {
        $declaredSensitive = array_flip($this->sensitiveFieldNames());
        foreach ($config as $key => $value) {
            if (isset($declaredSensitive[$key]) || self::isSensitiveConfigKey((string) $key)) {
                $config[$key] = '';
            }
        }

        return $config;
    }

    /**
     * Persist only fields declared by the selected gateway. This prevents a
     * gateway switch (or a crafted admin request) from carrying credentials
     * belonging to another payment integration into the new record.
     */
    public function onlyKnownConfigFields(array $config): array
    {
        return array_intersect_key($config, $this->payment->form());
    }

    /**
     * A blank password means "keep the current value". If no per-payment
     * value exists, omit the key so a legacy plugin default remains the
     * effective default instead of being overwritten by an empty string.
     */
    public function preserveBlankPasswords(array $submitted, array $existing = []): array
    {
        $declaredSensitive = array_flip($this->sensitiveFieldNames());
        $keys = array_unique(array_merge(
            array_keys($declaredSensitive),
            array_keys($submitted),
            array_keys($existing)
        ));
        foreach ($keys as $key) {
            if (!isset($declaredSensitive[$key])
                && !self::isSensitiveConfigKey((string) $key)) {
                continue;
            }
            $submittedValue = $submitted[$key] ?? null;
            if (array_key_exists($key, $submitted) && $this->hasNonBlankValue($submittedValue)) {
                continue;
            }

            if (array_key_exists($key, $existing) && $this->hasNonBlankValue($existing[$key])) {
                $submitted[$key] = $existing[$key];
            } else {
                unset($submitted[$key]);
            }
        }

        return $submitted;
    }

    public function passwordFieldNames(): array
    {
        return $this->sensitiveFieldNames();
    }

    public static function isSensitiveConfigKey(string $key): bool
    {
        return PluginConfigService::isSensitiveConfigKey($key);
    }

    private function sensitiveFieldNames(): array
    {
        $fields = $this->payment->form();

        return array_keys(array_filter(
            $fields,
            fn ($field, $key): bool => is_array($field)
                && $this->isSensitiveField((string) $key, $field),
            ARRAY_FILTER_USE_BOTH
        ));
    }

    private function withoutBlankPasswordOverrides(array $config, array $form): array
    {
        foreach ($form as $key => $field) {
            if (!is_array($field)
                || !$this->isSensitiveField((string) $key, $field)
                || !array_key_exists($key, $config)
                || $this->hasNonBlankValue($config[$key])) {
                continue;
            }
            unset($config[$key]);
        }

        return $config;
    }

    private function isSensitiveField(string $key, array $field): bool
    {
        return (string) ($field['type'] ?? '') === 'password'
            || self::isSensitiveConfigKey($key);
    }

    private function hasNonBlankValue(mixed $value): bool
    {
        return (is_scalar($value) || $value === null)
            && trim((string) $value) !== '';
    }

    /**
     * 获取所有可用的支付方式
     */
    public function getAvailablePaymentMethods(): array
    {
        $methods = [];

        $methods = HookManager::filter('available_payment_methods', $methods);

        return $methods;
    }

    /**
     * 获取所有支付方式名称列表（用于管理后台）
     */
    public static function getAllPaymentMethodNames(): array
    {
        $pluginManager = app(PluginManager::class);
        $pluginManager->initializeEnabledPlugins();

        $instance = new self('temp');
        $methods = $instance->getAvailablePaymentMethods();

        return array_keys($methods);
    }
}
