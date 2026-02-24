<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('role_permission', function (Blueprint $table): void {
            $table->timestamps();
        });

        Schema::table('user_role', function (Blueprint $table): void {
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('role_permission', function (Blueprint $table): void {
            $table->dropColumn(['created_at', 'updated_at']);
        });

        Schema::table('user_role', function (Blueprint $table): void {
            $table->dropColumn(['created_at', 'updated_at']);
        });
    }
};
