<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->string('client_code', 50)->unique()->comment('Kode unik klien, cocok dengan field client_code di token lisensi');
            $table->string('client_name');
            $table->string('legal_entity_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('address')->nullable();
            $table->string('status')->default('active')->comment('active, suspended, terminated');
            $table->string('api_token_hash', 128)->nullable()->comment('Hash dari service-to-service API token untuk tenant ini');
            $table->string('webhook_secret_hash', 128)->nullable()->comment('Hash dari HMAC secret untuk webhook push ke tenant ini');
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
