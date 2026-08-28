<?php

namespace Tests\Feature;

use App\Events\GrupNotification;
use App\Models\Group;
use App\Models\GroupReferral;
use App\Models\LicenseKey;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for the hub-authoritative referral store behind /api/v1/group/relay/referrals
 * (GroupApiController), consumed by RME-Backend's GroupHubClient::createReferral /
 * updateReferral / referral / referrals.
 *
 * The hub is the single source of truth for referral STATUS (source and
 * destination branches must agree) — it never stores clinical data beyond the
 * non-PHI snapshot + reason text the sending clinician already chose to share.
 */
class GroupReferralFlowTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWithToken(?string $groupId, string $instanceId): array
    {
        $tenant = Tenant::factory()->create(['group_id' => $groupId]);
        LicenseKey::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'license_key' => 'LIC-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(8)),
            'status' => 'active',
            'instance_id' => $instanceId,
        ]);
        $token = 'rme_hub_'.Str::random(48);
        $tenant->update(['api_token_hash' => hash('sha256', $token)]);

        return [$tenant, $token];
    }

    public function test_store_creates_referral_and_notifies_destination_branch(): void
    {
        Event::fake([GrupNotification::class]);

        $group = Group::factory()->create();
        [$source, $sourceToken] = $this->tenantWithToken($group->id, 'INST-SRC');
        [$destination] = $this->tenantWithToken($group->id, 'INST-DST');

        $response = $this->withHeader('Authorization', 'Bearer '.$sourceToken)
            ->postJson('/api/v1/group/relay/referrals', [
                'source_branch_id' => $source->id,
                'destination_branch_id' => $destination->id,
                'source_patient_id' => '4321',
                'patient_snapshot' => ['name' => 'Budi Santoso'],
                'reason' => 'Butuh perawatan ICU, kapasitas cabang asal penuh.',
                'referred_at' => now()->toIso8601String(),
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'requested');

        $this->assertDatabaseHas('group_referrals', [
            'group_id' => $group->id,
            'source_branch_id' => $source->id,
            'destination_branch_id' => $destination->id,
            'status' => 'requested',
        ]);

        Event::assertDispatched(GrupNotification::class, function (GrupNotification $event) {
            return $event->instanceId === 'INST-DST' && $event->type->value === 'referral.created';
        });
    }

    public function test_store_rejects_spoofed_source_branch_id(): void
    {
        $group = Group::factory()->create();
        [$source, $sourceToken] = $this->tenantWithToken($group->id, 'INST-SRC');
        [$destination] = $this->tenantWithToken($group->id, 'INST-DST');
        [$otherBranch] = $this->tenantWithToken($group->id, 'INST-OTHER');

        $response = $this->withHeader('Authorization', 'Bearer '.$sourceToken)
            ->postJson('/api/v1/group/relay/referrals', [
                // Claims to be a DIFFERENT branch than the authenticated one.
                'source_branch_id' => $otherBranch->id,
                'destination_branch_id' => $destination->id,
                'source_patient_id' => '1',
                'reason' => 'spoof attempt',
                'referred_at' => now()->toIso8601String(),
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('group_referrals', 0);
    }

    public function test_store_rejects_destination_outside_group(): void
    {
        $groupA = Group::factory()->create();
        $groupB = Group::factory()->create();
        [$source, $sourceToken] = $this->tenantWithToken($groupA->id, 'INST-SRC');
        [$outsider] = $this->tenantWithToken($groupB->id, 'INST-OUTSIDE');

        $response = $this->withHeader('Authorization', 'Bearer '.$sourceToken)
            ->postJson('/api/v1/group/relay/referrals', [
                'source_branch_id' => $source->id,
                'destination_branch_id' => $outsider->id,
                'source_patient_id' => '1',
                'reason' => 'cross-group attempt',
                'referred_at' => now()->toIso8601String(),
            ]);

        $response->assertStatus(404);
    }

    public function test_destination_can_accept_a_requested_referral(): void
    {
        Event::fake([GrupNotification::class]);

        $group = Group::factory()->create();
        [$source] = $this->tenantWithToken($group->id, 'INST-SRC');
        [$destination, $destToken] = $this->tenantWithToken($group->id, 'INST-DST');

        $referral = GroupReferral::factory()->create([
            'group_id' => $group->id,
            'source_branch_id' => $source->id,
            'destination_branch_id' => $destination->id,
            'status' => 'requested',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$destToken)
            ->patchJson("/api/v1/group/relay/referrals/{$referral->id}", ['status' => 'accepted']);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'accepted');
        $this->assertSame('accepted', $referral->fresh()->status);

        Event::assertDispatched(GrupNotification::class, function (GrupNotification $event) {
            return $event->instanceId === 'INST-SRC' && $event->type->value === 'referral.updated';
        });
    }

    public function test_source_cannot_accept_its_own_referral(): void
    {
        $group = Group::factory()->create();
        [$source, $sourceToken] = $this->tenantWithToken($group->id, 'INST-SRC');
        [$destination] = $this->tenantWithToken($group->id, 'INST-DST');

        $referral = GroupReferral::factory()->create([
            'group_id' => $group->id,
            'source_branch_id' => $source->id,
            'destination_branch_id' => $destination->id,
            'status' => 'requested',
        ]);

        // Only the DESTINATION may accept/reject; the source may only cancel.
        $response = $this->withHeader('Authorization', 'Bearer '.$sourceToken)
            ->patchJson("/api/v1/group/relay/referrals/{$referral->id}", ['status' => 'accepted']);

        $response->assertStatus(422);
        $this->assertSame('requested', $referral->fresh()->status);
    }

    public function test_destination_cannot_skip_straight_to_completed(): void
    {
        $group = Group::factory()->create();
        [$source] = $this->tenantWithToken($group->id, 'INST-SRC');
        [$destination, $destToken] = $this->tenantWithToken($group->id, 'INST-DST');

        $referral = GroupReferral::factory()->create([
            'group_id' => $group->id,
            'source_branch_id' => $source->id,
            'destination_branch_id' => $destination->id,
            'status' => 'requested',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$destToken)
            ->patchJson("/api/v1/group/relay/referrals/{$referral->id}", ['status' => 'completed']);

        $response->assertStatus(422);
    }

    public function test_a_branch_outside_the_referral_cannot_see_or_update_it(): void
    {
        $group = Group::factory()->create();
        [$source] = $this->tenantWithToken($group->id, 'INST-SRC');
        [$destination] = $this->tenantWithToken($group->id, 'INST-DST');
        [, $bystanderToken] = $this->tenantWithToken($group->id, 'INST-BYSTANDER');

        $referral = GroupReferral::factory()->create([
            'group_id' => $group->id,
            'source_branch_id' => $source->id,
            'destination_branch_id' => $destination->id,
            'status' => 'requested',
        ]);

        $show = $this->withHeader('Authorization', 'Bearer '.$bystanderToken)
            ->getJson("/api/v1/group/relay/referrals/{$referral->id}");
        $show->assertStatus(404);

        $update = $this->withHeader('Authorization', 'Bearer '.$bystanderToken)
            ->patchJson("/api/v1/group/relay/referrals/{$referral->id}", ['status' => 'accepted']);
        $update->assertStatus(404);
    }

    public function test_list_only_returns_referrals_involving_the_caller(): void
    {
        $group = Group::factory()->create();
        [$source, $sourceToken] = $this->tenantWithToken($group->id, 'INST-SRC');
        [$destination] = $this->tenantWithToken($group->id, 'INST-DST');
        [$unrelated] = $this->tenantWithToken($group->id, 'INST-UNRELATED');

        GroupReferral::factory()->create([
            'group_id' => $group->id,
            'source_branch_id' => $source->id,
            'destination_branch_id' => $destination->id,
        ]);
        GroupReferral::factory()->create([
            'group_id' => $group->id,
            'source_branch_id' => $destination->id,
            'destination_branch_id' => $unrelated->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$sourceToken)
            ->getJson('/api/v1/group/relay/referrals');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.data');
    }

    public function test_referral_payload_includes_patient_snapshot_for_client_resync(): void
    {
        // Regression guard: RME-Backend's RealtimeEventProcessor::syncReferral()
        // rebuilds its LOCAL row from this exact response and REQUIRES
        // source_patient_id + patient_snapshot — omitting them (a real bug found
        // via end-to-end testing against the actual client) breaks resync silently.
        $group = Group::factory()->create();
        [$source, $sourceToken] = $this->tenantWithToken($group->id, 'INST-SRC');
        [$destination] = $this->tenantWithToken($group->id, 'INST-DST');

        $referral = GroupReferral::factory()->create([
            'group_id' => $group->id,
            'source_branch_id' => $source->id,
            'destination_branch_id' => $destination->id,
            'source_patient_id' => '4321',
            'patient_snapshot' => ['name' => 'Budi Santoso'],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$sourceToken)
            ->getJson("/api/v1/group/relay/referrals/{$referral->id}");

        $response->assertOk();
        $response->assertJsonPath('data.source_patient_id', '4321');
        $response->assertJsonPath('data.patient_snapshot.name', 'Budi Santoso');
    }

    public function test_listing_route_does_not_fall_through_to_the_generic_relay_proxy(): void
    {
        // Regression guard: /relay/referrals must NOT be swallowed by the
        // generic GET /relay/{path} proxy (which would try to resolve
        // "referrals" as a branch id/code and 404 with the wrong message).
        $group = Group::factory()->create();
        [, $token] = $this->tenantWithToken($group->id, 'INST-SRC');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/group/relay/referrals');

        $response->assertOk();
        $response->assertJsonMissing(['message' => 'Target branch not found in group.']);
    }
}
