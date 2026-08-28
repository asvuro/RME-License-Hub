<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'offline_alert_sent_at')) {
                // Set once an offline alert has been emailed to hub admins for
                // the CURRENT stale period. Cleared back to null the moment the
                // tenant heartbeats successfully again, so the next time it
                // goes quiet for max_offline_days a fresh alert fires — without
                // this dedup, the hourly check would re-email every single run.
                $table->timestamp('offline_alert_sent_at')->nullable()->after('last_heartbeat_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'offline_alert_sent_at')) {
                $table->dropColumn('offline_alert_sent_at');
            }
        });
    }
};
