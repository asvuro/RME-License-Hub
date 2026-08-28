<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add-ons are generic line items that target ONE of: module, user_quota, branch_quota, time_extension
        Schema::create('license_addons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entitlement_id')->constrained('license_entitlements')->cascadeOnDelete();
            $table->string('addon_type', 30)->comment('module, user_quota, branch_quota, time_extension');
            $table->string('target_module_slug', 100)->nullable()->comment('Untuk addon_type=module, nama modul yang ditambahkan');
            $table->unsignedInteger('quantity')->default(1)->comment('Jumlah: user_quota=jumlah user, branch_quota=jumlah cabang, time_extension=hari, module=1');
            $table->string('label')->nullable()->comment('Label deskriptif, mis. "5 User Tambahan"');
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable()->comment('NULL = tidak kedaluwarsa terpisah; jika diisi, add-on berakhir pada tanggal ini');
            $table->string('status', 30)->default('active')->comment('active, expired, revoked');
            $table->timestamps();

            $table->index(['entitlement_id', 'status', 'addon_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_addons');
    }
};
