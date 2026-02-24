<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'name')) {
            return;
        }

        DB::statement("UPDATE users SET name = TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))) WHERE (name IS NULL OR name = '')");
        DB::statement("ALTER TABLE users MODIFY name VARCHAR(255) NULL");
    }

    public function down(): void
    {
        // noop
    }
};
