<?php

namespace App\Services\Plugin;

use App\Models\Plugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PluginManager
{
    protected string $pluginPath;
    protected string $corePluginPath;
    protected array $loadedPlugins = [];
    protected bool $pluginsInitialized = false;
    protected array $configTypesCache = [];

    /**
     * Re-arm initialization after the previous request's hooks were cleared.
     * Octane may keep a warmed/scoped manager object alive between requests.
     */
    public function prepareForRequest(): void
    {
        $this->pluginsInitialized = false;
    }

    public function __construct()
    {
        $this->pluginPath = base_path('plugins');
        $this->corePluginPath = base_path('plugins-core');
    }

    /**
     * 获取插件的命名空间
     */
    public function getPluginNamespace(string $pluginCode): string
    {
        return 'Plugin\\' . Str::studly($pluginCode);
    }

    public function resolvePluginPath(string $pluginCode): ?string
    {
        $dirName = Str::studly($pluginCode);
        $corePath = $this->corePluginPath . '/' . $dirName;
        if (File::isDirectory($corePath)) {
            return $corePath;
        }
        $userPath = $this->pluginPath . '/' . $dirName;
        if (File::isDirectory($userPath)) {
            return $userPath;
        }
        return null;
    }

    public function getPluginPath(string $pluginCode): string
    {
        return $this->resolvePluginPath($pluginCode)
            ?? $this->pluginPath . '/' . Str::studly($pluginCode);
    }

    public function getUserPluginPath(string $pluginCode): string
    {
        return $this->pluginPath . '/' . Str::studly($pluginCode);
    }

    public function isCorePlugin(string $pluginCode): bool
    {
        $dirName = Str::studly($pluginCode);
        return File::isDirectory($this->corePluginPath . '/' . $dirName);
    }

    public function getPluginPaths(): array
    {
        return [$this->corePluginPath, $this->pluginPath];
    }

    /**
     * 加载插件类
     */
    protected function loadPlugin(string $pluginCode): ?AbstractPlugin
    {
        if (isset($this->loadedPlugins[$pluginCode])) {
            return $this->loadedPlugins[$pluginCode];
        }

        $pluginClass = $this->getPluginNamespace($pluginCode) . '\\Plugin';

        if (!class_exists($pluginClass)) {
            $pluginFile = $this->getPluginPath($pluginCode) . '/Plugin.php';
            if (!File::exists($pluginFile)) {
                Log::warning("Plugin class file not found: {$pluginFile}");
                return null;
            }
            require_once $pluginFile;
        }

        if (!class_exists($pluginClass)) {
            Log::error("Plugin class not found: {$pluginClass}");
            return null;
        }

        $plugin = new $pluginClass($pluginCode);
        $this->loadedPlugins[$pluginCode] = $plugin;

        return $plugin;
    }

    /**
     * 注册插件的服务提供者
     */
    protected function registerServiceProvider(string $pluginCode): void
    {
        $providerClass = $this->getPluginNamespace($pluginCode) . '\\Providers\\PluginServiceProvider';

        if (class_exists($providerClass)) {
            app()->register($providerClass);
        }
    }

    /**
     * 加载插件的路由
     */
    protected function loadRoutes(string $pluginCode): void
    {
        $routesPath = $this->getPluginPath($pluginCode) . '/routes';
        if (File::exists($routesPath)) {
            $webRouteFile = $routesPath . '/web.php';
            $apiRouteFile = $routesPath . '/api.php';
            if (File::exists($webRouteFile)) {
                Route::middleware('web')
                    ->namespace($this->getPluginNamespace($pluginCode) . '\\Controllers')
                    ->group(function () use ($webRouteFile) {
                        require $webRouteFile;
                    });
            }
            if (File::exists($apiRouteFile)) {
                Route::middleware('api')
                    ->namespace($this->getPluginNamespace($pluginCode) . '\\Controllers')
                    ->group(function () use ($apiRouteFile) {
                        require $apiRouteFile;
                    });
            }
        }
    }

    /**
     * 加载插件的视图
     */
    protected function loadViews(string $pluginCode): void
    {
        $viewsPath = $this->getPluginPath($pluginCode) . '/resources/views';
        if (File::exists($viewsPath)) {
            View::addNamespace(Str::studly($pluginCode), $viewsPath);
            return;
        }
    }

    /**
     * 注册插件命令
     */
    protected function registerPluginCommands(string $pluginCode, AbstractPlugin $pluginInstance): void
    {
        try {
            // 调用插件的命令注册方法
            $pluginInstance->registerCommands();
        } catch (\Exception $e) {
            Log::error("Failed to register commands for plugin '{$pluginCode}': " . $e->getMessage());
        }
    }

    /**
     * 安装插件
     */
    public function install(string $pluginCode): bool
    {
        $configFile = $this->getPluginPath($pluginCode) . '/config.json';

        if (!File::exists($configFile)) {
            throw new \Exception('Plugin config file not found');
        }

        $config = json_decode(File::get($configFile), true);
        if (!$this->validateConfig($config)) {
            throw new \Exception('Invalid plugin config');
        }

        // 检查插件是否已安装
        if (Plugin::where('code', $pluginCode)->exists()) {
            throw new \Exception('Plugin already installed');
        }

        // 检查依赖
        if (!$this->checkDependencies($config['require'] ?? [])) {
            throw new \Exception('Dependencies not satisfied');
        }

        // 运行数据库迁移
        $this->runMigrations(pluginCode: $pluginCode);

        DB::beginTransaction();
        try {
            // 提取配置默认值
            $defaultValues = $this->extractDefaultConfig($config);

            // 创建插件实例
            $plugin = $this->loadPlugin($pluginCode);

            // 注册到数据库
            Plugin::create([
                'code' => $pluginCode,
                'name' => $config['name'],
                'version' => $config['version'],
                'type' => $config['type'] ?? Plugin::TYPE_FEATURE,
                'is_enabled' => false,
                'config' => json_encode($defaultValues),
                'installed_at' => now(),
            ]);

            // 运行插件安装方法
            if (method_exists($plugin, 'install')) {
                $plugin->install();
            }

            // 发布插件资源
            $this->publishAssets($pluginCode);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e;
        }
    }

    /**
     * 提取插件默认配置
     */
    protected function extractDefaultConfig(array $config): array
    {
        $defaultValues = [];
        if (isset($config['config']) && is_array($config['config'])) {
            foreach ($config['config'] as $key => $item) {
                if (is_array($item)) {
                    $defaultValues[$key] = $item['default'] ?? null;
                } else {
                    $defaultValues[$key] = $item;
                }
            }
        }
        return $defaultValues;
    }

    /**
     * 获取 Migrator 实例并确保迁移仓库存在
     */
    protected function getMigrator(): \Illuminate\Database\Migrations\Migrator
    {
        $migrator = app('migrator');

        if (!$migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        return $migrator;
    }

    /**
     * 运行插件数据库迁移
     */
    protected function runMigrations(string $pluginCode): void
    {
        $migrationsPath = $this->getPluginPath($pluginCode) . '/database/migrations';

        if (File::exists($migrationsPath)) {
            $migrator = $this->getMigrator();
            $migrator->run([$migrationsPath]);
        }
    }

    /**
     * 回滚插件数据库迁移
     */
    protected function runMigrationsRollback(string $pluginCode): void
    {
        $migrationsPath = $this->getPluginPath($pluginCode) . '/database/migrations';

        if (File::exists($migrationsPath)) {
            $migrator = $this->getMigrator();
            $migrator->rollback([$migrationsPath]);
        }
    }

    /**
     * 发布插件资源
     */
    protected function publishAssets(string $pluginCode): void
    {
        $assetsPath = $this->getPluginPath($pluginCode) . '/resources/assets';
        if (File::exists($assetsPath)) {
            $publishPath = public_path('plugins/' . $pluginCode);
            File::ensureDirectoryExists($publishPath);
            File::copyDirectory($assetsPath, $publishPath);
        }
    }

    /**
     * 验证配置文件
     */
    protected function validateConfig(array $config): bool
    {
        $requiredFields = [
            'name',
            'code',
            'version',
            'description',
            'author'
        ];

        foreach ($requiredFields as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                return false;
            }
        }

        // 验证插件代码格式
        if (!preg_match('/^[a-z0-9_]+$/', $config['code'])) {
            return false;
        }

        // 验证版本号格式
        if (!preg_match('/^\d+\.\d+\.\d+$/', $config['version'])) {
            return false;
        }

        // 验证插件类型
        if (isset($config['type'])) {
            $validTypes = ['feature', 'payment'];
            if (!in_array($config['type'], $validTypes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 启用插件
     */
    public function enable(string $pluginCode): bool
    {
        $plugin = $this->loadPlugin($pluginCode);

        if (!$plugin) {
            throw new \Exception('Plugin not found: ' . $pluginCode);
        }

        // Keep configuration validation, registration, the state change and
        // boot in one database transaction. The row lock prevents a concurrent
        // config save from invalidating the exact values being activated.
        DB::transaction(function () use ($pluginCode, $plugin): void {
            $dbPlugin = Plugin::query()
                ->where('code', $pluginCode)
                ->lockForUpdate()
                ->first();

            if (!$dbPlugin) {
                throw new \RuntimeException('Plugin is not installed: ' . $pluginCode);
            }

            if (!empty($dbPlugin->config)) {
                $values = json_decode($dbPlugin->config, true) ?: [];
                $values = $this->castConfigValuesByType($pluginCode, $values);
                $plugin->setConfig($values);
            }

            // Fail closed before registering routes/providers or changing the
            // database status. Payment plugins use this to reject activation
            // until every mandatory credential has been configured.
            $plugin->validateActivation();

            $this->registerServiceProvider($pluginCode);
            $this->loadRoutes($pluginCode);
            $this->loadViews($pluginCode);

            // Keep the historical ordering so boot observes is_enabled=true
            // on this connection. It becomes visible elsewhere only after a
            // successful boot and commit; any Throwable rolls the row back to
            // its exact previous state.
            $dbPlugin->is_enabled = true;
            $dbPlugin->updated_at = now();
            if (!$dbPlugin->save()) {
                throw new \RuntimeException('Failed to enable plugin: ' . $pluginCode);
            }

            $plugin->boot();
        });

        return true;
    }

    /**
     * Validate a candidate configuration without mutating the request-scoped
     * plugin instance that may already have registered hooks for this request.
     */
    public function validateActivationConfig(string $pluginCode, array $config): void
    {
        $plugin = $this->loadPlugin($pluginCode);
        if (!$plugin) {
            throw new \RuntimeException('Plugin not found: ' . $pluginCode);
        }

        $candidate = clone $plugin;
        $candidate->setConfig($this->castConfigValuesByType($pluginCode, $config));
        $candidate->validateActivation();
    }

    /**
     * 禁用插件
     */
    public function disable(string $pluginCode): bool
    {
        $plugin = $this->loadPlugin($pluginCode);
        if (!$plugin) {
            throw new \Exception('Plugin not found');
        }

        Plugin::query()
            ->where('code', $pluginCode)
            ->update([
                'is_enabled' => false,
                'updated_at' => now(),
            ]);

        $plugin->cleanup();

        return true;
    }

    /**
     * 卸载插件
     */
    public function uninstall(string $pluginCode): bool
    {
        $this->disable($pluginCode);
        $this->runMigrationsRollback($pluginCode);
        Plugin::query()->where('code', $pluginCode)->delete();

        return true;
    }

    /**
     * 删除插件
     *
     * @param string $pluginCode
     * @return bool
     * @throws \Exception
     */
    public function delete(string $pluginCode): bool
    {
        if (Plugin::where('code', $pluginCode)->exists()) {
            $this->uninstall($pluginCode);
        }

        if ($this->isCorePlugin($pluginCode)) {
            throw new \Exception(__('Core system plugins cannot be deleted.'));
        }

        $pluginPath = $this->getUserPluginPath($pluginCode);
        if (!File::exists($pluginPath)) {
            throw new \Exception(__('Plugin does not exist.'));
        }

        File::deleteDirectory($pluginPath);

        return true;
    }

    /**
     * 检查依赖关系
     */
    protected function checkDependencies(array $requires): bool
    {
        foreach ($requires as $package => $version) {
            if ($package === 'xboard') {
                // 检查xboard版本
                // 实现版本比较逻辑
            }
        }
        return true;
    }

    /**
     * 升级插件
     *
     * @param string $pluginCode
     * @return bool
     * @throws \Exception
     */
    public function update(string $pluginCode): bool
    {
        $dbPlugin = Plugin::where('code', $pluginCode)->first();
        if (!$dbPlugin) {
            throw new \Exception('Plugin not installed: ' . $pluginCode);
        }

        // 获取插件配置文件中的最新版本
        $configFile = $this->getPluginPath($pluginCode) . '/config.json';
        if (!File::exists($configFile)) {
            throw new \Exception('Plugin config file not found');
        }

        $config = json_decode(File::get($configFile), true);
        if (!$config || !isset($config['version'])) {
            throw new \Exception('Invalid plugin config or missing version');
        }

        $newVersion = $config['version'];
        $oldVersion = $dbPlugin->version;
        $wasEnabled = (bool) $dbPlugin->is_enabled;

        if (version_compare($newVersion, $oldVersion, '<=')) {
            throw new \Exception('Plugin is already up to date');
        }

        // Upgrading a disabled plugin must never silently enable it. This is
        // especially important for payment plugins whose credentials may not
        // have been configured yet.
        if ($wasEnabled) {
            $this->disable($pluginCode);
        }
        $this->runMigrations($pluginCode);

        $plugin = $this->loadPlugin($pluginCode);
            if ($plugin) {
                if (!empty($dbPlugin->config)) {
                    $values = json_decode($dbPlugin->config, true) ?: [];
                    $values = $this->castConfigValuesByType($pluginCode, $values);
                    $plugin->setConfig($values);
                }

                $plugin->update($oldVersion, $newVersion);
            }

        $dbPlugin->update([
            'version' => $newVersion,
            'updated_at' => now(),
        ]);

        if ($wasEnabled) {
            $this->enable($pluginCode);
        }

        return true;
    }

    /**
     * 上传插件
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return bool
     * @throws \Exception
     */
    public function upload($file): bool
    {
        $tmpPath = storage_path('tmp/plugins');
        if (!File::exists($tmpPath)) {
            File::makeDirectory($tmpPath, 0755, true);
        }

        $extractPath = $tmpPath . '/' . uniqid();
        $zip = new \ZipArchive();

        if ($zip->open($file->path()) !== true) {
            throw new \Exception(__('Unable to open the plugin package.'));
        }

        $zip->extractTo($extractPath);
        $zip->close();

        $configFile = File::glob($extractPath . '/*/config.json');
        if (empty($configFile)) {
            $configFile = File::glob($extractPath . '/config.json');
        }

        if (empty($configFile)) {
            File::deleteDirectory($extractPath);
            throw new \Exception(__('Plugin package is invalid: configuration file is missing.'));
        }

        $pluginPath = dirname(reset($configFile));
        $config = json_decode(File::get($pluginPath . '/config.json'), true);

        if (!$this->validateConfig($config)) {
            File::deleteDirectory($extractPath);
            throw new \Exception(__('Plugin configuration file is invalid.'));
        }

        $targetPath = $this->getUserPluginPath($config['code']);
        if (File::exists($targetPath)) {
            $installedConfigPath = $targetPath . '/config.json';
            if (!File::exists($installedConfigPath)) {
                throw new \Exception(__('The installed plugin is missing its configuration file, so its upgrade status cannot be determined.'));
            }
            $installedConfig = json_decode(File::get($installedConfigPath), true);

            $oldVersion = $installedConfig['version'] ?? null;
            $newVersion = $config['version'] ?? null;
            if (!$oldVersion || !$newVersion) {
                throw new \Exception(__('The plugin version is missing, so its upgrade status cannot be determined.'));
            }
            if (version_compare($newVersion, $oldVersion, '<=')) {
                throw new \Exception(__('The uploaded plugin version must be newer than the installed version.'));
            }

            File::deleteDirectory($targetPath);
        }

        File::copyDirectory($pluginPath, $targetPath);
        File::deleteDirectory($pluginPath);
        File::deleteDirectory($extractPath);

        if (Plugin::where('code', $config['code'])->exists()) {
            return $this->update($config['code']);
        }

        return true;
    }

    /**
     * Initializes all enabled plugins from the database.
     * This method ensures that plugins are loaded, and their routes, views,
     * and service providers are registered only once per request cycle.
     */
    public function initializeEnabledPlugins(): void
    {
        if ($this->pluginsInitialized) {
            return;
        }

        $enabledPlugins = Plugin::where('is_enabled', true)->get();

        foreach ($enabledPlugins as $dbPlugin) {
            try {
                $pluginCode = $dbPlugin->code;

                $pluginInstance = $this->loadPlugin($pluginCode);
                if (!$pluginInstance) {
                    continue;
                }

                if (!empty($dbPlugin->config)) {
                    $values = json_decode($dbPlugin->config, true) ?: [];
                    $values = $this->castConfigValuesByType($pluginCode, $values);
                    $pluginInstance->setConfig($values);
                }

                $this->registerServiceProvider($pluginCode);
                $this->loadRoutes($pluginCode);
                $this->loadViews($pluginCode);
                $this->registerPluginCommands($pluginCode, $pluginInstance);

                $pluginInstance->boot();

            } catch (\Exception $e) {
                Log::error("Failed to initialize plugin '{$dbPlugin->code}': " . $e->getMessage());
            }
        }

        $this->pluginsInitialized = true;
    }

    /**
     * Register scheduled tasks for all enabled plugins.
     * Called from Console Kernel. Only loads main plugin class and config for scheduling.
     * Avoids full HTTP/plugin boot overhead.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     */
    public function registerPluginSchedules(Schedule $schedule): void
    {
        Plugin::where('is_enabled', true)
            ->get()
            ->each(function ($dbPlugin) use ($schedule) {
                try {
                    $pluginInstance = $this->loadPlugin($dbPlugin->code);
                    if (!$pluginInstance) {
                        return;
                    }
                    if (!empty($dbPlugin->config)) {
                        $values = json_decode($dbPlugin->config, true) ?: [];
                        $values = $this->castConfigValuesByType($dbPlugin->code, $values);
                        $pluginInstance->setConfig($values);
                    }
                    $pluginInstance->schedule($schedule);

                } catch (\Exception $e) {
                    Log::error("Failed to register schedule for plugin '{$dbPlugin->code}': " . $e->getMessage());
                }
            });
    }

    /**
     * Get all enabled plugin instances.
     *
     * This method ensures that all enabled plugins are initialized and then returns them.
     * It's the central point for accessing active plugins.
     *
     * @return array<AbstractPlugin>
     */
    public function getEnabledPlugins(): array
    {
        $this->initializeEnabledPlugins();

        $enabledPluginCodes = Plugin::where('is_enabled', true)
            ->pluck('code')
            ->all();

        return array_intersect_key($this->loadedPlugins, array_flip($enabledPluginCodes));
    }

    /**
     * Get enabled plugins by type
     */
    public function getEnabledPluginsByType(string $type): array
    {
        $this->initializeEnabledPlugins();

        $enabledPluginCodes = Plugin::where('is_enabled', true)
            ->byType($type)
            ->pluck('code')
            ->all();

        return array_intersect_key($this->loadedPlugins, array_flip($enabledPluginCodes));
    }

    /**
     * Get enabled payment plugins
     */
    public function getEnabledPaymentPlugins(): array
    {
        return $this->getEnabledPluginsByType('payment');
    }

    /**
     * install default plugins
     */
    public static function installDefaultPlugins(): void
    {
        $pluginManager = app(self::class);
        $coreDir = base_path('plugins-core');

        if (!File::isDirectory($coreDir)) {
            return;
        }

        foreach (File::directories($coreDir) as $directory) {
            $configFile = $directory . '/config.json';
            if (!File::exists($configFile)) {
                continue;
            }
            $config = json_decode(File::get($configFile), true);
            $code = $config['code'] ?? null;
            if (!$code) {
                continue;
            }
            if (!Plugin::where('code', $code)->exists()) {
                $pluginManager->install($code);
                if (($config['auto_enable'] ?? true) === true) {
                    $pluginManager->enable($code);
                    Log::info("Installed and enabled core plugin: {$code}");
                } else {
                    Log::info("Installed core plugin in disabled state: {$code}");
                }
            }
        }
    }

    /**
     * 根据 config.json 的类型信息对配置值进行类型转换。
     */
    protected function castConfigValuesByType(string $pluginCode, array $values): array
    {
        $types = $this->getConfigTypes($pluginCode);
        foreach ($values as $key => $value) {
            $type = $types[$key] ?? null;

            if ($type === 'json') {
                if (is_array($value)) {
                    continue;
                }
                
                if (is_string($value) && $value !== '') {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $values[$key] = $decoded;
                    }
                }
            } elseif ($type === 'boolean' && !is_bool($value)) {
                $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed !== null) {
                    $values[$key] = $parsed;
                }
            } elseif ($type === 'number' && is_numeric($value)) {
                $values[$key] = str_contains((string) $value, '.') ? (float) $value : (int) $value;
            }
        }
        return $values;
    }

    /**
     * 读取并缓存插件 config.json 中的键类型映射。
     */
    protected function getConfigTypes(string $pluginCode): array
    {
        if (isset($this->configTypesCache[$pluginCode])) {
            return $this->configTypesCache[$pluginCode];
        }
        $types = [];
        $configFile = $this->getPluginPath($pluginCode) . '/config.json';
        if (File::exists($configFile)) {
            $config = json_decode(File::get($configFile), true);
            $fields = $config['config'] ?? [];
            foreach ($fields as $key => $meta) {
                $types[$key] = is_array($meta) ? ($meta['type'] ?? 'string') : 'string';
            }
        }
        $this->configTypesCache[$pluginCode] = $types;
        return $types;
    }
}
