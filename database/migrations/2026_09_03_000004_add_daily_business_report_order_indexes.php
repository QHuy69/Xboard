<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'v2_order';
    private const PAID_STATUS_INDEX = 'v2_order_paid_at_status_idx';
    private const COUPON_PAID_STATUS_INDEX = 'v2_order_coupon_paid_at_status_idx';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('Cannot add daily business report indexes: v2_order is missing.');
        }

        foreach (['paid_at', 'status', 'coupon_id'] as $column) {
            if (!Schema::hasColumn(self::TABLE, $column)) {
                throw new RuntimeException(
                    "Cannot add daily business report indexes: v2_order.{$column} is missing."
                );
            }
        }

        if (!Schema::hasIndex(self::TABLE, self::PAID_STATUS_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['paid_at', 'status'], self::PAID_STATUS_INDEX);
            });
        }

        if (!Schema::hasIndex(self::TABLE, self::COUPON_PAID_STATUS_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(
                    ['coupon_id', 'paid_at', 'status'],
                    self::COUPON_PAID_STATUS_INDEX
                );
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        if (Schema::hasIndex(self::TABLE, self::COUPON_PAID_STATUS_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::COUPON_PAID_STATUS_INDEX);
            });
        }

        if (Schema::hasIndex(self::TABLE, self::PAID_STATUS_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::PAID_STATUS_INDEX);
            });
        }
    }
};
