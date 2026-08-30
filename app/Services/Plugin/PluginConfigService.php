<?php

namespace App\Services\Plugin;

use App\Models\Plugin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class PluginConfigService
{
    protected $pluginManager;

    public function __construct()
    {
        $this->pluginManager = app(PluginManager::class);
    }

    /**
     * 获取插件配置
     *
     * @param string $pluginCode
     * @return array
     */
    public function getConfig(string $pluginCode): array
    {
        $defaultConfig = $this->getDefaultConfig($pluginCode);
        if (empty($defaultConfig)) {
            return [];
        }
        $dbConfig = $this->getDbConfig($pluginCode);

        $result = [];
        foreach ($defaultConfig as $key => $item) {
            if (!is_array($item)) {
                $item = ['type' => 'string', 'default' => $item];
            }
            $type = (string) ($item['type'] ?? 'string');
            $isSensitive = $type === 'password' || self::isSensitiveConfigKey((string) $key);
            $storedValue = array_key_exists($key, $dbConfig)
                ? $dbConfig[$key]
                : ($item['default'] ?? null);
            $placeholder = $item['placeholder']
                ?? ($isSensitive && $this->hasNonBlankValue($storedValue)
                    ? 'Leave blank to keep the current saved value.'
                    : '');
            $result[$key] = [
                // Treat conventionally named credentials as passwords even
                // when an older third-party manifest declared them as strings.
                'type' => $isSensitive ? 'password' : $type,
                // Plugin manifests use their English copy as translation keys.
                // Resolving it here keeps both installed and available-plugin
                // configuration screens aligned with the request locale.
                'label' => $this->translateMetadata($item['label'] ?? ''),
                'placeholder' => $this->translateMetadata($placeholder),
                'description' => $this->translateMetadata($item['description'] ?? ''),
                // Never return a stored secret to the browser. A blank password
                // means "keep the existing value" when a secret is already set.
                'value' => $isSensitive ? '' : $storedValue,
                'has_value' => $isSensitive && $this->hasNonBlankValue($storedValue),
                'options' => $this->translateOptions($item['options'] ?? [])
            ];
        }

        return $result;
    }

    public static function isSensitiveConfigKey(string $key): bool
    {
        $normalized = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', trim($key)) ?? $key;
        $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $normalized) ?? $normalized);
        $normalized = trim($normalized, '_');
        $flat = str_replace('_', '', $normalized);

        // Public verification keys are intentionally readable.
        if ($normalized === 'public_key' || str_ends_with($flat, 'publickey')) {
            return false;
        }

        if (preg_match(
            '/(?:^|_)(?:password|passwd|secret|credential|credentials|private_key|api_key|webhook_key|access_token|auth_token|token|key)$/',
            $normalized
        ) === 1) {
            return true;
        }

        // Cover common camelCase and delimiter-free legacy spellings without
        // treating unrelated words such as "monkey" as credentials.
        foreach ([
            'password',
            'passwd',
            'secret',
            'credential',
            'credentials',
            'privatekey',
            'apikey',
            'webhookkey',
            'accesskey',
            'secretkey',
            'accesstoken',
            'authtoken',
        ] as $suffix) {
            if (str_ends_with($flat, $suffix)) {
                return true;
            }
        }

        return $normalized === 'key' || $normalized === 'token';
    }

    private function translateMetadata(mixed $value): string
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }

        $text = (string) $value;
        return $text === '' ? '' : __($text);
    }

    private function translateOptions(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }

        foreach ($options as $key => $option) {
            if (is_array($option)) {
                if (array_key_exists('label', $option)) {
                    $option['label'] = $this->translateMetadata($option['label']);
                }
                $options[$key] = $option;
                continue;
            }

            // Also support the common value => label shorthand.
            if (is_string($option)) {
                $options[$key] = $this->translateMetadata($option);
            }
        }

        return $options;
    }

    /**
     * 更新插件配置
     *
     * @param string $pluginCode
     * @param array $config
     * @return bool
     */
    public function updateConfig(string $pluginCode, array $config): bool
    {
        $defaultConfig = $this->getDefaultConfig($pluginCode);
        if (empty($defaultConfig)) {
            throw new \Exception(__('Plugin configuration schema does not exist.'));
        }

        return DB::transaction(function () use ($pluginCode, $config, $defaultConfig): bool {
            // Use the same row lock as PluginManager::enable(). Whichever
            // operation obtains the lock second must observe and validate the
            // state committed by the first one.
            $plugin = Plugin::query()
                ->where('code', $pluginCode)
                ->lockForUpdate()
                ->first();
            if (!$plugin) {
                throw new \RuntimeException(__('Plugin does not exist.'));
            }

            $stored = json_decode((string) $plugin->config, true);
            // Preserve valid existing values that were not submitted (notably
            // password fields). Some admin clients only submit changed controls.
            $values = array_intersect_key(is_array($stored) ? $stored : [], $defaultConfig);
            foreach ($config as $key => $value) {
                if (!isset($defaultConfig[$key])) {
                    continue;
                }
                $field = is_array($defaultConfig[$key]) ? $defaultConfig[$key] : [];
                $type = (string) ($field['type'] ?? 'string');
                $isSensitive = $type === 'password' || self::isSensitiveConfigKey((string) $key);
                if ($isSensitive
                    && trim(is_scalar($value) || $value === null ? (string) $value : '') === ''
                    && array_key_exists($key, $values)
                    && trim((string) $values[$key]) !== '') {
                    continue;
                }
                $values[$key] = $this->normalizeValue($value, $type);
            }

            // An enabled plugin must never accept a configuration that could
            // not pass the same activation contract used when it was enabled.
            // Disabled plugins remain configurable so administrators can
            // complete their credentials before activating them.
            if ($plugin->is_enabled) {
                $this->pluginManager->validateActivationConfig($pluginCode, $values);
            }

            $plugin->config = json_encode($values, JSON_THROW_ON_ERROR);
            $plugin->updated_at = now();
            $plugin->saveOrFail();

            return true;
        });
    }

    private function normalizeValue(mixed $value, string $type): mixed
    {
        if ($type === 'boolean' && !is_bool($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }

        if ($type === 'number' && is_numeric($value)) {
            return str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }

        if ($type === 'string' || $type === 'password') {
            return is_scalar($value) || $value === null ? trim((string) $value) : '';
        }

        return $value;
    }

    private function hasNonBlankValue(mixed $value): bool
    {
        return (is_scalar($value) || $value === null)
            && trim((string) $value) !== '';
    }

    /**
     * 获取插件默认配置
     *
     * @param string $pluginCode
     * @return array
     */
    protected function getDefaultConfig(string $pluginCode): array
    {
        $configFile = $this->pluginManager->getPluginPath($pluginCode) . '/config.json';
        if (!File::exists($configFile)) {
            return [];
        }

        $config = json_decode(File::get($configFile), true);
        return $config['config'] ?? [];
    }

    /**
     * 获取数据库中的配置
     *
     * @param string $pluginCode
     * @return array
     */
    public function getDbConfig(string $pluginCode): array
    {
        $plugin = Plugin::query()
            ->where('code', $pluginCode)
            ->first();

        if (!$plugin || empty($plugin->config)) {
            return [];
        }

        $decoded = json_decode($plugin->config, true);
        return is_array($decoded) ? $decoded : [];
    }
}
