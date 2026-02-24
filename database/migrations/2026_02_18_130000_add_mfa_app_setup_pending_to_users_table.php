<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('users') || Schema::hasColumn('users', 'mfa_app_setup_pending')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('mfa_app_setup_pending')->default(false)->after('mfa_secret');
        });
    }

    public function down(): void
    {
        // noop: column may come from base users migration in fresh environments.
    }
};
