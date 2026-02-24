<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('password');
            $table->enum('mfa_type', ['mail', 'app'])->default('mail');
            $table->string('mfa_secret')->nullable();
            $table->boolean('mfa_app_setup_pending')->default(false);
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('temp_password_expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
