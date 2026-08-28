<?php

namespace Tests\Feature;

use App\Events\GrupNotification;
use App\Models\Group;
use App\Models\LicenseKey;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\DatabaseTestCase;

class GrupRealtimeAuthTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic Reverb app credentials for the auth signing test.
        Config::set('reverb.apps.0.key', 'test_key');
        Config::set('reverb.apps.0.secret', 'test_secret');
    }

    private function tenantWithInstance(string $instanceId, ?string $groupId = null): Tenant
    {
        $tenant = Tenant::factory()->create(['group_id' => $groupId]);
        LicenseKey::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'license_key' => 'LIC-'.strtoupper(\Illuminate\Support\Str::random(4)).'-'.strtoupper(\Illuminate\Support\Str::random(4)).'-'.strtoupper(\Illuminate\Support\Str::random(8)),
            'status' => 'active',
            'instance_id' => $instanceId,
        ]);

        return $tenant;
    }

    public function test_auth_succeeds_when_instance_header_matches_channel(): void
    {
        $instance = 'INST-AAA';
        $tenant = $this->tenantWithInstance($instance);
        $token = \Illuminate\Support\Str::random(48);
        $tenant->update(['api_token_hash' => hash('sha256', $token)]);

        $response = $this->postJson('/api/v1/group/realtime/auth', [
            'socket_id' => '123.456',
            'channel_name' => GrupNotification::CHANNEL_PREFIX.$instance,
        ], ['Authorization' => 'Bearer '.$token, 'X-RME-Instance-ID' => $instance]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['auth']);
        $this->assertStringStartsWith('test_key:', $response->json('auth'));
    }

    public function test_auth_denied_when_instance_header_mismatches(): void
    {
        $tenant = $this->tenantWithInstance('INST-AAA');
        $token = \Illuminate\Support\Str::random(48);
        $tenant->update(['api_token_hash' => hash('sha256', $token)]);

        $response = $this->postJson('/api/v1/group/realtime/auth', [
            'socket_id' => '123.456',
            'channel_name' => GrupNotification::CHANNEL_PREFIX.'INST-AAA',
        ], ['Authorization' => 'Bearer '.$token, 'X-RME-Instance-ID' => 'INST-EVIL']);

        $response->assertStatus(403);
    }

    public function test_auth_denied_without_token(): void
    {
        $this->postJson('/api/v1/group/realtime/auth', [
            'socket_id' => '123.456',
            'channel_name' => GrupNotification::CHANNEL_PREFIX.'INST-AAA',
        ])->assertStatus(401);
    }

    public function test_auth_denied_for_other_tenants_channel(): void
    {
        $tenant = $this->tenantWithInstance('INST-AAA');
        $token = \Illuminate\Support\Str::random(48);
        $tenant->update(['api_token_hash' => hash('sha256', $token)]);

        // Attempting to auth a DIFFERENT instance's channel must fail (fail-closed).
        $response = $this->postJson('/api/v1/group/realtime/auth', [
            'socket_id' => '123.456',
            'channel_name' => GrupNotification::CHANNEL_PREFIX.'INST-BBB',
        ], ['Authorization' => 'Bearer '.$token, 'X-RME-Instance-ID' => 'INST-AAA']);

        $response->assertStatus(403);
    }

    public function test_relay_requires_membership_in_group(): void
    {
        $tenant = $this->tenantWithInstance('INST-AAA'); // no group_id
        $token = \Illuminate\Support\Str::random(48);
        $tenant->update(['api_token_hash' => hash('sha256', $token)]);

        $this->postJson('/api/v1/group/relay', [
            'event' => 'patient.updated',
            'resource_id' => 'p1',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(422);
    }

    public function test_relay_rejects_unknown_event_type(): void
    {
        $group = Group::create(['id' => \Illuminate\Support\Str::uuid()->toString(), 'name' => 'G']);
        $tenant = $this->tenantWithInstance('INST-AAA', $group->id);
        $token = \Illuminate\Support\Str::random(48);
        $tenant->update(['api_token_hash' => hash('sha256', $token)]);

        // Only the 4 fixed types are allowed (fail-closed to the contract).
        $this->postJson('/api/v1/group/relay', [
            'event' => 'arbitrary.hack',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(422);
    }

    public function test_relay_broadcasts_to_siblings_on_correct_channel(): void
    {
        \Illuminate\Support\Facades\Event::fake([GrupNotification::class]);

        $group = Group::create(['id' => \Illuminate\Support\Str::uuid()->toString(), 'name' => 'G']);
        $sender = $this->tenantWithInstance('INST-SEND', $group->id);
        $sibling = $this->tenantWithInstance('INST-SIB', $group->id);
        $token = \Illuminate\Support\Str::random(48);
        $sender->update(['api_token_hash' => hash('sha256', $token)]);

        $response = $this->postJson('/api/v1/group/relay', [
            'event' => 'patient.updated',
            'resource_id' => 'p1',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertOk();
        // The hub relayed to exactly one sibling branch in the group.
        $response->assertJson(['success' => true, 'delivered_to' => 1]);
    }
}
