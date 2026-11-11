<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('legal_name', 180)->nullable();
            $table->string('trade_name', 180)->nullable();
            $table->string('document_type', 4)->nullable();
            $table->string('document_number', 14)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('postal_code', 8)->nullable();
            $table->string('street', 180)->nullable();
            $table->string('street_number', 20)->nullable();
            $table->string('address_complement', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 2)->nullable();
            $table->string('legal_representative', 140)->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['legal_name', 'trade_name', 'document_type', 'document_number',
                'contact_email', 'phone', 'postal_code', 'street', 'street_number',
                'address_complement', 'district', 'city', 'state', 'legal_representative']);
        });
    }
};
