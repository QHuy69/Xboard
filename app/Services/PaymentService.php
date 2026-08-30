<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Services\Plugin\PluginConfigService;
use App\Services\Plugin\PluginManager;
use App\Services\Plugin\HookManager;

class PaymentService
{
    public $method;
    protected $config;
    protected $payment;
    protected $pluginManager;
    protected $class;

    public function __construct($method, $id = NULL, $uuid = NULL)
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
            if (!$paymentModel) {
                throw new ApiException('payment not found');
            }
            $payment = $paymentModel->makeVisible('config')->toArray();
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
                    // Plugin-admin configuration supplies safe global
                    // defaults. A concrete payment record can override those
                    // values without making the plugin settings decorative.
                    $pluginConfig = $plugin->getConfig();
                    $paymentConfig = $this->withoutBlankPasswordOverrides(
                        $this->config,
                        $paymentPlugin->form()
                    );
                    $this->config = array_replace(
                        is_array($pluginConfig) ? $pluginConfig : [],
                        $paymentConfig
                    );
                    $paymentPlugin->setConfig($this->config);
                    $this->payment = $paymentPlugin;
                    return;
                }
            }
        }

        $this->payment = new $this->class($this->config);
    }

    public function notify($params)
    {
        if (!$this->config['enable'])
            throw new ApiException('gate is not enable');
        return $this->payment->notify($params);
    }

    public function pay($order)
    {
        // custom notify domain name
        $notifyUrl = url("/api/v1/guest/payment/notify/{$this->method}/{$this->config['uuid']}");
        if ($this->config['notify_domain']) {
            $parseUrl = parse_url($notifyUrl);
            $notifyUrl = $this->config['notify_domain'] . $parseUrl['path'];
        }

        return $this->payment->pay([
            'notify_url' => $notifyUrl,
            'return_url' => source_base_url('/#/order/' . $order['trade_no']),
            'trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'user_id' => $order['user_id'],
            'stripe_token' => $order['stripe_token']
        ]);
    }

    public function form()
    {
        $form = $this->payment->form();
        $result = [];
        foreach ($form as $key => $field) {
            $type = (string) ($field['type'] ?? 'string');
            $storedValue = $this->config[$key] ?? $field['default'] ?? '';
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
                // plugin-global or per-payment secret through this endpoint,
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

        foreach ($options as $key => $option) {
            if (is_array($option)) {
                if (array_key_exists('label', $option)) {
                    $option['label'] = $this->translateFormMetadata($option['label']);
                }
                $options[$key] = $option;
                continue;
            }

            if (is_string($option)) {
                $options[$key] = $this->translateFormMetadata($option);
            }
        }

        return $options;
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
     * value exists, omit the key so the plugin-global secret remains the
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
