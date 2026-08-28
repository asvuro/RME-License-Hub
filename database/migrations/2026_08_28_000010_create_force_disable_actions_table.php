<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tracks force-disable actions: when quota is exceeded after an addon expires,
        // the newest users must be disabled. This table records the audit trail.
        Schema::create('force_disable_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('entitlement_id')->nullable()->constrained('license_entitlements')->nullOnDelete();
            $table->string('trigger_type', 50)->comment('user_quota_exceeded, branch_quota_exceeded');
            $table->unsignedInteger('previous_limit')->nullable();
            $table->unsignedInteger('new_limit')->nullable();
            $table->unsignedInteger('users_to_disable')->default(0);
            $table->unsignedInteger('users_actually_disabled')->default(0);
            $table->boolean('admin_last_protected')->default(false)->comment('True jika admin terakhir dilindungi dari disable');
            $table->string('status', 30)->default('pending')->comment('pending, warning_sent, executed, cancelled');
            $table->timestamp('warning_sent_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('force_disable_actions');
    }
};
