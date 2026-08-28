<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Public base URL of this client instance (RME-Backend). Used to build
            // the absolute webhook/ingress URLs the hub pushes to. Set at tenant
            // provisioning; never derived from untrusted request input (SSRF guard).
            if (!Schema::hasColumn('tenants', 'instance_url')) {
                $table->string('instance_url')->nullable()->after('contact_phone')
                    ->comment('Public base URL of the client instance, e.g. https://rs-sehat.simgos.id');
            }

            // Per-tenant webhook HMAC secret, stored ENCRYPTED so the hub can
            // recover the plaintext to sign pushes to this tenant. The legacy
            // webhook_secret_hash remains as a verification/canary column.
            if (!Schema::hasColumn('tenants', 'webhook_secret')) {
                $table->text('webhook_secret')->nullable()->after('webhook_secret_hash')
                    ->comment('Encrypted HMAC secret (plaintext recovered at delivery time)');
            }

            // Per-tenant service-to-service token used by the hub when it calls
            // this instance's egress/ingress endpoints (Grup relay, sync ack).
            if (!Schema::hasColumn('tenants', 's2s_token')) {
                $table->text('s2s_token')->nullable()->after('api_token_hash')
                    ->comment('Encrypted service-to-service bearer token for hub->instance calls');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumnIfExists('instance_url');
            $table->dropColumnIfExists('webhook_secret');
            $table->dropColumnIfExists('s2s_token');
        });
    }
};
