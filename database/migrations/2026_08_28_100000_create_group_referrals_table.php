<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hub-authoritative store for cross-branch referrals (Modules/Grup
     * GroupHubClient::createReferral/updateReferral). The hub is the single
     * source of truth for referral status so both branches agree; clinical
     * data is NEVER stored here (only a non-PHI snapshot + free-text reason
     * the sending clinician already chose to share).
     */
    public function up(): void
    {
        if (Schema::hasTable('group_referrals')) {
            return;
        }

        Schema::create('group_referrals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignUuid('source_branch_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('destination_branch_id')->constrained('tenants')->restrictOnDelete();
            $table->string('source_patient_id');
            $table->json('patient_snapshot')->nullable();
            $table->text('reason');
            $table->text('clinical_summary')->nullable();
            $table->string('note')->nullable();
            $table->string('status', 20)->default('requested');
            $table->timestamp('referred_at');
            $table->timestamps();

            $table->index(['group_id', 'source_branch_id']);
            $table->index(['group_id', 'destination_branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_referrals');
    }
};
