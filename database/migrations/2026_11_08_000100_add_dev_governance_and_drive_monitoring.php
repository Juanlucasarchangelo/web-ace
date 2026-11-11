<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_dev')->default(false)->index();
        });
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedInteger('seat_limit')->default(1);
            $table->string('billing_status', 20)->default('unverified');
            $table->timestamp('billing_checked_at')->nullable();
        });
        // Never revoke seats already assigned at the moment of migration.
        foreach (DB::table('tenants')->select('id')->get() as $tenant) {
            $seats = DB::table('tenant_user')->where('tenant_id', $tenant->id)->count();
            DB::table('tenants')->where('id', $tenant->id)->update(['seat_limit' => max(1, $seats)]);
        }
        Schema::table('google_drive_connections', function (Blueprint $table): void {
            $table->string('health_status', 24)->default('unknown');
            $table->string('last_error_code', 48)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
        });
        Schema::create('dev_admin_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('action', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
        });
        Schema::create('dev_operational_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('connection_id')->nullable(); // Historical record survives disconnects.
            $table->string('source', 48);
            $table->string('code', 48);
            $table->string('summary', 240); // Fixed sanitized server-side message only.
            $table->string('severity', 12)->default('warning');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['resolved_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dev_operational_issues');
        Schema::dropIfExists('dev_admin_audits');
        Schema::table('google_drive_connections', function (Blueprint $table): void {
            $table->dropColumn(['health_status', 'last_error_code', 'last_error_at', 'last_checked_at', 'last_success_at']);
        });
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['seat_limit', 'billing_status', 'billing_checked_at']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_dev'));
    }
};
