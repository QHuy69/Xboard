<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Models\Plugin as PluginModel;
use App\Models\Payment;
use App\Http\Controllers\V2\Admin\PaymentController as AdminPaymentController;
use App\Exceptions\ApiException;
use App\Services\PaymentService;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\PluginConfigService;
use App\Services\Plugin\PluginManager;
use App\Services\Plugin\HookManager;
use App\Http\Middleware\InitializePlugins;
use Illuminate\Http\Request;
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

    $configService = app(PluginConfigService::class);
    $pluginManager = app(PluginManager::class);

    $configService->updateConfig('coin_payments', [
        'enabled' => 'false',
        'coinpayments_client_secret' => 'preserve-this-secret',
        'coinpayments_webhook_max_age' => '420',
    ]);
    $configService->updateConfig('coin_payments', [
        'coinpayments_client_id' => 'updated-client',
        'coinpayments_client_secret' => '',
    ]);
    $stored = json_decode((string) $installed->fresh()->config, true, 512, JSON_THROW_ON_ERROR);
    if (($stored['enabled'] ?? null) !== false
        || ($stored['coinpayments_webhook_max_age'] ?? null) !== 420
        || ($stored['coinpayments_client_secret'] ?? null) !== 'preserve-this-secret') {
        throw new RuntimeException('Plugin admin config did not preserve or normalize values.');
    }

    $adminConfig = $configService->getConfig('coin_payments');
    if (($adminConfig['coinpayments_client_secret']['value'] ?? null) !== ''
        || ($adminConfig['coinpayments_client_secret']['has_value'] ?? false) !== true) {
        throw new RuntimeException('Plugin admin API exposed a stored secret or lost its presence marker.');
    }

    try {
        $pluginManager->enable('coin_payments');
        throw new RuntimeException('CoinPayments activated without the mandatory configuration.');
    } catch (InvalidArgumentException $exception) {
        if (!str_contains($exception->getMessage(), 'CoinPayments')) {
            throw $exception;
        }
    }
    if ($installed->fresh()->is_enabled) {
        throw new RuntimeException('Failed CoinPayments activation changed the enabled state.');
    }

    $configService->updateConfig('coin_payments', [
        'enabled' => true,
        'coinpayments_invoice_currency_id' => '5057',
        'coinpayments_cny_invoice_rate' => '0.14',
        'coinpayments_api_base' => 'https://a-api.coinpayments.net',
    ]);
    $pluginManager->enable('coin_payments');
    if (!$installed->fresh()->is_enabled) {
        throw new RuntimeException('Valid CoinPayments configuration could not be explicitly activated.');
    }

    $validEnabledConfig = json_decode(
        (string) $installed->fresh()->config,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    try {
        $configService->updateConfig('coin_payments', [
            'coinpayments_api_base' => 'http://insecure.invalid',
        ]);
        throw new RuntimeException('An enabled plugin accepted an invalid replacement configuration.');
    } catch (InvalidArgumentException $exception) {
        if (!str_contains($exception->getMessage(), 'HTTPS')) {
            throw $exception;
        }
    }
    $afterRejectedConfig = $installed->fresh();
    if (!$afterRejectedConfig->is_enabled
        || json_decode((string) $afterRejectedConfig->config, true, 512, JSON_THROW_ON_ERROR) !== $validEnabledConfig) {
        throw new RuntimeException('Rejected enabled-plugin config was persisted or disabled the plugin.');
    }

    $configService->updateConfig('coin_payments', ['display_name' => 'CoinPayments verified']);
    $afterValidConfig = json_decode(
        (string) $installed->fresh()->config,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (($afterValidConfig['display_name'] ?? null) !== 'CoinPayments verified') {
        throw new RuntimeException('An enabled plugin rejected a valid replacement configuration.');
    }

    $payment = Payment::query()->create([
        'name' => 'CoinPayments smoke',
        'icon' => 'CoinPayments',
        'payment' => 'CoinPayments',
        'uuid' => 'cpsmoke1',
        'enable' => true,
        'config' => [
            'coinpayments_client_id' => 'payment-specific-client',
            'coinpayments_client_secret' => 'payment-specific-secret',
            'coinpayments_payment_currency' => '',
        ],
    ]);
    $paymentService = new PaymentService('CoinPayments', $payment->id);
    $paymentForm = $paymentService->form();
    if (($paymentForm['coinpayments_client_secret']['value'] ?? null) !== ''
        || ($paymentForm['coinpayments_client_secret']['has_value'] ?? false) !== true) {
        throw new RuntimeException('Payment form exposed or lost the plugin-global CoinPayments secret.');
    }
    if (($paymentForm['coinpayments_client_id']['value'] ?? null) !== 'payment-specific-client') {
        throw new RuntimeException('Payment-specific CoinPayments config was not applied.');
    }

    foreach ([
        static fn () => new PaymentService('DifferentGateway', $payment->id),
        static fn () => new PaymentService('DifferentGateway', null, $payment->uuid),
    ] as $mismatchedPaymentFactory) {
        try {
            $mismatchedPaymentFactory();
            throw new RuntimeException('A payment record was mixed with a different gateway method.');
        } catch (ApiException $exception) {
            if (!str_contains($exception->getMessage(), 'Payment method')) {
                throw $exception;
            }
        }
    }

    $secondPayment = Payment::query()->create([
        'name' => 'CoinPayments isolated smoke',
        'icon' => 'CoinPayments',
        'payment' => 'CoinPayments',
        'uuid' => 'cpsmoke2',
        'enable' => true,
        'config' => [
            // An old admin form may have persisted an empty override. It must
            // fall back to the plugin-global secret, not disable the gateway.
            'coinpayments_client_secret' => '',
        ],
    ]);
    $secondPaymentForm = (new PaymentService('CoinPayments', $secondPayment->id))->form();
    if (($secondPaymentForm['coinpayments_client_id']['value'] ?? null) !== 'updated-client') {
        throw new RuntimeException('One payment record leaked its config into another payment record.');
    }

    $redacted = $paymentService->redactPasswordConfig([
        'coinpayments_client_secret' => 'must-not-leak',
        'coinpayments_client_id' => 'visible-client',
        'legacy_webhook_key' => 'legacy-must-not-leak',
        'public_key' => 'public-verification-key',
    ]);
    if (($redacted['coinpayments_client_secret'] ?? null) !== ''
        || ($redacted['legacy_webhook_key'] ?? null) !== ''
        || ($redacted['coinpayments_client_id'] ?? null) !== 'visible-client'
        || ($redacted['public_key'] ?? null) !== 'public-verification-key') {
        throw new RuntimeException('Payment-list config redaction changed the wrong fields.');
    }
    foreach ([
        'key',
        'coinbase_webhook_key',
        'sepay_api_key',
        'mgate_app_secret',
        'legacyApiKey',
        'clientSecret',
        'accessToken',
        'merchantCredentials',
    ] as $legacySecretKey) {
        if (!PaymentService::isSensitiveConfigKey($legacySecretKey)
            || !PluginConfigService::isSensitiveConfigKey($legacySecretKey)) {
            throw new RuntimeException("Legacy payment secret {$legacySecretKey} was not classified as sensitive.");
        }
    }
    if (PaymentService::isSensitiveConfigKey('public_key')) {
        throw new RuntimeException('A public verification key was incorrectly classified as secret.');
    }
    $knownOnly = $paymentService->onlyKnownConfigFields([
        'coinpayments_client_id' => 'known-client',
        'foreign_gateway_secret' => 'must-be-dropped',
    ]);
    if (($knownOnly['coinpayments_client_id'] ?? null) !== 'known-client'
        || array_key_exists('foreign_gateway_secret', $knownOnly)) {
        throw new RuntimeException('Unknown or cross-gateway payment config was persisted.');
    }

    $paymentListPayload = (new AdminPaymentController())->fetch()->getData(true);
    $listedPayment = collect($paymentListPayload['data'] ?? [])->firstWhere('id', $payment->id);
    if (!is_array($listedPayment)
        || data_get($listedPayment, 'config.coinpayments_client_secret') !== '') {
        throw new RuntimeException('Payment admin fetch exposed a stored CoinPayments secret.');
    }

    $preserved = $paymentService->preserveBlankPasswords(
        ['coinpayments_client_secret' => '', 'coinpayments_client_id' => 'changed-client'],
        ['coinpayments_client_secret' => 'per-payment-secret']
    );
    if (($preserved['coinpayments_client_secret'] ?? null) !== 'per-payment-secret') {
        throw new RuntimeException('Blank payment secret did not preserve the existing per-payment value.');
    }
    $globalFallback = $paymentService->preserveBlankPasswords(
        ['coinpayments_client_secret' => ''],
        []
    );
    if (array_key_exists('coinpayments_client_secret', $globalFallback)) {
        throw new RuntimeException('Blank payment secret overrode the plugin-global secret.');
    }

    $pluginManager->disable('coin_payments');

    // A plugin that throws after is_enabled is written must leave no committed
    // enabled state behind. Inject a failing instance into the manager cache so
    // the regression covers the real enable lifecycle without test-only files.
    $managerReflection = new ReflectionObject($pluginManager);
    $loadedPluginsProperty = $managerReflection->getProperty('loadedPlugins');
    $loadedPlugins = $loadedPluginsProperty->getValue($pluginManager);
    $originalCoinPaymentsPlugin = $loadedPlugins['coin_payments'] ?? null;
    $loadedPlugins['coin_payments'] = new class('coin_payments') extends AbstractPlugin {
        public function boot(): void
        {
            throw new RuntimeException('Intentional plugin boot failure.');
        }
    };
    $loadedPluginsProperty->setValue($pluginManager, $loadedPlugins);
    try {
        $pluginManager->enable('coin_payments');
        throw new RuntimeException('Failing plugin boot unexpectedly succeeded.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'Intentional plugin boot failure.') {
            throw $exception;
        }
    } finally {
        $loadedPlugins = $loadedPluginsProperty->getValue($pluginManager);
        if ($originalCoinPaymentsPlugin instanceof AbstractPlugin) {
            $loadedPlugins['coin_payments'] = $originalCoinPaymentsPlugin;
        } else {
            unset($loadedPlugins['coin_payments']);
        }
        $loadedPluginsProperty->setValue($pluginManager, $loadedPlugins);
    }
    if ($installed->fresh()->is_enabled) {
        throw new RuntimeException('Failed plugin boot left the database enabled.');
    }

    $missingPlugin = PluginModel::query()->create([
        'code' => 'missing_smoke_plugin',
        'name' => 'Missing smoke plugin',
        'version' => '1.0.0',
        'type' => PluginModel::TYPE_FEATURE,
        'is_enabled' => false,
        'config' => json_encode(['secret' => 'preserve-me'], JSON_THROW_ON_ERROR),
        'installed_at' => now(),
    ]);
    try {
        $pluginManager->enable($missingPlugin->code);
        throw new RuntimeException('A missing plugin file unexpectedly enabled.');
    } catch (Exception $exception) {
        if (!str_contains($exception->getMessage(), 'Plugin not found')) {
            throw $exception;
        }
    }
    $missingPluginAfterFailure = PluginModel::query()->find($missingPlugin->id);
    if (!$missingPluginAfterFailure
        || $missingPluginAfterFailure->is_enabled
        || $missingPluginAfterFailure->config !== $missingPlugin->config) {
        throw new RuntimeException('A missing plugin file deleted or changed its persisted configuration.');
    }

    $installed->update(['version' => '2.0.0', 'is_enabled' => false]);
    $pluginManager->update('coin_payments');
    $updated = $installed->fresh();
    if ($updated->version !== $manifest['version'] || $updated->is_enabled) {
        throw new RuntimeException('CoinPayments upgrade changed the disabled state.');
    }

    $requestPluginManager = new class extends PluginManager {
        public bool $enabledForRequest = true;

        public function initializeEnabledPlugins(): void
        {
            if ($this->enabledForRequest) {
                HookManager::registerFilter(
                    'smoke.request.plugin',
                    static fn (int $value): int => $value + 1
                );
            }
        }
    };
    $middleware = new InitializePlugins($requestPluginManager);
    HookManager::registerFilter('smoke.request.plugin', static fn (int $value): int => $value + 100);
    $firstRequestValue = $middleware->handle(
        Request::create('/smoke-plugin-request-one'),
        static fn () => HookManager::filter('smoke.request.plugin', 0)
    );
    if ($firstRequestValue !== 1) {
        throw new RuntimeException('A stale Octane hook was duplicated into the next request.');
    }
    $requestPluginManager->enabledForRequest = false;
    $secondRequestValue = $middleware->handle(
        Request::create('/smoke-plugin-request-two'),
        static fn () => HookManager::filter('smoke.request.plugin', 0)
    );
    if ($secondRequestValue !== 0) {
        throw new RuntimeException('A disabled plugin hook remained active in the next request.');
    }
    HookManager::reset();
} finally {
    DB::rollBack();
}

echo "Plugin admin config persistence and disabled-upgrade checks passed.\n";
