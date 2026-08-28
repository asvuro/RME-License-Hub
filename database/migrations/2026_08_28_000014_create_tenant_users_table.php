<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Client-reported roster snapshot. The hub is authoritative for force-disable
        // targeting: the client POSTs its user list (id, is_admin, registered_at) and
        // the hub computes which newest-registered users to disable — never the client.
        // This table is a hub-side cache of the client's reported roster.
        Schema::create('tenant_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('external_user_id', 128)->comment('User id as known by the client instance');
            $table->boolean('is_admin')->default(false)->comment('True if the user holds an admin role on the client');
            $table->timestamp('registered_at')->nullable()->comment('When the user was created on the client (drives newest-first ordering)');
            $table->boolean('is_active')->default(true)->comment('Whether the user is currently enabled on the client');
            $table->timestamp('last_deactivated_at')->nullable()->comment('When the hub last ordered this user disabled');
            $table->timestamps();

            $table->unique(['tenant_id', 'external_user_id']);
            $table->index(['tenant_id', 'is_active', 'is_admin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
    }
};
