<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('module_entitlements', function (Blueprint $table): void {
            if (!Schema::hasColumn('module_entitlements', 'license_key')) {
                $table->string('license_key', 64)->nullable()->after('source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('module_entitlements', function (Blueprint $table): void {
            if (Schema::hasColumn('module_entitlements', 'license_key')) {
                $table->dropColumn('license_key');
            }
        });
    }
};

