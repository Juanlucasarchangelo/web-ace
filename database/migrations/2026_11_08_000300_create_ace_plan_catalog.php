<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ace_plans', function (Blueprint $table): void {
            $table->string('id', 12)->primary();
            $table->string('title', 80);
            $table->text('description')->nullable();
            $table->json('features')->nullable();
            $table->unsignedInteger('monthly_cents');
            $table->unsignedInteger('annual_cents');
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('ace_billing_orders', function (Blueprint $table): void {
            $table->string('plan_title', 80)->nullable();
        });
        Schema::create('ace_plan_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('plan_id', 12)->index();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->json('before_state');
            $table->json('after_state');
            $table->timestamp('created_at')->useCurrent();
        });
        $now = now();
        DB::table('ace_plans')->insert([
            ['id' => 'free', 'title' => 'Free', 'description' => 'Comece com seu workspace e a conta Master.', 'features' => json_encode(['Clientes e sites', 'Cofre de acessos protegido', 'Gestão de equipe pelo Master']), 'monthly_cents' => 0, 'annual_cents' => 0, 'is_active' => true, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'cloud', 'title' => 'Cloud', 'description' => 'Integrações e organização da operação digital.', 'features' => json_encode(['Tudo do Free', 'Google Drive com OAuth oficial', 'Arquivos e pastas em nuvem']), 'monthly_cents' => 12900, 'annual_cents' => 130800, 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'pro', 'title' => 'Pro', 'description' => 'IA e produtividade com acesso supervisionado.', 'features' => json_encode(['Tudo do Cloud', 'ACE IA contextual', 'Cadastros orientados e auditados']), 'monthly_cents' => 15900, 'annual_cents' => 166800, 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::table('ace_billing_orders', function (Blueprint $table): void {
            $table->dropColumn('plan_title');
        });
        Schema::dropIfExists('ace_plan_changes');
        Schema::dropIfExists('ace_plans');
    }
};
