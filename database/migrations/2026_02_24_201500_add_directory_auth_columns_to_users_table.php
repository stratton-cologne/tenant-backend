<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (!Schema::hasColumn('users', 'auth_provider')) {
                $table->string('auth_provider', 20)->default('local')->after('password');
            }

            if (!Schema::hasColumn('users', 'ad_username')) {
                $table->string('ad_username')->nullable()->after('email');
            }

            if (!Schema::hasColumn('users', 'external_directory_id')) {
                $table->string('external_directory_id')->nullable()->after('auth_provider');
            }

            if (!Schema::hasColumn('users', 'external_directory_dn')) {
                $table->text('external_directory_dn')->nullable()->after('external_directory_id');
            }

            if (!Schema::hasColumn('users', 'external_directory_active')) {
                $table->boolean('external_directory_active')->default(true)->after('external_directory_dn');
            }

            if (!Schema::hasColumn('users', 'external_directory_last_sync_at')) {
                $table->timestamp('external_directory_last_sync_at')->nullable()->after('external_directory_active');
            }

            $table->index('auth_provider');
            $table->index('external_directory_id');
            $table->index('ad_username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'auth_provider')) {
                $table->dropIndex(['auth_provider']);
                $table->dropColumn('auth_provider');
            }
            if (Schema::hasColumn('users', 'ad_username')) {
                $table->dropIndex(['ad_username']);
                $table->dropColumn('ad_username');
            }
            if (Schema::hasColumn('users', 'external_directory_id')) {
                $table->dropIndex(['external_directory_id']);
                $table->dropColumn('external_directory_id');
            }
            if (Schema::hasColumn('users', 'external_directory_dn')) {
                $table->dropColumn('external_directory_dn');
            }
            if (Schema::hasColumn('users', 'external_directory_active')) {
                $table->dropColumn('external_directory_active');
            }
            if (Schema::hasColumn('users', 'external_directory_last_sync_at')) {
                $table->dropColumn('external_directory_last_sync_at');
            }
        });
    }
};

