<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'v2_user';
    private const UNIQUE_INDEX = 'v2_user_telegram_id_unique';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, 'telegram_id')) {
            throw new RuntimeException(
                'Cannot safely enforce Telegram binding uniqueness: v2_user.telegram_id is missing.'
            );
        }

        // Never guess which account owns an already duplicated Telegram ID.
        // Stop the release so an administrator can investigate the conflicting
        // rows explicitly before the database constraint is installed.
        $duplicate = DB::table(self::TABLE)
            ->select('telegram_id')
            ->whereNotNull('telegram_id')
            ->groupBy('telegram_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate) {
            throw new RuntimeException(
                'Cannot safely enforce Telegram binding uniqueness: duplicate Telegram account binding exists.'
            );
        }

        if (!Schema::hasIndex(self::TABLE, self::UNIQUE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('telegram_id', self::UNIQUE_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && Schema::hasIndex(self::TABLE, self::UNIQUE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }
    }
};
