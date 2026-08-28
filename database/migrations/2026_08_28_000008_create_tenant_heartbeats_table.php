<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('instance_id', 64)->nullable();
            $table->string('license_key', 128)->nullable();
            $table->string('hardware_id', 128)->nullable();
            $table->string('app_version', 30)->nullable();
            $table->string('php_version', 30)->nullable();
            $table->string('hostname')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_heartbeats');
    }
};
