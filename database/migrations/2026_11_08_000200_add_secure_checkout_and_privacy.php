<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('billing_provider', 24)->default('manual'); // grandfather existing workspaces
            $table->string('billing_cycle', 12)->nullable();
            $table->timestamp('billing_expires_at')->nullable();
        });
        Schema::create('ace_signup_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 140);
            $table->string('email', 255)->unique();
            $table->string('company', 140);
            $table->string('password_hash', 255);
            $table->string('plan', 12);
            $table->string('cycle', 12);
            $table->string('status', 30)->default('pending');
            $table->string('terms_version', 20);
            $table->string('privacy_version', 20);
            $table->timestamp('accepted_at');
            $table->string('acceptance_ip_hash', 64)->nullable();
            $table->string('email_verification_hash', 64)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('email_verification_sent_at')->nullable();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
        Schema::create('ace_billing_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('signup_application_id')->nullable()->constrained('ace_signup_applications')->nullOnDelete();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('plan', 12);
            $table->string('cycle', 12);
            $table->unsignedInteger('amount_cents');
            $table->string('terms_version', 20);
            $table->string('privacy_version', 20);
            $table->timestamp('accepted_at');
            $table->string('currency', 3)->default('BRL');
            $table->string('status', 24)->default('initiated');
            $table->string('provider', 24)->default('mercadopago');
            $table->string('provider_preference_id', 150)->nullable()->unique();
            $table->string('checkout_url', 1024)->nullable();
            $table->string('provider_payment_id', 80)->nullable()->unique();
            $table->string('payment_method', 40)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
        Schema::create('ace_billing_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('order_id')->nullable()->constrained('ace_billing_orders')->nullOnDelete();
            $table->string('provider_payment_id', 80);
            $table->string('event_status', 32);
            $table->string('event_code', 48);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ace_billing_events');
        Schema::dropIfExists('ace_billing_orders');
        Schema::dropIfExists('ace_signup_applications');
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn(['billing_provider', 'billing_cycle', 'billing_expires_at']));
    }
};
