<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('event_type', 64)->index()->comment('license.activated, license.suspended, license.expired, addon.added, addon.expired, force_disable.warning, force_disable.executed, webhook.delivered, sync.pushed, group.member_added, group.member_removed');
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable()->comment('User hub yang melakukan aksi');
            $table->string('actor_type', 30)->nullable()->comment('admin, system, api');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_audit_logs');
    }
};
