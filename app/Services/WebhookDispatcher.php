<?php

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Delivers webhook events to tenant instances.
 *
 * Contract (matches SystemLicenseGuard client side):
 * - Header: X-Hub-Signature-256 = 'sha256=' . hash_hmac('sha256', $body, $secret)
 * - Body envelope MUST contain: event_id (unique), timestamp (unix int)
 *   These fields are part of the HMAC-signed body (anti-replay protection).
 * - Events: license.updated (with 'token'), license.suspended, modules.sync (with 'modules')
 *
 * The webhook_secret is per-tenant, stored as hash in DB.
 * The actual secret is shown once at tenant creation and stored in config/secrets.
 */
class WebhookDispatcher
{
    /**
     * The 4 fixed Grup realtime event types (GroupRealtimeEventType). Routes
     * a delivery to the Grup HMAC contract (GroupHubSignature) instead of the
     * unrelated license-webhook HMAC contract in deliver() below — the two
     * event-type sets never overlap so this is an unambiguous dispatch key.
     */
    private const GRUP_EVENT_TYPES = [
        'membership.updated', 'patient.updated', 'referral.created', 'referral.updated',
    ];

    /**
     * Queue a webhook delivery to a tenant.
     * The actual HTTP delivery happens in a queued job or synchronously.
     */
    public function dispatch(
        Tenant $tenant,
        string $eventType,
        array $payload = [],
        bool $sync = false
    ): WebhookDelivery {
        // If the caller already assigned an event_id (e.g. force-disable warning),
        // reuse it so the stored WebhookDelivery.event_id matches the client's
        // envelope exactly — important for client-side anti-replay dedup.
        $eventId = $payload['event_id'] ?? ('evt-'.Str::uuid()->toString());
        $timestamp = time();

        $envelope = array_merge([
            'event_id' => $eventId,
            'event' => $eventType,
            'timestamp' => $timestamp,
        ], $payload);

        $delivery = WebhookDelivery::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'event_type' => $eventType,
            'event_id' => $eventId,
            'payload' => $envelope,
            'url' => null, // URL is derived at delivery time from the tenant's instance_url
            'attempts' => 0,
            'max_attempts' => 5,
            'next_attempt_at' => $sync ? now() : now()->addSeconds(30),
        ]);

        if ($sync) {
            $this->deliver($delivery);
        } else {
            DeliverWebhook::dispatch($delivery);
        }

        return $delivery;
    }

    /**
     * Queue a Grup realtime notification (durable HTTP fallback for the
     * Reverb broadcast) to a tenant. Delivered with the SAME event_id as the
     * Reverb event — the client's RealtimeEventProcessor dedupes by event_id
     * (firstOrCreate), so it is always safe for BOTH to arrive; whichever
     * lands first is processed, the other is a no-op.
     *
     * @param  string  $groupHubId  The hub Group.id (== tenant.group_id) —
     *                              carried out-of-band in the delivery row
     *                              (not part of the signed body) since it is
     *                              needed at delivery time for X-RME-Group-ID.
     * @param  array  $payload  Exactly GrupNotification::broadcastWith()'s shape:
     *                          event_id, type, resource_id, source_branch_id,
     *                          version, occurred_at.
     */
    public function dispatchGroupNotification(
        Tenant $tenant,
        string $groupHubId,
        array $payload,
        bool $sync = false
    ): WebhookDelivery {
        $delivery = WebhookDelivery::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'event_type' => $payload['type'],
            'event_id' => $payload['event_id'],
            'payload' => array_merge($payload, ['_group_hub_id' => $groupHubId]),
            'url' => null,
            'attempts' => 0,
            'max_attempts' => 5,
            // Short initial delay — Grup notifications are latency-sensitive
            // (a clinician waiting on a referral update), unlike license sync.
            'next_attempt_at' => $sync ? now() : now()->addSeconds(2),
        ]);

        if ($sync) {
            $this->deliver($delivery);
        } else {
            DeliverWebhook::dispatch($delivery);
        }

        return $delivery;
    }

    /**
     * Deliver a Grup realtime notification using the Grup HMAC contract
     * (GroupHubSignature — X-RME-* headers), NOT the license-webhook
     * X-Hub-Signature-256 contract used by deliver() for everything else.
     */
    private function deliverGroupNotification(WebhookDelivery $delivery): bool
    {
        $tenant = $delivery->tenant;
        $base = rtrim((string) $tenant->instance_url, '/');

        if ($base === '') {
            Log::warning("WebhookDispatcher: No instance_url for tenant {$tenant->client_code} (Grup notification).");
            $delivery->update([
                'attempts' => $delivery->attempts + 1,
                'next_attempt_at' => now()->addMinutes(5),
            ]);

            return false;
        }

        $secret = (string) config('license.grup_hub_hmac_secret', '');
        $groupHubId = (string) ($delivery->payload['_group_hub_id'] ?? '');

        if ($secret === '' || $groupHubId === '') {
            Log::warning("WebhookDispatcher: Grup HMAC secret or group id not configured for tenant {$tenant->client_code}.");
            $delivery->update([
                'attempts' => $delivery->attempts + 1,
                'next_attempt_at' => now()->addMinutes(5),
            ]);

            return false;
        }

        $payload = $delivery->payload;
        unset($payload['_group_hub_id']);
        $body = json_encode($payload);

        $signer = new GroupHubSignature($secret, $groupHubId, (int) config('license.grup_hub_timestamp_tolerance', 300));
        $headers = $signer->signedHeaders($tenant, $body);
        $url = $base.'/api/v1/grup/relay/notifications';

        $delivery->update([
            'url' => $url,
            'attempts' => $delivery->attempts + 1,
        ]);

        try {
            $response = Http::timeout(15)
                ->withHeaders(array_merge($headers, ['Content-Type' => 'application/json']))
                ->withBody($body, 'application/json')
                ->post($url);

            $delivery->update([
                'last_response_code' => (string) $response->status(),
                'last_response_body' => substr($response->body(), 0, 1000),
            ]);

            if ($response->successful()) {
                $delivery->update(['delivered_at' => now(), 'next_attempt_at' => null]);

                return true;
            }

            // 409 = client already processed this event_id (nonce replay) —
            // treat as delivered, not a failure, so it never retries forever.
            if ($response->status() === 409) {
                $delivery->update(['delivered_at' => now(), 'next_attempt_at' => null]);

                return true;
            }

            $backoff = min(300, 5 * (2 ** $delivery->attempts));
            $delivery->update(['next_attempt_at' => now()->addSeconds($backoff)]);

            return false;
        } catch (\Throwable $e) {
            Log::error("WebhookDispatcher: Grup notification delivery failed for tenant {$tenant->client_code}: {$e->getMessage()}");
            $delivery->update([
                'last_response_body' => substr($e->getMessage(), 0, 1000),
                'next_attempt_at' => $delivery->canRetry() ? now()->addSeconds(min(300, 5 * (2 ** $delivery->attempts))) : null,
            ]);

            return false;
        }
    }

    /**
     * Attempt to deliver a webhook to the tenant's instance.
     */
    public function deliver(WebhookDelivery $delivery): bool
    {
        if (in_array($delivery->event_type, self::GRUP_EVENT_TYPES, true)) {
            return $this->deliverGroupNotification($delivery);
        }

        $tenant = $delivery->tenant;

        // The webhook URL is the tenant's instance URL + /api/v1/system/license/webhook
        $webhookUrl = $this->getWebhookUrl($tenant);
        if (! $webhookUrl) {
            Log::warning("WebhookDispatcher: No webhook URL configured for tenant {$tenant->client_code}");
            $delivery->update([
                'attempts' => $delivery->attempts + 1,
                'next_attempt_at' => now()->addMinutes(5),
            ]);

            return false;
        }

        // Get the plaintext webhook secret (from config, not DB hash)
        $secret = $this->getWebhookSecret($tenant);
        if (! $secret) {
            Log::warning("WebhookDispatcher: No webhook secret for tenant {$tenant->client_code}");
            $delivery->update([
                'attempts' => $delivery->attempts + 1,
                'next_attempt_at' => now()->addMinutes(5),
            ]);

            return false;
        }

        $body = json_encode($delivery->payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

        $delivery->update([
            'url' => $webhookUrl,
            'attempts' => $delivery->attempts + 1,
        ]);

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'X-Hub-Signature-256' => $signature,
                    'Content-Type' => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->post($webhookUrl);

            $delivery->update([
                'last_response_code' => (string) $response->status(),
                'last_response_body' => substr($response->body(), 0, 1000),
            ]);

            if ($response->successful()) {
                $delivery->update([
                    'delivered_at' => now(),
                    'next_attempt_at' => null,
                ]);

                return true;
            }

            // Schedule retry with exponential backoff
            $backoff = min(300, 30 * (2 ** $delivery->attempts));
            $delivery->update(['next_attempt_at' => now()->addSeconds($backoff)]);

            return false;
        } catch (\Throwable $e) {
            Log::error("WebhookDispatcher: Delivery failed for tenant {$tenant->client_code}: {$e->getMessage()}");
            $delivery->update([
                'last_response_body' => substr($e->getMessage(), 0, 1000),
                'next_attempt_at' => $delivery->canRetry() ? now()->addSeconds(min(300, 30 * (2 ** $delivery->attempts))) : null,
            ]);

            return false;
        }
    }

    /**
     * Get the webhook URL for a tenant.
     *
     * SSRF guard: the URL is ONLY taken from the tenant's provisioned
     * `instance_url` (set by hub ops at tenant creation), never from any
     * caller-supplied value. Appended is the exact path the client's
     * SystemLicenseGuard webhook endpoint listens on.
     */
    private function getWebhookUrl(Tenant $tenant): ?string
    {
        $base = $tenant->instance_url;
        if (! $base) {
            // Fallback to per-tenant config override (also ops-set).
            $base = config("license.tenants.{$tenant->client_code}.instance_url");
        }

        if (! $base) {
            return null;
        }

        return rtrim($base, '/').'/api/v1/system/license/webhook';
    }

    /**
     * Get the plaintext webhook secret for a tenant.
     *
     * Prefers the encrypted-per-tenant secret stored on the tenant row; fails
     * over to a global config secret. The hashed column is intentionally NOT
     * used for signing (we need the raw secret).
     */
    private function getWebhookSecret(Tenant $tenant): ?string
    {
        if (! empty($tenant->webhook_secret)) {
            return $tenant->webhook_secret;
        }

        return config("license.tenants.{$tenant->client_code}.webhook_secret")
            ?? config('license.global_webhook_secret');
    }

    /**
     * Dispatch a license.updated event with a new signed token.
     */
    public function dispatchLicenseUpdate(Tenant $tenant, string $token, bool $sync = false): WebhookDelivery
    {
        return $this->dispatch($tenant, 'license.updated', [
            'token' => $token,
        ], $sync);
    }

    /**
     * Dispatch a license.suspended event.
     */
    public function dispatchLicenseSuspend(Tenant $tenant, bool $sync = false): WebhookDelivery
    {
        return $this->dispatch($tenant, 'license.suspended', [], $sync);
    }

    /**
     * Dispatch a modules.sync event.
     */
    public function dispatchModulesSync(Tenant $tenant, array $modules, bool $sync = false): WebhookDelivery
    {
        // Decision 3: push the FULL modules_statuses map (idempotent overwrite),
        // matching the contract in docs/reconciliation-with-grup-module.md.
        return $this->dispatch($tenant, 'modules.sync', [
            'modules_statuses' => $modules,
        ], $sync);
    }

    /**
     * Dispatch a force_disable.warning event.
     */
    public function dispatchForceDisableWarning(Tenant $tenant, array $details, bool $sync = false): WebhookDelivery
    {
        return $this->dispatch($tenant, 'force_disable.warning', $details, $sync);
    }

    /**
     * Dispatch a force_disable.executed event.
     */
    public function dispatchForceDisableExecuted(Tenant $tenant, array $details, bool $sync = false): WebhookDelivery
    {
        return $this->dispatch($tenant, 'force_disable.executed', $details, $sync);
    }
}
