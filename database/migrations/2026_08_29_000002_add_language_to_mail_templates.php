<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_mail_templates') || Schema::hasColumn('v2_mail_templates', 'language')) {
            return;
        }

        Schema::table('v2_mail_templates', function (Blueprint $table) {
            // Existing rows contain the original Chinese templates.
            $table->string('language', 10)->default('zh-CN')->after('name');
        });

        Schema::table('v2_mail_templates', function (Blueprint $table) {
            $table->dropUnique('v2_mail_templates_name_unique');
            $table->unique(['name', 'language'], 'v2_mail_templates_name_language_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_mail_templates') || !Schema::hasColumn('v2_mail_templates', 'language')) {
            return;
        }

        Schema::table('v2_mail_templates', function (Blueprint $table) {
            $table->dropUnique('v2_mail_templates_name_language_unique');
            $table->dropColumn('language');
            $table->unique('name', 'v2_mail_templates_name_unique');
        });
    }
};
