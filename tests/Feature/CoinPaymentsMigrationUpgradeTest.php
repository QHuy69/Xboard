<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    private function runSnapshotMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_08_31_000005_add_coinpayments_checkout_snapshot.php'
        );

        $migration->up();
    }
}
