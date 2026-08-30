<?php

namespace App\Services\Plugin;

use App\Models\Plugin;
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
            $result[$key] = [
                'type' => $item['type'],
                'label' => $item['label'] ?? '',
                'placeholder' => $item['placeholder'] ?? '',
                'description' => $item['description'] ?? '',
                'value' => $dbConfig[$key] ?? $item['default'],
                'options' => $item['options'] ?? []
            ];
        }

        return $result;
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
            throw new \Exception('插件配置结构不存在');
        }
        // Preserve valid existing values that were not submitted (notably
        // password fields). Some admin clients only submit changed controls.
        $values = array_intersect_key($this->getDbConfig($pluginCode), $defaultConfig);
        foreach ($config as $key => $value) {
            if (!isset($defaultConfig[$key])) {
                continue;
            }
            $values[$key] = $this->normalizeValue($value, (string) ($defaultConfig[$key]['type'] ?? 'string'));
        }
        Plugin::query()
            ->where('code', $pluginCode)
            ->update([
                'config' => json_encode($values),
                'updated_at' => now()
            ]);

        return true;
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

        return json_decode($plugin->config, true);
    }
}
