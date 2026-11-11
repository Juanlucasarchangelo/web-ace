<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ace_affiliates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('ace_affiliate_coupons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_id')->constrained('ace_affiliates')->restrictOnDelete();
            $table->string('code', 32)->unique();
            $table->unsignedTinyInteger('discount_percent');
            $table->string('plan', 12)->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['affiliate_id', 'is_active']);
        });
        Schema::table('ace_signup_applications', function (Blueprint $table): void {
            $table->foreignId('affiliate_coupon_id')->nullable()->constrained('ace_affiliate_coupons')->nullOnDelete();
        });
        Schema::table('ace_billing_orders', function (Blueprint $table): void {
            $table->foreignId('affiliate_coupon_id')->nullable()->constrained('ace_affiliate_coupons')->nullOnDelete();
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('original_amount_cents')->nullable();
            $table->index(['affiliate_coupon_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('ace_billing_orders', function (Blueprint $table): void {
            $table->dropForeign(['affiliate_coupon_id']);
            $table->dropIndex(['affiliate_coupon_id', 'status']);
            $table->dropColumn(['affiliate_coupon_id','discount_cents','original_amount_cents']);
        });
        Schema::table('ace_signup_applications', function (Blueprint $table): void {
            $table->dropForeign(['affiliate_coupon_id']);
            $table->dropColumn('affiliate_coupon_id');
        });
        Schema::dropIfExists('ace_affiliate_coupons');
        Schema::dropIfExists('ace_affiliates');
    }
};
