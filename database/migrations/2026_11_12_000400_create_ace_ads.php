<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Tabela independente e exclusivamente aditiva. Não altera patrocinadores nem popups.
        Schema::create('ace_ads', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 140);
            $table->string('provider', 20)->default('direct');
            $table->string('placement', 30);
            $table->string('headline', 160)->nullable();
            $table->string('description', 240)->nullable();
            $table->string('image_path')->nullable();
            $table->string('destination_url', 500)->nullable();
            $table->string('google_client', 40)->nullable();
            $table->string('google_slot', 30)->nullable();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_active')->default(false);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['placement', 'is_active', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        // Não executar rollback em atualização normal.
        Schema::dropIfExists('ace_ads');
    }
};
