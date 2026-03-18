<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->longText('resumo')->nullable();
            $table->string('dominio')->nullable();

            $table->string('acesso_email')->nullable();
            $table->string('email_profissional')->nullable();

            $table->string('drive')->nullable();
            $table->string('youtube')->nullable();
            $table->string('facebook')->nullable();
            $table->string('linkedin')->nullable();
            $table->string('gmail')->nullable();
            $table->string('instagram')->nullable();
            $table->string('linktree')->nullable();

            $table->string('info_adicionais')->nullable();

            $table->string('registro_dominio')->nullable();
            $table->string('usuario_dominio')->nullable();
            $table->string('senha_dominio')->nullable();

            $table->string('hospedagem')->nullable();
            $table->string('usuario_hospedagem')->nullable();
            $table->string('senha_hospedagem')->nullable();

            $table->string('dns_primario')->nullable();
            $table->string('dns_secundario')->nullable();

            $table->string('ftp')->nullable();
            $table->string('usuario_ftp')->nullable();
            $table->string('senha_ftp')->nullable();

            $table->string('link_site_adm')->nullable();
            $table->string('usuario_site_adm')->nullable();
            $table->string('senha_site_adm')->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_tables');
    }
};
