<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('module_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->string('module_slug');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('seats')->default(1);
            $table->string('source')->default('subscription');
            $table->timestamp('valid_until')->nullable();
            $table->timestamps();

            $table->unique(['module_slug', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_entitlements');
    }
};
