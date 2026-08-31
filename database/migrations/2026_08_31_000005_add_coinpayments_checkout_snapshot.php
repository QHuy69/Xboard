<?php

use App\Services\CoinPaymentsCheckoutSnapshot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'v2_order_payment_checkout';
    private const LEGACY_PAYMENT_CONFIG_KEYS = [
        'coinpayments_client_id',
        'coinpayments_client_secret',
        'coinpayments_invoice_currency_id',
        'coinpayments_payment_currency',
        'coinpayments_cny_invoice_rate',
        'coinpayments_api_base',
        'coinpayments_webhook_url',
        'coinpayments_webhook_max_age',
    ];

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('CoinPayments checkout table is missing. Run all migrations in order.');
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (!Schema::hasColumn(self::TABLE, 'payment_uuid')) {
                $table->string('payment_uuid', 64)->nullable()->after('payment_id');
            }
            if (!Schema::hasColumn(self::TABLE, 'config_snapshot')) {
                $table->text('config_snapshot')->nullable()->after('payment_uuid');
            }
            if (!Schema::hasColumn(self::TABLE, 'provider_invoice_id')) {
                $table->string('provider_invoice_id', 128)->nullable()->after('config_snapshot');
            }
            if (!Schema::hasColumn(self::TABLE, 'provider_expires_at')) {
                $table->integer('provider_expires_at')->nullable()->after('provider_invoice_id');
            }
            if (!Schema::hasColumn(self::TABLE, 'expected_amount')) {
                $table->string('expected_amount', 64)->nullable()->after('provider_expires_at');
            }
        });

        $this->assertReadyCheckoutsHaveProviderMetadata();
        $this->assertNoDuplicateProviderInvoiceIds();
        $this->backfillCoinPaymentsSnapshots();

        $indexNames = array_column(Schema::getIndexes(self::TABLE), 'name');
        Schema::table(self::TABLE, function (Blueprint $table) use ($indexNames): void {
            if (!in_array('order_payment_checkout_payment_state_idx', $indexNames, true)) {
                $table->index(['payment_id', 'state'], 'order_payment_checkout_payment_state_idx');
            }
            if (!in_array('order_payment_checkout_uuid_state_idx', $indexNames, true)) {
                $table->index(['payment_uuid', 'state'], 'order_payment_checkout_uuid_state_idx');
            }
            if (!in_array('order_payment_checkout_provider_invoice_unique', $indexNames, true)) {
                $table->unique(
                    ['provider', 'provider_invoice_id'],
                    'order_payment_checkout_provider_invoice_unique'
                );
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        $indexNames = array_column(Schema::getIndexes(self::TABLE), 'name');
        Schema::table(self::TABLE, function (Blueprint $table) use ($indexNames): void {
            if (in_array('order_payment_checkout_payment_state_idx', $indexNames, true)) {
                $table->dropIndex('order_payment_checkout_payment_state_idx');
            }
            if (in_array('order_payment_checkout_uuid_state_idx', $indexNames, true)) {
                $table->dropIndex('order_payment_checkout_uuid_state_idx');
            }
            if (in_array('order_payment_checkout_provider_invoice_unique', $indexNames, true)) {
                $table->dropUnique('order_payment_checkout_provider_invoice_unique');
            }
            $table->dropColumn([
                'payment_uuid',
                'config_snapshot',
                'provider_invoice_id',
                'provider_expires_at',
                'expected_amount',
            ]);
        });
    }

    private function assertNoDuplicateProviderInvoiceIds(): void
    {
        $duplicate = DB::table(self::TABLE)
            ->select(['provider', 'provider_invoice_id'])
            ->whereNotNull('provider_invoice_id')
            ->groupBy('provider', 'provider_invoice_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate) {
            throw new RuntimeException(
                'Cannot safely migrate CoinPayments checkouts: duplicate provider invoice identity exists.'
            );
        }
    }

    private function assertReadyCheckoutsHaveProviderMetadata(): void
    {
        DB::table(self::TABLE)
            ->select(['id', 'state', 'provider_invoice_id', 'provider_expires_at'])
            ->where('provider', 'CoinPayments')
            ->where('state', 'ready')
            ->orderBy('id')
            ->get()
            ->each(function (object $checkout): void {
                $this->assertReadyProviderMetadata($checkout);
            });
    }

    private function backfillCoinPaymentsSnapshots(): void
    {
        $legacyDefaults = $this->legacyPluginDefaults();
        $this->backfillPaymentConfigurations($legacyDefaults);

        DB::table(self::TABLE)
            ->where('provider', 'CoinPayments')
            ->whereNull('config_snapshot')
            ->orderBy('id')
            ->get()
            ->each(function (object $checkout) use ($legacyDefaults): void {
                $this->assertReadyProviderMetadata($checkout);

                $payment = DB::table('v2_payment')->where('id', $checkout->payment_id)->first();
                if (!$payment) {
                    $this->failIfActive($checkout, 'payment row is missing');
                    return;
                }

                $recordConfig = json_decode((string) $payment->config, true);
                $recordConfig = is_array($recordConfig) ? $recordConfig : [];
                $config = array_replace($legacyDefaults, $recordConfig);

                try {
                    // Validate legacy JSON values before converting them. PHP
                    // otherwise turns arrays into "Array" (or 1 for an int
                    // cast), which can either abort the upgrade with an
                    // ErrorException or create a misleading valid snapshot.
                    $apiBase = $this->scalarConfigString(
                        $config,
                        'coinpayments_api_base',
                        'https://a-api.coinpayments.net'
                    );
                    if ($apiBase === '') {
                        $apiBase = 'https://a-api.coinpayments.net';
                    }

                    $snapshot = [
                        'snapshot_version' => CoinPaymentsCheckoutSnapshot::VERSION,
                        'payment_id' => (int) $payment->id,
                        'payment_uuid' => (string) $payment->uuid,
                        'coinpayments_client_id' => $this->scalarConfigString(
                            $config,
                            'coinpayments_client_id'
                        ),
                        'coinpayments_client_secret' => $this->scalarConfigString(
                            $config,
                            'coinpayments_client_secret'
                        ),
                        'coinpayments_invoice_currency_id' => $this->scalarConfigString(
                            $config,
                            'coinpayments_invoice_currency_id'
                        ),
                        'coinpayments_payment_currency' => $this->scalarConfigString(
                            $config,
                            'coinpayments_payment_currency'
                        ),
                        'coinpayments_cny_invoice_rate' => $this->positiveRate($config),
                        'coinpayments_api_base' => rtrim($apiBase, '/'),
                        'coinpayments_webhook_url' => $this->resolvedWebhookUrl(
                            $config,
                            (string) $payment->uuid,
                            (string) ($payment->notify_domain ?? '')
                        ),
                        'coinpayments_webhook_max_age' => $this->webhookMaxAge($config),
                    ];
                    $encrypted = CoinPaymentsCheckoutSnapshot::encrypt($snapshot);
                    $expectedAmount = CoinPaymentsCheckoutSnapshot::expectedAmount(
                        (int) $checkout->base_amount,
                        isset($checkout->handling_amount) ? (int) $checkout->handling_amount : null,
                        $snapshot['coinpayments_cny_invoice_rate']
                    );
                } catch (Throwable $exception) {
                    $this->failIfActive($checkout, $exception->getMessage());
                    return;
                }

                DB::table(self::TABLE)->where('id', $checkout->id)->update([
                    'payment_uuid' => (string) $payment->uuid,
                    'config_snapshot' => $encrypted,
                    'expected_amount' => $expectedAmount,
                    'updated_at' => time(),
                ]);
            });
    }

    private function assertReadyProviderMetadata(object $checkout): void
    {
        if ((string) $checkout->state !== 'ready') {
            return;
        }

        if (trim((string) ($checkout->provider_invoice_id ?? '')) === '') {
            $this->failIfActive($checkout, 'READY checkout is missing provider_invoice_id');
        }

        $expiresAt = filter_var(
            $checkout->provider_expires_at ?? null,
            FILTER_VALIDATE_INT
        );
        if ($expiresAt === false || $expiresAt <= 0) {
            $this->failIfActive($checkout, 'READY checkout is missing provider_expires_at');
        }
    }

    private function backfillPaymentConfigurations(array $legacyDefaults): void
    {
        $legacyPaymentDefaults = array_intersect_key(
            $legacyDefaults,
            array_flip(self::LEGACY_PAYMENT_CONFIG_KEYS)
        );
        if ($legacyPaymentDefaults === []) {
            return;
        }

        DB::table('v2_payment')
            ->where('payment', 'CoinPayments')
            ->orderBy('id')
            ->get()
            ->each(function (object $payment) use ($legacyPaymentDefaults): void {
                $recordConfig = json_decode((string) $payment->config, true);
                $recordConfig = is_array($recordConfig) ? $recordConfig : [];
                foreach ($legacyPaymentDefaults as $key => $value) {
                    if (!$this->isSafeLegacyDefault((string) $key, $value)) {
                        continue;
                    }

                    if ($key === 'coinpayments_client_secret') {
                        $currentValue = $recordConfig[$key] ?? null;
                        // Runtime versions <= 2.3 removed every non-scalar or
                        // blank password override before applying the global
                        // plugin secret. Mirror that exact fallback here so a
                        // malformed row value cannot become authoritative just
                        // before the effective global secret is removed.
                        if (!$this->hasNonBlankScalarValue($currentValue)) {
                            $recordConfig[$key] = $value;
                        }
                    } elseif (!array_key_exists($key, $recordConfig)) {
                        $recordConfig[$key] = $value;
                    }
                }
                DB::table('v2_payment')->where('id', $payment->id)->update([
                    'config' => json_encode(
                        $recordConfig,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    ),
                    'updated_at' => time(),
                ]);
            });

        // Secrets no longer belong to plugin-global storage. Remove them in
        // the database migration as well as the plugin-version hook so a
        // previously inconsistent version marker cannot skip the cleanup.
        $pluginConfig = array_diff_key($legacyDefaults, array_flip(self::LEGACY_PAYMENT_CONFIG_KEYS));
        DB::table('v2_plugins')->where('code', 'coin_payments')->update([
            'config' => json_encode(
                $pluginConfig,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            'updated_at' => now(),
        ]);
    }

    private function legacyPluginDefaults(): array
    {
        if (!Schema::hasTable('v2_plugins')) {
            return [];
        }

        $plugin = DB::table('v2_plugins')->where('code', 'coin_payments')->first();
        if (!$plugin) {
            return [];
        }

        $config = json_decode((string) $plugin->config, true);
        return is_array($config) ? $config : [];
    }

    private function resolvedWebhookUrl(array $config, string $uuid, string $notifyDomain): string
    {
        $configured = $this->scalarConfigString($config, 'coinpayments_webhook_url');
        if ($configured !== '') {
            return $configured;
        }

        $url = url('/api/v1/guest/payment/notify/CoinPayments/' . rawurlencode($uuid));
        $notifyDomain = rtrim(trim($notifyDomain), '/');
        if ($notifyDomain !== '') {
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                return $notifyDomain . $path;
            }
        }

        return $url;
    }

    private function scalarConfigString(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? $default;
        if (!is_string($value) && !is_int($value)) {
            throw new UnexpectedValueException("CoinPayments configuration {$key} must be text");
        }

        return trim((string) $value);
    }

    private function positiveRate(array $config): string
    {
        $value = $config['coinpayments_cny_invoice_rate'] ?? '';
        if (!is_scalar($value) || is_bool($value) || !is_numeric($value) || (float) $value <= 0) {
            throw new UnexpectedValueException('CoinPayments configuration coinpayments_cny_invoice_rate is invalid');
        }

        return trim((string) $value);
    }

    private function webhookMaxAge(array $config): int
    {
        $value = $config['coinpayments_webhook_max_age'] ?? 300;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $value = 300;
        }
        if (!is_string($value) && !is_int($value)) {
            throw new UnexpectedValueException('CoinPayments configuration coinpayments_webhook_max_age must be text or an integer');
        }

        $maxAge = filter_var($value, FILTER_VALIDATE_INT);
        if ($maxAge === false || $maxAge < 60 || $maxAge > 900) {
            throw new UnexpectedValueException('CoinPayments configuration coinpayments_webhook_max_age must be between 60 and 900');
        }

        return (int) $maxAge;
    }

    private function hasNonBlankScalarValue(mixed $value): bool
    {
        return (is_scalar($value) || $value === null)
            && trim((string) $value) !== '';
    }

    private function isSafeLegacyDefault(string $key, mixed $value): bool
    {
        if ($key === 'coinpayments_cny_invoice_rate') {
            return is_scalar($value)
                && !is_bool($value)
                && is_numeric($value)
                && (float) $value > 0;
        }

        if ($key === 'coinpayments_webhook_max_age') {
            if ($value === null || (is_string($value) && trim($value) === '')) {
                return true;
            }
            if (!is_string($value) && !is_int($value)) {
                return false;
            }

            $maxAge = filter_var($value, FILTER_VALIDATE_INT);

            return $maxAge !== false && $maxAge >= 60 && $maxAge <= 900;
        }

        return is_string($value) || is_int($value) || $value === null;
    }

    private function failIfActive(object $checkout, string $reason): void
    {
        if (in_array((string) $checkout->state, ['creating', 'ready', 'uncertain'], true)) {
            throw new RuntimeException(
                "Cannot safely migrate active CoinPayments checkout {$checkout->id}: {$reason}."
            );
        }
    }
};
