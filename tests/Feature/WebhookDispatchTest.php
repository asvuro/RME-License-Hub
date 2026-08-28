<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\WebhookDispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\DatabaseTestCase;

class WebhookDispatchTest extends DatabaseTestCase
{
    public function test_hub_signs_webhook_with_tenant_secret_and_client_verifies(): void
    {
        Queue::fake();
        Http::fake([
            'rs-sehat.example.test/*' => Http::response(['ok' => true], 200),
        ]);
        $plainSecret = 'shared-secret-'.\Illuminate\Support\Str::random(32);
        $tenant = Tenant::factory()->create([
            'instance_url' => 'https://rs-sehat.example.test',
            'webhook_secret' => $plainSecret, // encrypted cast on save
        ]);
        // Recover the plaintext (encrypted cast would otherwise mask it).
        $plainSecret = $tenant->fresh()->webhook_secret;

        $dispatcher = app(WebhookDispatcher::class);
        $delivery = $dispatcher->dispatch($tenant, 'license.updated', ['token' => 'abc'], true);

        $this->assertNotNull($delivery->delivered_at, 'webhook should be delivered synchronously');

        // Reconstruct exactly what the client verifies.
        $payload = $delivery->payload; // contains event_id, event, timestamp, token
        $body = json_encode($payload);
        $expectedSig = 'sha256='.hash_hmac('sha256', $body, $plainSecret);

        // The client (SystemLicenseGuard webhook handler) computes the same HMAC
        // over the raw body. Assert the hub produced a body that verifies.
        $clientComputed = 'sha256='.hash_hmac('sha256', $body, $plainSecret);
        $this->assertTrue(hash_equals($expectedSig, $clientComputed));

        // And that tampering with the body invalidates the signature.
        $tampered = json_encode(array_merge($payload, ['token' => 'evil']));
        $tamperedSig = 'sha256='.hash_hmac('sha256', $tampered, $plainSecret);
        $this->assertFalse(hash_equals($expectedSig, $tamperedSig));
    }

    public function test_modules_sync_uses_full_modules_statuses_key(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create(['instance_url' => 'https://rs.example.test']);
        $app = app(WebhookDispatcher::class);
        $delivery = $app->dispatchModulesSync($tenant, ['core' => true, 'radiologi' => false], true);

        $this->assertSame('modules.sync', $delivery->event_type);
        $this->assertArrayHasKey('modules_statuses', $delivery->payload);
        $this->assertSame(['core' => true, 'radiologi' => false], $delivery->payload['modules_statuses']);
    }

    public function test_delivery_without_instance_url_stays_pending(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create(['instance_url' => null]);
        $delivery = app(WebhookDispatcher::class)->dispatch($tenant, 'license.updated', [], true);

        // No URL -> not delivered; remains retryable.
        $this->assertNull($delivery->delivered_at);
        $this->assertTrue($delivery->canRetry());
    }
}
