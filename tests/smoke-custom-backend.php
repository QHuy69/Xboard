<?php

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/plugins-core/CoinPayments/Plugin.php';
require_once dirname(__DIR__) . '/plugins-core/Crisp/Plugin.php';
require_once dirname(__DIR__) . '/plugins-core/Messenger/Plugin.php';

use Plugin\CoinPayments\Plugin as CoinPaymentsPlugin;
use Plugin\Crisp\Plugin as CrispPlugin;
use Plugin\Messenger\Plugin as MessengerPlugin;
use App\Services\EncryptedDatabaseBackupService;
use App\Services\Plugin\HookManager;
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

HookManager::reset();
$unconfiguredCoinPayments = new CoinPaymentsPlugin('coin_payments');
$unconfiguredCoinPayments->boot();
if (!array_key_exists('CoinPayments', HookManager::filter('available_payment_methods', []))) {
    fwrite(STDERR, "Enabled CoinPayments was not exposed to the payment editor before credential entry.\n");
    exit(1);
}

HookManager::reset();
$configuredCoinPayments = new CoinPaymentsPlugin('coin_payments');
$configuredCoinPayments->setConfig([
    'enabled' => 'true',
    'coinpayments_client_id' => 'client-123',
    'coinpayments_client_secret' => 'secret-xyz',
    'coinpayments_invoice_currency_id' => '5057',
    'coinpayments_cny_invoice_rate' => '0.14',
]);
$configuredCoinPayments->boot();
if (!array_key_exists('CoinPayments', HookManager::filter('available_payment_methods', []))) {
    fwrite(STDERR, "Configured CoinPayments was not registered.\n");
    exit(1);
}

$validCoinPaymentsConfig = [
    'coinpayments_client_id' => 'client-123',
    'coinpayments_client_secret' => 'secret-xyz',
    'coinpayments_invoice_currency_id' => '5057',
    'coinpayments_cny_invoice_rate' => '0.14',
    'coinpayments_api_base' => 'https://a-api.coinpayments.net',
    'coinpayments_webhook_url' => 'https://payments.example.test/coinpayments/callback',
];
foreach ([
    'coinpayments_client_id',
    'coinpayments_client_secret',
    'coinpayments_invoice_currency_id',
    'coinpayments_cny_invoice_rate',
    'coinpayments_api_base',
    'coinpayments_webhook_url',
] as $malformedField) {
    $malformed = new CoinPaymentsPlugin('coin_payments');
    $malformed->setConfig(array_replace($validCoinPaymentsConfig, [$malformedField => []]));
    try {
        $malformed->validatePaymentConfiguration();
        fwrite(STDERR, "CoinPayments accepted a non-scalar {$malformedField}.\n");
        exit(1);
    } catch (InvalidArgumentException) {
        // Expected: crafted array/object values cannot become the string
        // "Array" or a positive numeric cast inside request signing.
    }
}

HookManager::reset();
$crisp = new CrispPlugin('crisp');
$crisp->setConfig(['website_id' => 'invalid-script-value']);
$crisp->boot();
if (HookManager::filter('theme.support.crisp.website_id', 'fallback-crisp') !== 'fallback-crisp') {
    fwrite(STDERR, "Crisp accepted an invalid Website ID.\n");
    exit(1);
}
HookManager::reset();
$crisp->setConfig(['website_id' => '123e4567-e89b-42d3-a456-426614174000']);
$crisp->boot();
if (HookManager::filter('theme.support.crisp.website_id', '') !== '123e4567-e89b-42d3-a456-426614174000') {
    fwrite(STDERR, "Crisp did not expose a valid Website ID.\n");
    exit(1);
}
HookManager::reset();
$messenger = new MessengerPlugin('messenger');
$messenger->setConfig(['page_username' => 'invalid/value']);
$messenger->boot();
if (HookManager::filter('theme.support.messenger.page_username', 'fallback-page') !== 'fallback-page') {
    fwrite(STDERR, "Messenger accepted an invalid Page username.\n");
    exit(1);
}
HookManager::reset();
$messenger->setConfig(['page_username' => 'zaoguang.support']);
$messenger->boot();
if (HookManager::filter('theme.support.messenger.page_username', '') !== 'zaoguang.support') {
    fwrite(STDERR, "Messenger did not expose a valid Page username.\n");
    exit(1);
}
HookManager::reset();
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
if ($pendingResult !== 'success') {
    fwrite(STDERR, "CoinPayments pending webhook acknowledgement failed.\n");
    exit(1);
}

