<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        $tables = [
            'users',
            'roles',
            'permissions',
            'module_entitlements',
            'tenant_settings',
            'audit_logs',
        ];

        foreach ($tables as $table) {
            if (!Schema::hasColumn($table, 'uuid')) {
                Schema::table($table, function (Blueprint $table): void {
                    $table->uuid('uuid')->nullable()->after('id');
                });
            }
        }

        foreach ($tables as $table) {
            DB::table($table)
                ->whereNull('uuid')
                ->orderBy('id')
                ->lazyById()
                ->each(function (object $row) use ($table): void {
                    DB::table($table)->where('id', (int) $row->id)->update(['uuid' => (string) Str::uuid()]);
                });
        }

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->unique('uuid');
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'audit_logs',
            'tenant_settings',
            'module_entitlements',
            'permissions',
            'roles',
            'users',
        ];

        foreach ($tables as $table) {
            if (Schema::hasColumn($table, 'uuid')) {
                Schema::table($table, function (Blueprint $table): void {
                    $table->dropUnique(['uuid']);
                    $table->dropColumn('uuid');
                });
            }
        }
    }
};

