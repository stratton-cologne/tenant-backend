<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('notification_sound_enabled')->default(true)->after('must_change_password');
            $table->boolean('notification_desktop_enabled')->default(true)->after('notification_sound_enabled');
        });

        DB::table('users')->whereNull('notification_sound_enabled')->update([
            'notification_sound_enabled' => true,
        ]);
        DB::table('users')->whereNull('notification_desktop_enabled')->update([
            'notification_desktop_enabled' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('notification_desktop_enabled');
            $table->dropColumn('notification_sound_enabled');
        });
    }
};
