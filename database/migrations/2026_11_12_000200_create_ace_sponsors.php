<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Nova tabela isolada: não altera contas, empresas, assinaturas ou credenciais.
        Schema::create('ace_sponsors', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 140);
            $table->string('logo_path', 255);
            $table->string('website_url', 500)->nullable();
            $table->string('description', 240)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        // Exclusivamente rollback explícito; nunca rodar migrate:rollback em produção sem backup.
        Schema::dropIfExists('ace_sponsors');
    }
};
