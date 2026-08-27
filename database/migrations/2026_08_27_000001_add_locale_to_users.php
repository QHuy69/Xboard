<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_user', 'locale')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->string('locale', 10)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_user', 'locale')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropColumn('locale');
            });
        }
    }
};
