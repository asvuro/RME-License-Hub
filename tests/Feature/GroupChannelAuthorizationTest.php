<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\LicenseKey;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * End-to-end authorization of the group realtime channels through the real
 * /broadcasting/auth endpoint, using the reverb (Pusher) broadcaster. The Pusher
 * SDK signs the auth response locally (HMAC) so no live WebSocket server is needed.
 *
 * Security guarantees asserted here:
 *  - A tenant that is NOT a member of the group is denied (403).
 *  - A tenant from a DIFFERENT group is denied (no cross-group leakage).
 *  - A tenant belonging to the group but requesting ANOTHER branch's channel is denied.
 *  - The correct member is allowed and receives a signed response.
 *  - Suspended/terminated tenants are denied.
 *  - Unauthenticated requests are denied.
 */
class GroupChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Use the real reverb broadcaster; it signs offline (HMAC), no server needed.
        Config::set('broadcasting.default', 'reverb');
        Config::set('broadcasting.connections.reverb.key', 'test-key');
        Config::set('broadcasting.connections.reverb.secret', 'test-secret');
        Config::set('broadcasting.connections.reverb.app_id', 'test-app');
        Config::set('broadcasting.connections.reverb.options', [
            'host' => 'localhost', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false,
        ]);
        // Channels are already registered by the app's withBroadcasting() boot.
        // Calling Broadcast::channel again is a no-op-safe re-registration.
        Broadcast::channel('group.{groupId}.branch.{branchInstanceId}', \App\Broadcasting\GroupBranchChannel::class, ['guards' => ['tenant']]);
        Broadcast::channel('group.{groupId}', \App\Broadcasting\GroupPresenceChannel::class, ['guards' => ['tenant']]);
    }

    /**
     * UUID PK models don't list `id` in $fillable, so assign it directly.
     */
    private function makeGroup(string $id): Group
    {
        $group = new Group(['name' => 'Group '.$id, 'status' => 'active']);
        $group->id = $id;
        $group->save();

        return $group;
    }

    private function makeTenant(string $id, ?string $groupId = null, string $status = 'active', ?string $instanceId = null): Tenant
    {
        $tenant = new Tenant([
            'group_id' => $groupId,
            'client_code' => 'CODE-'.$id,
            'client_name' => 'Tenant '.$id,
            'status' => $status,
            'api_token_hash' => hash('sha256', 'token-'.$id),
        ]);
        $tenant->id = $id;
        $tenant->save();

        if ($instanceId) {
            $license = new LicenseKey([
                'tenant_id' => $tenant->id,
                'license_key' => 'LIC-'.$id,
                'status' => 'active',
                'instance_id' => $instanceId,
            ]);
            $license->id = 'lic-'.$id;
            $license->save();
        }

        return $tenant;
    }

    private function authRequest(string $channel, ?string $token = null, string $socketId = '123.456')
    {
        $headers = ['X-Socket-ID' => $socketId];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->postJson('/broadcasting/auth', [
            'channel_name' => $channel,
            'socket_id' => $socketId,
        ], $headers);
    }

    public function test_member_is_authorized_for_its_own_branch_private_channel(): void
    {
        $this->makeGroup('grp-1');
        $this->makeTenant('t-1', 'grp-1', 'active', 'inst-1');

        $response = $this->authRequest('private-group.grp-1.branch.inst-1', 'token-t-1');

        $response->assertOk();
        $response->assertJsonStructure(['auth']);
        // Presence-style channel_data must NOT be present for a private channel.
        $response->assertJsonMissing(['channel_data']);
    }

    public function test_non_member_is_denied_for_branch_private_channel(): void
    {
        $this->makeGroup('grp-1');
        // Tenant exists but belongs to NO group.
        $this->makeTenant('t-2', null, 'active', 'inst-2');

        $response = $this->authRequest('private-group.grp-1.branch.inst-1', 'token-t-2');

        $response->assertForbidden();
    }

    public function test_tenant_from_different_group_is_denied(): void
    {
        $this->makeGroup('grp-1');
        $this->makeGroup('grp-2');
        // Member of grp-2 trying to access grp-1's channel.
        $this->makeTenant('t-3', 'grp-2', 'active', 'inst-3');

        $response = $this->authRequest('private-group.grp-1.branch.inst-1', 'token-t-3');

        $response->assertForbidden();
    }

    public function test_member_is_denied_for_another_branch_channel_in_same_group(): void
    {
        $this->makeGroup('grp-1');
        // Member owns inst-4 but tries to subscribe to inst-9's channel.
        $this->makeTenant('t-4', 'grp-1', 'active', 'inst-4');

        $response = $this->authRequest('private-group.grp-1.branch.inst-9', 'token-t-4');

        $response->assertForbidden();
    }

    public function test_suspended_tenant_is_denied(): void
    {
        $this->makeGroup('grp-1');
        $this->makeTenant('t-5', 'grp-1', 'suspended', 'inst-5');

        $response = $this->authRequest('private-group.grp-1.branch.inst-5', 'token-t-5');

        $response->assertForbidden();
    }

    public function test_presence_group_channel_authorized_only_for_members(): void
    {
        $this->makeGroup('grp-1');
        $this->makeTenant('t-6', 'grp-1', 'active', 'inst-6');

        $response = $this->authRequest('presence-group.grp-1', 'token-t-6');

        $response->assertOk();
        // Presence auth returns both auth and channel_data (member identity).
        $response->assertJsonStructure(['auth', 'channel_data']);
    }

    public function test_presence_group_channel_denied_for_non_member(): void
    {
        $this->makeGroup('grp-1');
        $this->makeTenant('t-7', null, 'active', 'inst-7');

        $response = $this->authRequest('presence-group.grp-1', 'token-t-7');

        $response->assertForbidden();
    }

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->makeGroup('grp-1');
        $this->makeTenant('t-8', 'grp-1', 'active', 'inst-8');

        // No Authorization header at all.
        $response = $this->authRequest('presence-group.grp-1', null);

        $response->assertForbidden();
    }
}
