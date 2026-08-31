<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'telegram_webhook_update_receipts';
    private const UNIQUE_INDEX = 'telegram_webhook_receipt_hash_unique';
    private const EXPIRY_INDEX = 'telegram_webhook_receipt_expiry_idx';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->char('receipt_hash', 64);
                $table->timestamp('created_at');
                $table->timestamp('expires_at');
                $table->unique('receipt_hash', self::UNIQUE_INDEX);
                $table->index('expires_at', self::EXPIRY_INDEX);
            });

            return;
        }

        foreach (['receipt_hash', 'created_at', 'expires_at'] as $column) {
            if (!Schema::hasColumn(self::TABLE, $column)) {
                throw new RuntimeException(
                    "Cannot safely use Telegram webhook receipts: missing {$column} column."
                );
            }
        }

        if (!Schema::hasIndex(self::TABLE, self::UNIQUE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('receipt_hash', self::UNIQUE_INDEX);
            });
        }
        if (!Schema::hasIndex(self::TABLE, self::EXPIRY_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index('expires_at', self::EXPIRY_INDEX);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
