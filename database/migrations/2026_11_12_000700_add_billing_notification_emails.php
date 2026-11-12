<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Nullable additions only. Existing customer records and mail history remain intact.
        Schema::table('ace_billing_orders', function (Blueprint $table): void {
            $table->string('checkout_email', 255)->nullable();
        });
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('billing_notification_email', 255)->nullable();
        });
    }

    public function down(): void
    {
        // Never drop customer billing notification addresses in an automatic rollback.
        throw new RuntimeException('Rollback destrutivo bloqueado: preservar os e-mails de faturamento.');
    }
};
