<?php

namespace Tests\Feature;

use App\Services\CoinPaymentsCheckoutSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CoinPaymentsMigrationUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_fails_closed_when_legacy_ready_checkout_has_no_provider_invoice_id(): void
    {
        $this->insertLegacyCheckout(900001, 999991, 'ready', null, time() + 900);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('READY checkout is missing provider_invoice_id');

        $this->runSnapshotMigration();
    }

    public function test_upgrade_fails_closed_when_legacy_ready_checkout_has_no_provider_expiry(): void
    {
        $this->insertLegacyCheckout(900002, 999992, 'ready', 'legacy-invoice-id', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('READY checkout is missing provider_expires_at');

        $this->runSnapshotMigration();
    }

    public function test_upgrade_fails_closed_when_legacy_provider_invoice_identity_is_duplicated(): void
    {
        Schema::table('v2_order_payment_checkout', function (Blueprint $table): void {
            $table->dropUnique('order_payment_checkout_provider_invoice_unique');
        });

        $this->insertLegacyCheckout(900003, 999993, 'failed', 'duplicate-invoice-id', null);
        $this->insertLegacyCheckout(900004, 999994, 'failed', 'duplicate-invoice-id', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate provider invoice identity exists');

        $this->runSnapshotMigration();
    }

    #[DataProvider('malformedLegacyConfigFieldProvider')]
    public function test_upgrade_fails_closed_with_a_deliberate_exception_for_active_malformed_config(
        string $field,
        mixed $value
    ): void {
        $config = array_replace($this->validLegacyConfig(), [$field => $value]);
        $paymentId = $this->insertLegacyPayment($config);
        $this->insertLegacyCheckout(910001, $paymentId, 'creating', null, null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot safely migrate active CoinPayments checkout');

        $this->runSnapshotMigration();
    }

    public static function malformedLegacyConfigFieldProvider(): array
    {
        return [
            'client ID array' => ['coinpayments_client_id', []],
            'client secret array' => ['coinpayments_client_secret', []],
            'invoice currency array' => ['coinpayments_invoice_currency_id', []],
            'payment currency array' => ['coinpayments_payment_currency', []],
            'exchange rate array' => ['coinpayments_cny_invoice_rate', []],
            'API base array' => ['coinpayments_api_base', []],
            'webhook URL array' => ['coinpayments_webhook_url', []],
            'webhook max age array' => ['coinpayments_webhook_max_age', []],
            'client ID boolean' => ['coinpayments_client_id', true],
            'client ID float' => ['coinpayments_client_id', 1.5],
            'client secret boolean' => ['coinpayments_client_secret', true],
            'client secret float' => ['coinpayments_client_secret', 1.5],
            'invoice currency boolean' => ['coinpayments_invoice_currency_id', true],
            'invoice currency float' => ['coinpayments_invoice_currency_id', 1.5],
            'payment currency boolean' => ['coinpayments_payment_currency', true],
            'payment currency float' => ['coinpayments_payment_currency', 1.5],
            'API base boolean' => ['coinpayments_api_base', true],
            'API base float' => ['coinpayments_api_base', 1.5],
            'webhook URL boolean' => ['coinpayments_webhook_url', true],
            'webhook URL float' => ['coinpayments_webhook_url', 1.5],
            'exchange rate boolean' => ['coinpayments_cny_invoice_rate', true],
            'webhook max age boolean' => ['coinpayments_webhook_max_age', true],
            'webhook max age float' => ['coinpayments_webhook_max_age', 300.5],
        ];
    }

    public function test_upgrade_skips_inactive_malformed_config_and_continues_backfill(): void
    {
        $checkoutIds = [];
        $orderId = 920000;
        foreach (self::malformedLegacyConfigFieldProvider() as [$field, $value]) {
            $paymentId = $this->insertLegacyPayment(array_replace(
                $this->validLegacyConfig(),
                [$field => $value]
            ));
            $this->insertLegacyCheckout(++$orderId, $paymentId, 'failed', null, null);
            $checkoutIds[] = (int) DB::table('v2_order_payment_checkout')
                ->where('order_id', $orderId)
                ->value('id');
        }
        $validPaymentId = $this->insertLegacyPayment($this->validLegacyConfig());
        $this->insertLegacyCheckout(++$orderId, $validPaymentId, 'failed', null, null);
        $validCheckoutId = (int) DB::table('v2_order_payment_checkout')
            ->where('order_id', $orderId)
            ->value('id');

        $this->runSnapshotMigration();

        foreach ($checkoutIds as $checkoutId) {
            $checkout = DB::table('v2_order_payment_checkout')->where('id', $checkoutId)->first();
            $this->assertNotNull($checkout);
            $this->assertNull($checkout->config_snapshot);
            $this->assertNull($checkout->expected_amount);
        }
        $this->assertIsString(DB::table('v2_order_payment_checkout')
            ->where('id', $validCheckoutId)
            ->value('config_snapshot'));
    }

    #[DataProvider('blankConfigurationDefaultProvider')]
    public function test_upgrade_uses_runtime_defaults_for_blank_api_base_and_webhook_max_age(
        mixed $apiBase,
        mixed $maxAge
    ): void {
        $paymentId = $this->insertLegacyPayment(array_replace(
            $this->validLegacyConfig(),
            [
                'coinpayments_api_base' => $apiBase,
                'coinpayments_webhook_max_age' => $maxAge,
            ]
        ));
        $orderId = 925000 + $paymentId;
        $this->insertLegacyCheckout($orderId, $paymentId, 'creating', null, null);

        $this->runSnapshotMigration();

        $encrypted = DB::table('v2_order_payment_checkout')
            ->where('order_id', $orderId)
            ->value('config_snapshot');
        $this->assertIsString($encrypted);
        $snapshot = CoinPaymentsCheckoutSnapshot::decrypt($encrypted);
        $this->assertSame('https://a-api.coinpayments.net', $snapshot['coinpayments_api_base']);
        $this->assertSame(300, $snapshot['coinpayments_webhook_max_age']);
    }

    public static function blankConfigurationDefaultProvider(): array
    {
        return [
            'empty strings' => ['', ''],
            'whitespace strings' => ['   ', '   '],
            'null values' => [null, null],
        ];
    }

    public function test_upgrade_does_not_copy_unsafe_legacy_plugin_defaults(): void
    {
        $unsafeDefaults = [
            'coinpayments_client_id' => true,
            'coinpayments_client_secret' => 1.5,
            'coinpayments_invoice_currency_id' => true,
            'coinpayments_payment_currency' => 1.5,
            'coinpayments_cny_invoice_rate' => true,
            'coinpayments_api_base' => 1.5,
            'coinpayments_webhook_url' => true,
            'coinpayments_webhook_max_age' => 300.5,
        ];
        $this->putLegacyPluginConfig($unsafeDefaults);
        $paymentId = $this->insertLegacyPayment([]);

        $this->runSnapshotMigration();

        $storedConfig = json_decode(
            (string) DB::table('v2_payment')->where('id', $paymentId)->value('config'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        foreach (array_keys($unsafeDefaults) as $key) {
            $this->assertArrayNotHasKey($key, $storedConfig);
        }
    }

    #[DataProvider('legacySecretFallbackStateProvider')]
    public function test_upgrade_preserves_effective_global_secret_over_malformed_row_override(
        string $state,
        int $orderId
    ): void {
        $this->putLegacyPluginConfig([
            'display_name' => 'Legacy CoinPayments',
            'coinpayments_client_secret' => 'effective-global-secret',
        ]);
        $paymentId = $this->insertLegacyPayment(array_replace(
            $this->validLegacyConfig(),
            ['coinpayments_client_secret' => []]
        ));
        $this->insertLegacyCheckout($orderId, $paymentId, $state, null, null);

        $this->runSnapshotMigration();

        $storedConfig = json_decode(
            (string) DB::table('v2_payment')->where('id', $paymentId)->value('config'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame(
            'effective-global-secret',
            $storedConfig['coinpayments_client_secret'] ?? null
        );

        $pluginConfig = json_decode(
            (string) DB::table('v2_plugins')->where('code', 'coin_payments')->value('config'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame('Legacy CoinPayments', $pluginConfig['display_name'] ?? null);
        $this->assertArrayNotHasKey('coinpayments_client_secret', $pluginConfig);

        $encrypted = DB::table('v2_order_payment_checkout')
            ->where('order_id', $orderId)
            ->value('config_snapshot');
        $this->assertIsString($encrypted);
        $snapshot = CoinPaymentsCheckoutSnapshot::decrypt($encrypted);
        $this->assertSame(
            'effective-global-secret',
            $snapshot['coinpayments_client_secret']
        );
    }

    public static function legacySecretFallbackStateProvider(): array
    {
        return [
            'inactive failed checkout' => ['failed', 926001],
            'active creating checkout' => ['creating', 926002],
        ];
    }

    #[DataProvider('webhookMaxAgeBoundaryProvider')]
    public function test_upgrade_enforces_exact_webhook_max_age_boundaries(
        int|string $maxAge,
        bool $valid
    ): void
    {
        $paymentId = $this->insertLegacyPayment(array_replace(
            $this->validLegacyConfig(),
            ['coinpayments_webhook_max_age' => $maxAge]
        ));
        $orderId = 930000 + (int) $maxAge;
        $this->insertLegacyCheckout($orderId, $paymentId, 'creating', null, null);

        if (!$valid) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('coinpayments_webhook_max_age must be between 60 and 900');
            $this->runSnapshotMigration();
            return;
        }

        $this->runSnapshotMigration();

        $encrypted = DB::table('v2_order_payment_checkout')
            ->where('order_id', $orderId)
            ->value('config_snapshot');
        $this->assertIsString($encrypted);
        $snapshot = CoinPaymentsCheckoutSnapshot::decrypt($encrypted);
        $this->assertSame((int) $maxAge, $snapshot['coinpayments_webhook_max_age']);
    }

    public static function webhookMaxAgeBoundaryProvider(): array
    {
        return [
            'below minimum' => [59, false],
            'minimum' => [60, true],
            'maximum' => [900, true],
            'minimum numeric string' => ['60', true],
            'maximum numeric string' => ['900', true],
            'above maximum' => [901, false],
        ];
    }

    private function insertLegacyCheckout(
        int $orderId,
        int $paymentId,
        string $state,
        ?string $providerInvoiceId,
        ?int $providerExpiresAt
    ): void {
        $now = time();

        DB::table('v2_order_payment_checkout')->insert([
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'provider' => 'CoinPayments',
            'state' => $state,
            'claim_token' => null,
            'base_amount' => 100,
            'handling_amount' => 0,
            'response_type' => 1,
            'response_data' => 'https://checkout.coinpayments.net/invoices/legacy',
            'attempted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'payment_uuid' => null,
            'config_snapshot' => null,
            'provider_invoice_id' => $providerInvoiceId,
            'provider_expires_at' => $providerExpiresAt,
            'expected_amount' => null,
        ]);
    }

    private function insertLegacyPayment(array $config): int
    {
        $now = time();

        return (int) DB::table('v2_payment')->insertGetId([
            'uuid' => bin2hex(random_bytes(16)),
            'payment' => 'CoinPayments',
            'name' => 'CoinPayments migration fixture',
            'icon' => 'CoinPayments',
            'config' => json_encode(
                $config,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            'notify_domain' => null,
            'handling_fee_fixed' => 0,
            'handling_fee_percent' => 0,
            'enable' => false,
            'sort' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function validLegacyConfig(): array
    {
        return [
            'coinpayments_client_id' => 'legacy-client',
            'coinpayments_client_secret' => 'legacy-secret',
            'coinpayments_invoice_currency_id' => '5057',
            'coinpayments_payment_currency' => '',
            'coinpayments_cny_invoice_rate' => '0.14',
            'coinpayments_api_base' => 'https://a-api.coinpayments.net',
            'coinpayments_webhook_url' => 'https://payments.example.test/coinpayments/callback',
            'coinpayments_webhook_max_age' => 300,
        ];
    }

    private function putLegacyPluginConfig(array $config): void
    {
        DB::table('v2_plugins')->updateOrInsert(
            ['code' => 'coin_payments'],
            [
                'name' => 'CoinPayments',
                'type' => 'payment',
                'version' => '2.4.0',
                'is_enabled' => false,
                'config' => json_encode(
                    $config,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ),
                'installed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function runSnapshotMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_08_31_000005_add_coinpayments_checkout_snapshot.php'
        );

        $migration->up();
    }
}
