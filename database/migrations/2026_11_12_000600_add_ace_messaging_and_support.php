<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Additive only: preserves all existing accounts, companies, billings and credentials.
        Schema::table('users', function (Blueprint $table): void {
            $table->json('dev_notification_emails')->nullable();
        });
        Schema::create('ace_messaging_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->text('resend_api_key')->nullable(); // Laravel encrypted cast, never returned through API.
            $table->string('from_email', 255)->nullable();
            $table->string('from_name', 120)->default('ACE');
            $table->json('event_preferences')->nullable();
            $table->timestamps();
        });
        Schema::create('ace_mail_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 190)->unique();
            $table->string('event_type', 40);
            $table->json('payload');
            $table->string('direct_email', 255)->nullable();
            $table->timestamp('routed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['routed_at', 'created_at']);
        });
        Schema::create('ace_mail_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('ace_mail_events')->cascadeOnDelete();
            $table->string('recipient_email', 255);
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('resend_id', 100)->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'recipient_email'], 'ace_mail_delivery_unique');
            $table->index(['status', 'next_attempt_at']);
        });
        Schema::create('ace_support_tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->string('subject', 180);
            $table->string('category', 32)->default('question');
            $table->string('priority', 16)->default('normal');
            $table->string('status', 24)->default('open');
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['status', 'updated_at']);
        });
        Schema::create('ace_support_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('ticket_id')->constrained('ace_support_tickets');
            $table->foreignId('user_id')->constrained('users');
            $table->string('sender_role', 12);
            $table->text('body');
            $table->timestamps();
            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        // Rollback is intentionally disallowed: deleting ticket messages or delivery history
        // would destroy user data. Forward-only migrations are required for ACE upgrades.
        throw new RuntimeException('Migration aditiva do ACE: rollback destrutivo não é permitido.');
    }
};
