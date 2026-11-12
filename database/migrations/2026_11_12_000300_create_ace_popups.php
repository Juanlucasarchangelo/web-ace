<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Somente tabela nova: não altera ou limpa dados de empresas, usuários e cofre.
        Schema::create('ace_popups', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 20)->default('text');
            $table->string('title', 160)->nullable();
            $table->text('description')->nullable();
            $table->string('badge_text', 70)->nullable();
            $table->string('image_path')->nullable();
            $table->string('cta_label', 70)->nullable();
            $table->string('cta_url', 500)->nullable();
            $table->json('placements');
            $table->string('trigger', 20)->default('immediate');
            $table->unsignedSmallInteger('delay_seconds')->default(3);
            $table->unsignedTinyInteger('scroll_percent')->default(40);
            $table->string('frequency', 20)->default('session');
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_active')->default(false);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_active', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        // Apenas para rollback explícito (não executar em atualizações normais).
        Schema::dropIfExists('ace_popups');
    }
};
