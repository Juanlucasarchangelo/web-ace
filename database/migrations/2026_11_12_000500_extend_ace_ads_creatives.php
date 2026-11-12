<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Atualização exclusivamente aditiva: preserva campanhas e imagens já cadastradas.
        Schema::table('ace_ads', function (Blueprint $table): void {
            $table->text('html_code')->nullable();
        });
        Schema::create('ace_ad_creatives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_id')->constrained('ace_ads')->cascadeOnDelete();
            $table->string('image_path');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['ad_id', 'sort_order']);
        });
    }
    public function down(): void
    {
        // Nunca executar rollback em upgrades regulares.
        Schema::dropIfExists('ace_ad_creatives');
        Schema::table('ace_ads', function (Blueprint $table): void { $table->dropColumn('html_code'); });
    }
};
