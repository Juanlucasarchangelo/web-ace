<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 50)->nullable();
            $table->string('name', 180);
            $table->string('trade_name', 180)->nullable();
            $table->enum('person_type', ['company', 'person'])->default('company');
            $table->string('document', 20)->nullable();
            $table->string('email', 180)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable();
            $table->string('contact_name', 150)->nullable();
            $table->string('contact_email', 180)->nullable();
            $table->string('address_street', 180)->nullable();
            $table->string('address_number', 30)->nullable();
            $table->string('address_extra', 100)->nullable();
            $table->string('address_district', 120)->nullable();
            $table->string('address_city', 120)->nullable();
            $table->string('address_state', 50)->nullable();
            $table->string('address_zip', 25)->nullable();
            $table->string('address_country', 80)->default('Brasil');
            $table->date('started_at')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->string('preferred_cloud', 40)->nullable(); // Apenas preferência, NÃO autenticação integrada.
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('name', 180);
            $table->string('domain', 253);
            $table->enum('status', ['active', 'inactive', 'maintenance'])->default('active');
            $table->date('online_at')->nullable();
            $table->string('hosting_provider', 180)->nullable();
            $table->string('dns_primary', 253)->nullable();
            $table->string('dns_secondary', 253)->nullable();
            $table->string('ftp_host', 253)->nullable();
            $table->string('control_panel_url', 2048)->nullable();
            $table->string('store_url', 2048)->nullable();
            $table->longText('registro_br')->nullable(); // Atributo encrypted no model.
            $table->json('social_links')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'client_id']);
            $table->unique(['tenant_id', 'domain']);
        });

        Schema::create('site_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->enum('category', ['email', 'store', 'social', 'hosting', 'ftp', 'other'])->default('other');
            $table->string('label', 140);
            $table->string('service', 120)->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('username', 255)->nullable();
            $table->longText('secret')->nullable(); // Criptografado via Eloquent; jamais serializar em respostas.
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'site_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_accesses');
        Schema::dropIfExists('sites');
        Schema::dropIfExists('clients');
    }
};
