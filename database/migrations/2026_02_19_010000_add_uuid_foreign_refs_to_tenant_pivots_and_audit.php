<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('role_permission', function (Blueprint $table): void {
            if (!Schema::hasColumn('role_permission', 'role_uuid')) {
                $table->uuid('role_uuid')->nullable()->after('id');
            }
            if (!Schema::hasColumn('role_permission', 'permission_uuid')) {
                $table->uuid('permission_uuid')->nullable()->after('role_uuid');
            }
        });

        Schema::table('user_role', function (Blueprint $table): void {
            if (!Schema::hasColumn('user_role', 'user_uuid')) {
                $table->uuid('user_uuid')->nullable()->after('id');
            }
            if (!Schema::hasColumn('user_role', 'role_uuid')) {
                $table->uuid('role_uuid')->nullable()->after('user_uuid');
            }
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            if (!Schema::hasColumn('audit_logs', 'user_uuid')) {
                $table->uuid('user_uuid')->nullable()->after('user_id');
                $table->index('user_uuid');
            }
        });

        DB::statement('
            UPDATE role_permission rp
            JOIN roles r ON r.id = rp.role_id
            JOIN permissions p ON p.id = rp.permission_id
            SET rp.role_uuid = r.uuid, rp.permission_uuid = p.uuid
            WHERE rp.role_uuid IS NULL OR rp.permission_uuid IS NULL
        ');

        DB::statement('
            UPDATE user_role ur
            JOIN users u ON u.id = ur.user_id
            JOIN roles r ON r.id = ur.role_id
            SET ur.user_uuid = u.uuid, ur.role_uuid = r.uuid
            WHERE ur.user_uuid IS NULL OR ur.role_uuid IS NULL
        ');

        DB::statement('
            UPDATE audit_logs al
            JOIN users u ON u.id = al.user_id
            SET al.user_uuid = u.uuid
            WHERE al.user_id IS NOT NULL AND al.user_uuid IS NULL
        ');

        Schema::table('role_permission', function (Blueprint $table): void {
            $table->unique(['role_uuid', 'permission_uuid'], 'role_permission_role_uuid_permission_uuid_unique');
        });

        Schema::table('user_role', function (Blueprint $table): void {
            $table->unique(['user_uuid', 'role_uuid'], 'user_role_user_uuid_role_uuid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('user_role', function (Blueprint $table): void {
            $table->dropUnique('user_role_user_uuid_role_uuid_unique');
            $table->dropColumn(['user_uuid', 'role_uuid']);
        });

        Schema::table('role_permission', function (Blueprint $table): void {
            $table->dropUnique('role_permission_role_uuid_permission_uuid_unique');
            $table->dropColumn(['role_uuid', 'permission_uuid']);
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['user_uuid']);
            $table->dropColumn('user_uuid');
        });
    }
};

