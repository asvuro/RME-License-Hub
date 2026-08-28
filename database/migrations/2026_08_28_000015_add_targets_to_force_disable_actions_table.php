<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('force_disable_actions', function (Blueprint $table) {
            // Hub-computed, explicit disable targets (newest-first, admin-last protected).
            if (! Schema::hasColumn('force_disable_actions', 'affected_user_ids')) {
                $table->json('affected_user_ids')->nullable()->after('users_actually_disabled')
                    ->comment('External user ids the hub has ordered disabled (explicit, not client-chosen)');
            }
            if (! Schema::hasColumn('force_disable_actions', 'last_admin_protected_ids')) {
                $table->json('last_admin_protected_ids')->nullable()->after('affected_user_ids')
                    ->comment('Admin ids deliberately preserved so the client is never left without an admin');
            }
            if (! Schema::hasColumn('force_disable_actions', 'warning_event_id')) {
                $table->string('warning_event_id', 128)->nullable()->after('last_admin_protected_ids');
            }
            if (! Schema::hasColumn('force_disable_actions', 'executed_event_id')) {
                $table->string('executed_event_id', 128)->nullable()->after('warning_event_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('force_disable_actions', function (Blueprint $table) {
            $table->dropColumnIfExists('affected_user_ids');
            $table->dropColumnIfExists('last_admin_protected_ids');
            $table->dropColumnIfExists('warning_event_id');
            $table->dropColumnIfExists('executed_event_id');
        });
    }
};
