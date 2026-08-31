<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const RESELLER_OWNERSHIP_INDEX = 'v2_user_referral_roles_id_idx';

    public function up(): void
    {
        if (!Schema::hasTable('v2_user')) {
            return;
        }

        if (!Schema::hasColumn('v2_user', 'is_reseller')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->boolean('is_reseller')
                    ->default(false)
                    ->after('is_staff');
            });
        }

        // Ownership lists constrain the inviter and all role flags before
        // ordering by id. Leading with invite_user_id also helps referral
        // counts, unlike a low-selectivity standalone boolean index.
        if (!Schema::hasIndex('v2_user', self::RESELLER_OWNERSHIP_INDEX)) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->index(
                    ['invite_user_id', 'is_admin', 'is_staff', 'is_reseller', 'id'],
                    self::RESELLER_OWNERSHIP_INDEX
                );
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_user')) {
            return;
        }

        if (Schema::hasIndex('v2_user', self::RESELLER_OWNERSHIP_INDEX)) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropIndex(self::RESELLER_OWNERSHIP_INDEX);
            });
        }

        if (Schema::hasColumn('v2_user', 'is_reseller')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropColumn('is_reseller');
            });
        }
    }
};
