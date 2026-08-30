<?php

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/plugins-core/Telegram/Plugin.php';

use App\Services\EncryptedDatabaseBackupService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Plugin\Telegram\Plugin as TelegramPlugin;

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(static function (Throwable $throwable): never {
    fwrite(STDERR, "Uncaught scheduler smoke-test error: {$throwable->getMessage()}\n");
    exit(1);
});

function schedulerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Construct the plugin schedule with every Telegram callback enabled. This
// catches Laravel's runtime requirement that guarded callback events must be
// named before withoutOverlapping()/onOneServer() are attached.
$schedule = new Schedule(config('app.timezone'));
$telegram = new TelegramPlugin('telegram');
$telegram->setConfig([
    'enable_node_group_report' => true,
    'node_report_interval_minutes' => '5',
    'enable_database_backup' => true,
    'database_backup_time' => '02:45',
]);
$telegram->schedule($schedule);

$events = [];
foreach ($schedule->events() as $event) {
    $events[$event->getSummaryForDisplay()] = $event;
}

schedulerAssert(isset($events['telegram-node-group-report']), 'Telegram node report was not registered.');
schedulerAssert(isset($events['telegram-database-backup']), 'Telegram database backup was not registered.');

$nodeReport = $events['telegram-node-group-report'];
schedulerAssert($nodeReport->expression === '*/5 * * * *', 'Telegram node report interval is incorrect.');
schedulerAssert($nodeReport->withoutOverlapping === true, 'Telegram node report overlap guard is missing.');
schedulerAssert($nodeReport->onOneServer === true, 'Telegram node report one-server guard is missing.');

$databaseBackup = $events['telegram-database-backup'];
schedulerAssert($databaseBackup->expression === '45 2 * * *', 'Telegram database backup time is incorrect.');
schedulerAssert($databaseBackup->withoutOverlapping === true, 'Telegram database backup overlap guard is missing.');
schedulerAssert($databaseBackup->onOneServer === true, 'Telegram database backup one-server guard is missing.');

// Exercise the actual SQLite dump -> gzip -> AES-GCM path used by the daily
// job. No Telegram request is made; the restored dump stays inside the smoke
// container and is removed before the test exits.
schedulerAssert(config('database.default') === 'sqlite', 'Scheduler smoke environment must use SQLite.');
$encryptedPath = null;
$restoredGzipPath = null;

try {
    $backupService = app(EncryptedDatabaseBackupService::class);
    $encryptedPath = $backupService->create('ci-scheduler-backup-password-2026');
    schedulerAssert(is_file($encryptedPath) && filesize($encryptedPath) > 64, 'Encrypted database backup was not created.');

    $restoredGzipPath = $encryptedPath . '.restored.gz';
    $backupService->decryptFile($encryptedPath, $restoredGzipPath, 'ci-scheduler-backup-password-2026');
    $stream = gzopen($restoredGzipPath, 'rb');
    schedulerAssert($stream !== false, 'Decrypted database dump is not valid gzip data.');

    $sql = '';
    while (!gzeof($stream) && strlen($sql) < 4 * 1024 * 1024) {
        $chunk = gzread($stream, 64 * 1024);
        schedulerAssert($chunk !== false, 'Could not read the restored database dump.');
        $sql .= $chunk;
    }
    gzclose($stream);

    schedulerAssert(str_contains($sql, 'CREATE TABLE'), 'Restored database dump does not contain schema SQL.');
} finally {
    if (is_string($encryptedPath)) @unlink($encryptedPath);
    if (is_string($restoredGzipPath)) @unlink($restoredGzipPath);
}

echo "Dedicated scheduler, Telegram schedules and encrypted SQLite backup passed.\n";
