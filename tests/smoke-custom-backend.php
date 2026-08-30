<?php

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/plugins-core/CoinPayments/Plugin.php';

use Plugin\CoinPayments\Plugin as CoinPaymentsPlugin;
use App\Services\EncryptedDatabaseBackupService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

function expectSource(string $file, array $needles): void
{
    $source = (string) file_get_contents(dirname(__DIR__) . '/' . $file);
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            fwrite(STDERR, "Missing expected custom behavior in {$file}: {$needle}\n");
            exit(1);
        }
    }
}

$signature = CoinPaymentsPlugin::signature(
    'POST',
    'https://a-api.coinpayments.net/api/v2/merchant/invoices',
    'client-123',
    '2026-08-30T02:22:00',
    '{"amount":"1.00"}',
    'secret-xyz'
);

if (!hash_equals('wAQN/sw1iJTVHmYgXUkPwgnDJjQFtuu+0fmeMFZinN8=', $signature)) {
    fwrite(STDERR, "CoinPayments canonical HMAC signature is incorrect.\n");
    exit(1);
}

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $throwable): never {
    fwrite(STDERR, "Uncaught backend smoke-test error: {$throwable->getMessage()}\n");
    exit(1);
});
$webhookUrl = 'https://payments.example.test/coinpayments/callback';
$timestamp = gmdate('Y-m-d\TH:i:s');
$payload = json_encode([
    'id' => 'invoice-pending-test',
    'type' => 'InvoicePending',
    'invoice' => ['invoiceId' => 'ORDER-PENDING', 'state' => 'Pending'],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$webhookRequest = Request::create($webhookUrl, 'POST', [], [], [], [], $payload);
$webhookRequest->headers->set('Content-Type', 'application/json');
$webhookRequest->headers->set('X-CoinPayments-Client', 'client-123');
$webhookRequest->headers->set('X-CoinPayments-Timestamp', $timestamp);
$webhookRequest->headers->set('X-CoinPayments-Signature', CoinPaymentsPlugin::signature(
    'POST', $webhookUrl, 'client-123', $timestamp, $payload, 'secret-xyz'
));
$app->instance('request', $webhookRequest);
$plugin = new CoinPaymentsPlugin('coin_payments');
$plugin->setConfig([
    'coinpayments_client_id' => 'client-123',
    'coinpayments_client_secret' => 'secret-xyz',
    'coinpayments_webhook_url' => $webhookUrl,
    'coinpayments_webhook_max_age' => 300,
]);
$pendingResult = $plugin->notify([]);
if (!is_string($pendingResult) || !str_contains($pendingResult, 'pending')) {
    fwrite(STDERR, "CoinPayments pending webhook acknowledgement failed.\n");
    exit(1);
}

expectSource('plugins-core/CoinPayments/Plugin.php', [
    '/api/v2/merchant/invoices',
    'X-CoinPayments-Signature',
    "eventType !== 'invoicecompleted'",
    "invoiceState !== 'completed'",
    'invoice.amount.currencyId',
    'invoice.amount.total',
]);

expectSource('app/Services/OrderService.php', [
    "admin_setting('surplus_enable', 0)",
    'if ($order->surplus_amount >= $order->total_amount)',
]);

expectSource('app/Http/Controllers/V1/User/UserController.php', [
    "'locale',",
    "HookManager::call('user.subscribe.reset.after'",
]);

expectSource('app/Http/Controllers/V1/User/ServerController.php', [
    "cookie('luck_locale_manual', '') === '1'",
    '($manualChoice || blank($user->locale))',
]);

expectSource('plugins-core/Telegram/Plugin.php', [
    "'/menu'",
    "'/nodes'",
    "'/setreportgroup'",
    "'/reseller'",
    '(int) $coupon->value !== 100',
    "Log::notice('Telegram reseller created customer'",
    'if (!$actor || (!$actor->is_admin && !$actor->is_staff))',
    "listen('order.open.after'",
    "'/backupdb'",
    "Cache::lock('telegram:database-backup'",
    "app(EncryptedDatabaseBackupService::class)->create",
    'Schedule registration does not call boot()',
]);

expectSource('app/Services/TelegramService.php', [
    'function sendDocument(',
    "->attach('document'",
]);

$backupService = app(EncryptedDatabaseBackupService::class);
$plainPath = tempnam(sys_get_temp_dir(), 'xboard-backup-plain-');
$encryptedPath = $plainPath . '.xbenc';
$decryptedPath = $plainPath . '.restored';
$backupFixture = random_bytes(1024 * 1024 + 317);
file_put_contents($plainPath, $backupFixture);
try {
    $backupService->encryptFile($plainPath, $encryptedPath, 'ci-backup-password-2026');
    $backupService->decryptFile($encryptedPath, $decryptedPath, 'ci-backup-password-2026');
    if (!hash_equals(hash('sha256', $backupFixture), hash_file('sha256', $decryptedPath))) {
        fwrite(STDERR, "Encrypted database backup round-trip failed.\n");
        exit(1);
    }

    $wrongPasswordPath = $plainPath . '.wrong-password';
    $wrongPasswordRejected = false;
    try {
        $backupService->decryptFile($encryptedPath, $wrongPasswordPath, 'wrong-backup-password-2026');
    } catch (\Throwable) {
        $wrongPasswordRejected = true;
    }
    if (!$wrongPasswordRejected || file_exists($wrongPasswordPath)) {
        fwrite(STDERR, "Encrypted database backup accepted a wrong password or left partial output.\n");
        exit(1);
    }
} finally {
    @unlink($plainPath);
    @unlink($encryptedPath);
    @unlink($decryptedPath);
    @unlink($wrongPasswordPath ?? '');
}

foreach (['plugins-core/CoinPayments/config.json', 'plugins-core/Telegram/config.json', 'resources/lang/vi-VN.json'] as $file) {
    $decoded = json_decode((string) file_get_contents(dirname(__DIR__) . '/' . $file), true, 512, JSON_THROW_ON_ERROR);
    if ($file === 'plugins-core/Telegram/config.json') {
        foreach ($decoded['config'] as $field) {
            if (($field['type'] ?? '') === 'select' && !array_is_list($field['options'] ?? [])) {
                fwrite(STDERR, "Telegram select options must be an admin-compatible list.\n");
                exit(1);
            }
        }
    }
}

echo "CoinPayments signing, surplus guard, account locale and Telegram integration checks passed.\n";
