<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_settings')) {
            return;
        }

        $now = now();
        foreach ([
            'email_verify' => '1',
            'secure_path' => 'Huy2006',
        ] as $name => $value) {
            $query = DB::table('v2_settings')->where('name', $name);
            if ($query->exists()) {
                $query->update(['value' => $value, 'updated_at' => $now]);
            } else {
                DB::table('v2_settings')->insert([
                    'name' => $name,
                    'value' => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        try {
            // Follow the active cache driver so fresh installs and CI can use
            // the array store without attempting a Redis connection.
            Cache::forget('admin_settings');
        } catch (\Throwable) {
            // The database values are authoritative; cache can be cleared later.
        }
    }

    public function down(): void
    {
        // These are instance settings. Do not guess and overwrite the values
        // that existed before this migration during a rollback.
    }
};
