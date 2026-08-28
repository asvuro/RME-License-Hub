<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('license_key', 128)->unique();
            $table->string('status', 30)->default('unused')->comment('unused, active, suspended, expired, revoked');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('hardware_id', 128)->nullable()->comment('HWID klien yang mengaktivasi key ini');
            $table->string('instance_id', 64)->nullable()->comment('Instance ID yang diterbitkan hub saat aktivasi');
            $table->string('hostname')->nullable();
            $table->string('app_version', 30)->nullable();
            $table->string('php_version', 30)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('hardware_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_keys');
    }
};
