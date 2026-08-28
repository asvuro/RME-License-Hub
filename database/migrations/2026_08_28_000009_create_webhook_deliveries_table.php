<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('event_type', 64)->comment('license.updated, license.suspended, modules.sync, force_disable.warning, force_disable.executed');
            $table->string('event_id', 128)->unique()->comment('ID unik untuk anti-replay di sisi klien');
            $table->json('payload')->comment('Body webhook yang dikirim ke klien');
            $table->string('url')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->string('last_response_code', 10)->nullable();
            $table->text('last_response_body')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'delivered_at']);
            $table->index('next_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
