<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('google_drive_connections', function (Blueprint $table): void {
            // Tenant foreign key must remain indexed before UNIQUE is removed.
            $table->index('tenant_id', 'google_drive_connections_tenant_lookup');
        });
        Schema::table('google_drive_connections', function (Blueprint $table): void {
            $table->dropUnique('google_drive_connections_tenant_id_unique');
            $table->unique(['tenant_id', 'email'], 'google_drive_connections_tenant_email_unique');
        });
    }

    public function down(): void
    {
        // Intentionally no destructive rollback: multiple accounts cannot safely
        // be reduced to one without losing stored OAuth connections.
    }
};
