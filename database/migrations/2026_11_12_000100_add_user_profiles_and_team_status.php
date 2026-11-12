<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Additive columns: no existing users, tenant links or credentials are removed.
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 20)->nullable();
            $table->string('job_title', 100)->nullable();
            $table->string('avatar_path', 255)->nullable();
        });
        Schema::table('tenant_user', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });
        // Retire viewer role without deleting accounts or membership records.
        DB::table('tenant_user')->where('role', 'viewer')->update(['role' => 'member']);
    }

    public function down(): void
    {
        Schema::table('tenant_user', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['phone', 'job_title', 'avatar_path']);
        });
    }
};
