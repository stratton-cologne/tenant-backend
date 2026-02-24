<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dashboard_widgets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('widget_key', 120);
            $table->unsignedTinyInteger('x')->default(0);
            $table->unsignedTinyInteger('y')->default(0);
            $table->unsignedTinyInteger('w')->default(3);
            $table->unsignedTinyInteger('h')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->json('config_json')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'widget_key']);
            $table->index(['user_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
    }
};
