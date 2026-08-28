<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Entitlement = satu lisensi untuk satu tenant, membawa tier + durasi + kuota dasar.
        // Add-ons menempel pada entitlement ini sebagai line items.
        Schema::create('license_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('license_key_id')->constrained('license_keys')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tier_id')->constrained('tiers');
            $table->string('status', 30)->default('active')->comment('active, suspended, expired');
            $table->unsignedInteger('base_max_users')->default(0)->comment('Snapshot max_users dari tier saat entitlement dibuat');
            $table->unsignedInteger('base_max_branches')->default(1)->comment('Kuota cabang/grup default dari tier');
            $table->unsignedInteger('effective_max_users')->default(0)->comment('Hasil kalkulasi: base + semua add-on user_quota aktif');
            $table->unsignedInteger('effective_max_branches')->default(1)->comment('Hasil kalkulasi: base + semua add-on branch_quota aktif');
            $table->json('effective_modules')->comment('Hasil: modul tier + semua add-on module aktif');
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable()->comment('Tanggal kedaluwarsa (dapat diperpanjang oleh add-on time_extension)');
            $table->timestamp('last_recalculated_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('license_key_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_entitlements');
    }
};