expectSource('plugins-core/CoinPayments/Plugin.php', [
    '/api/v2/merchant/invoices',
    'X-CoinPayments-Signature',
    "'currency' => \$invoiceCurrencyId",
    "eventType !== 'invoicecompleted'",
    "invoiceState !== 'completed'",
    'invoice.amount.currencyId',
    'invoice.amount.total',
]);

expectSource('plugins-core/Crisp/Plugin.php', [
    "theme.support.crisp.website_id",
    "getConfig('website_id'",
]);

expectSource('plugins-core/Messenger/Plugin.php', [
    "theme.support.messenger.page_username",
    "getConfig('page_username'",
]);

expectSource('app/Services/PaymentService.php', [
    'clone $plugin',
    'redactPasswordConfig',
    'preserveBlankPasswords',
    'onlyKnownConfigFields',
    'isSensitiveConfigKey',
    "'value' => \$isSensitive ? '' : \$storedValue",
]);

expectSource('app/Http/Controllers/V2/Admin/PaymentController.php', [
    'redactPasswordConfig',
    'preserveBlankPasswords',
    'redactSensitiveConfigFallback',
    'if (!$sameGateway)',
]);

expectSource('app/Http/Controllers/V1/Guest/PaymentController.php', [
    'catch (ApiException $e)',
    "return \$this->fail([\$status, __('Payment gateway request failed')])",
    'catch (\\JsonException $e)',
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

foreach (['plugins-core/CoinPayments/config.json', 'plugins-core/Telegram/config.json', 'plugins-core/Crisp/config.json', 'plugins-core/Messenger/config.json', 'resources/lang/vi-VN.json'] as $file) {
    $decoded = json_decode((string) file_get_contents(dirname(__DIR__) . '/' . $file), true, 512, JSON_THROW_ON_ERROR);
    if (isset($decoded['config'])) {
        foreach ($decoded['config'] as $key => $field) {
            if (!is_array($field)
                || !isset($field['type'], $field['default'], $field['label'])
                || !in_array($field['type'], ['string', 'password', 'boolean', 'number', 'select', 'json'], true)) {
                fwrite(STDERR, "Plugin config field {$file}:{$key} is not admin-compatible.\n");
                exit(1);
            }
        }
    }
    if ($file === 'plugins-core/Telegram/config.json') {
        foreach ($decoded['config'] as $field) {
            if (($field['type'] ?? '') === 'select' && !array_is_list($field['options'] ?? [])) {
                fwrite(STDERR, "Telegram select options must be an admin-compatible list.\n");
                exit(1);
            }
        }
    }
}

$coinPaymentsManifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/plugins-core/CoinPayments/config.json'), true, 512, JSON_THROW_ON_ERROR);
if (($coinPaymentsManifest['auto_enable'] ?? true) !== false) {
    fwrite(STDERR, "CoinPayments must still require explicit administrator activation.\n");
    exit(1);
}
foreach (['coinpayments_client_id', 'coinpayments_client_secret', 'coinpayments_invoice_currency_id', 'coinpayments_webhook_url'] as $field) {
    if (isset($coinPaymentsManifest['config'][$field])) {
        fwrite(STDERR, "CoinPayments plugin settings still expose payment credential field {$field}.\n");
        exit(1);
    }
}
if (isset($coinPaymentsManifest['config']['coinpayments_invoice_currency'])) {
    fwrite(STDERR, "CoinPayments still exposes the obsolete symbol-based invoice currency field.\n");
    exit(1);
}
$coinPaymentsForm = (new CoinPaymentsPlugin('coin_payments'))->form();
if (isset($coinPaymentsForm['coinpayments_invoice_currency'])
    || !isset($coinPaymentsForm['coinpayments_invoice_currency_id'])) {
    fwrite(STDERR, "CoinPayments form does not use the canonical invoice currency ID field.\n");
    exit(1);
}
foreach ($coinPaymentsForm as $field => $_meta) {
    if ($field !== 'display_name' && isset($coinPaymentsManifest['config'][$field])) {
        fwrite(STDERR, "CoinPayments payment field {$field} is duplicated in plugin-global settings.\n");
        exit(1);
    }
}

$coinPaymentsSource = (string) file_get_contents(dirname(__DIR__) . '/plugins-core/CoinPayments/Plugin.php');
if (str_contains($coinPaymentsSource, '->retry(')) {
    fwrite(STDERR, "CoinPayments invoice creation must not automatically retry a non-idempotent POST.\n");
    exit(1);
}

echo "CoinPayments signing, surplus guard, account locale and Telegram integration checks passed.\n";
