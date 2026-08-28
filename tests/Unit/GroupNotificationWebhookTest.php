<?php

namespace Tests\Unit;

use App\Enums\GroupRealtimeEventType;
use App\Events\GrupNotification;
use App\Models\Group;
use App\Models\LicenseKey;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Services\GroupRelayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests the durable HTTP fallback for Grup realtime events (WebhookDispatcher
 * ::dispatchGroupNotification / deliverGroupNotification), which exists
 * alongside the Reverb broadcast so a branch with a dropped Reverb connection
 * still learns about referral/membership changes once it comes back online.
 *
 * Also regression-guards the GroupHubSignature "sha256=" prefix bug: the
 * client's VerifyGroupHubSignature middleware compares the raw hex HMAC with
 * NO prefix — a prefixed signature always fails verification silently on the
 * client side (found via real end-to-end testing, not by this test suite
 * originally, which is exactly why this explicit assertion exists now).
 */
class GroupNotificationWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('license.grup_hub_hmac_secret', 'test-grup-hmac-secret');
    }

    private function tenantWithInstance(?string $groupId, string $instanceId, string $instanceUrl): Tenant
    {
        $tenant = Tenant::factory()->create(['group_id' => $groupId, 'instance_url' => $instanceUrl]);
        LicenseKey::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'license_key' => 'LIC-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(8)),
            'status' => 'active',
            'instance_id' => $instanceId,
        ]);

        return $tenant;
    }

    public function test_broadcast_queues_a_webhook_delivery_alongside_the_reverb_event(): void
    {
        Event::fake([GrupNotification::class]);

        $group = Group::factory()->create();
        $tenant = $this->tenantWithInstance($group->id, 'INST-TARGET', 'https://branch-target.example.test');

        app(GroupRelayService::class)->broadcast($tenant, GroupRealtimeEventType::ReferralUpdated, 'INST-SRC', 'ref-123');

        Event::assertDispatched(GrupNotification::class);

        $delivery = WebhookDelivery::where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($delivery, 'broadcast() must also queue an HTTP fallback delivery.');
        $this->assertSame('referral.updated', $delivery->event_type);
        $this->assertSame('ref-123', $delivery->payload['resource_id']);
        $this->assertSame('INST-SRC', $delivery->payload['source_branch_id']);
    }

    public function test_delivery_signature_has_no_sha256_prefix_and_verifies_exactly_like_the_real_client(): void
    {
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $group = Group::factory()->create();
        $tenant = $this->tenantWithInstance($group->id, 'INST-TARGET', 'https://branch-target.example.test');

        app(GroupRelayService::class)->broadcast($tenant, GroupRealtimeEventType::MembershipUpdated, null, null);

        Http::assertSent(function ($request) use ($group, $tenant) {
            if ($request->url() !== 'https://branch-target.example.test/api/v1/grup/relay/notifications') {
                return false;
            }

            $ts = $request->header('X-RME-Timestamp')[0] ?? '';
            $reqId = $request->header('X-RME-Request-ID')[0] ?? '';
            $signature = $request->header('X-RME-Signature')[0] ?? '';
            $groupId = $request->header('X-RME-Group-ID')[0] ?? '';
            $targetInstance = $request->header('X-RME-Target-Instance-ID')[0] ?? '';

            // Regression guard: must be the BARE hex digest, never "sha256=...".
            if (str_starts_with($signature, 'sha256=')) {
                return false;
            }

            // Recompute EXACTLY as VerifyGroupHubSignature::handle() does on
            // the real client, using the raw request body it would receive.
            $expected = hash_hmac('sha256', $ts."\n".$reqId."\n".$request->body(), 'test-grup-hmac-secret');

            return hash_equals($expected, $signature)
                && $groupId === (string) $group->id
                && $targetInstance === 'INST-TARGET';
        });
    }

    public function test_delivery_body_matches_the_grup_notification_payload_shape(): void
    {
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $group = Group::factory()->create();
        $tenant = $this->tenantWithInstance($group->id, 'INST-TARGET', 'https://branch-target.example.test');

        app(GroupRelayService::class)->broadcast($tenant, GroupRealtimeEventType::ReferralCreated, 'INST-SRC', 'ref-999');

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['type'] === 'referral.created'
                && $body['resource_id'] === 'ref-999'
                && $body['source_branch_id'] === 'INST-SRC'
                && $body['version'] === GrupNotification::CONTRACT_VERSION
                && isset($body['event_id'], $body['occurred_at'])
                && ! array_key_exists('_group_hub_id', $body); // internal field must never leak onto the wire
        });
    }

    public function test_a_409_nonce_replay_response_is_treated_as_delivered_not_a_failure(): void
    {
        Http::fake(['*' => Http::response(['message' => 'already processed'], 409)]);

        $group = Group::factory()->create();
        $tenant = $this->tenantWithInstance($group->id, 'INST-TARGET', 'https://branch-target.example.test');

        app(GroupRelayService::class)->broadcast($tenant, GroupRealtimeEventType::PatientUpdated, null, 'p-1');

        $delivery = WebhookDelivery::where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($delivery->delivered_at, '409 (nonce already seen) must be treated as a successful delivery.');
        $this->assertNull($delivery->next_attempt_at);
    }

    public function test_missing_instance_url_schedules_a_retry_instead_of_crashing(): void
    {
        $group = Group::factory()->create();
        $tenant = Tenant::factory()->create(['group_id' => $group->id, 'instance_url' => null]);
        LicenseKey::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'license_key' => 'LIC-NOURL-'.strtoupper(Str::random(8)),
            'status' => 'active',
            'instance_id' => 'INST-NO-URL',
        ]);

        app(GroupRelayService::class)->broadcast($tenant, GroupRealtimeEventType::MembershipUpdated, null, null);

        $delivery = WebhookDelivery::where('tenant_id', $tenant->id)->first();
        $this->assertNull($delivery->delivered_at);
        $this->assertNotNull($delivery->next_attempt_at);
    }
}
