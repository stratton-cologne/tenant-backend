<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ticket_types', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('tenant_id')->index();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('ticket_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('tenant_id')->index();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('ticket_tags', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('tenant_id')->index();
            $table->string('name');
            $table->string('slug');
            $table->string('color', 32)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('ticket_queues', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('tenant_id')->index();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('ticket_sla_policies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('tenant_id')->index();
            $table->enum('priority', ['low', 'medium', 'high', 'urgent']);
            $table->unsignedInteger('first_response_minutes')->default(240);
            $table->unsignedInteger('resolve_minutes')->default(1440);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'priority']);
        });

        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('tenant_id')->index();
            $table->string('ticket_no')->index();
            $table->string('title');
            $table->longText('description')->nullable();
            $table->enum('status', ['new', 'triage', 'in_progress', 'waiting', 'resolved', 'closed'])->default('new')->index();
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium')->index();
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('queue_id')->nullable()->constrained('ticket_queues')->nullOnDelete();
            $table->foreignId('type_id')->nullable()->constrained('ticket_types')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('ticket_categories')->nullOnDelete();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('waiting_started_at')->nullable();
            $table->unsignedInteger('waiting_total_seconds')->default(0);
            $table->timestamp('last_commented_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
            $table->unique(['tenant_id', 'ticket_no']);
        });

        Schema::create('ticket_comments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('tenant_id')->index();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('source', ['web', 'email'])->default('web');
            $table->boolean('is_public')->default(true);
            $table->longText('body');
            $table->timestamps();
            $table->index(['tenant_id', 'ticket_id', 'created_at']);
        });

        Schema::create('ticket_ticket_tag', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('ticket_tag_id')->constrained('ticket_tags')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['ticket_id', 'ticket_tag_id']);
        });

        Schema::create('ticket_watchers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('tenant_id')->index();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email')->nullable();
            $table->enum('mode', ['access_notify', 'notify_only'])->default('access_notify');
            $table->timestamps();
            $table->unique(['ticket_id', 'user_id', 'email']);
        });

        Schema::create('ticket_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('tenant_id')->index();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_name');
            $table->string('mime_type', 180)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('current_version')->default(1);
            $table->timestamps();
        });

        Schema::create('ticket_attachment_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('tenant_id')->index();
            $table->foreignId('attachment_id')->constrained('ticket_attachments')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('storage_disk')->default('local');
            $table->string('storage_path');
            $table->string('file_name');
            $table->string('mime_type', 180)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['attachment_id', 'version_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachment_versions');
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('ticket_watchers');
        Schema::dropIfExists('ticket_ticket_tag');
        Schema::dropIfExists('ticket_comments');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_sla_policies');
        Schema::dropIfExists('ticket_queues');
        Schema::dropIfExists('ticket_tags');
        Schema::dropIfExists('ticket_categories');
        Schema::dropIfExists('ticket_types');
    }
};
