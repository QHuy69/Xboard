<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Models\Plugin as PluginModel;
use App\Services\Plugin\PluginConfigService;
use App\Services\Plugin\PluginManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(static function (Throwable $throwable): never {
    fwrite(STDERR, "Uncaught plugin-admin smoke-test error: {$throwable->getMessage()}\n");
    exit(1);
});

$manifest = json_decode(
    (string) file_get_contents(dirname(__DIR__) . '/plugins-core/CoinPayments/config.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);

DB::beginTransaction();
try {
    // The published-image smoke environment has every other core plugin
    // installed already. Reinstall only CoinPayments to exercise the
    // auto_enable=false path without touching production data.
    PluginModel::query()->where('code', 'coin_payments')->delete();
    PluginManager::installDefaultPlugins();
    $installed = PluginModel::query()->where('code', 'coin_payments')->firstOrFail();
    if ($installed->is_enabled) {
        throw new RuntimeException('CoinPayments default install did not remain disabled.');
    }

    app(PluginConfigService::class)->updateConfig('coin_payments', [
        'enabled' => 'false',
        'coinpayments_client_secret' => 'preserve-this-secret',
        'coinpayments_webhook_max_age' => '420',
    ]);
    app(PluginConfigService::class)->updateConfig('coin_payments', [
        'coinpayments_client_id' => 'updated-client',
    ]);
    $stored = json_decode((string) $installed->fresh()->config, true, 512, JSON_THROW_ON_ERROR);
    if (($stored['enabled'] ?? null) !== false
        || ($stored['coinpayments_webhook_max_age'] ?? null) !== 420
        || ($stored['coinpayments_client_secret'] ?? null) !== 'preserve-this-secret') {
        throw new RuntimeException('Plugin admin config did not preserve or normalize values.');
    }

    $installed->update(['version' => '2.0.0', 'is_enabled' => false]);
    app(PluginManager::class)->update('coin_payments');
    $updated = $installed->fresh();
    if ($updated->version !== $manifest['version'] || $updated->is_enabled) {
        throw new RuntimeException('CoinPayments upgrade changed the disabled state.');
    }
} finally {
    DB::rollBack();
}

echo "Plugin admin config persistence and disabled-upgrade checks passed.\n";
