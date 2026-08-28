<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\LicenseKey;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for GET /api/v1/group/context, consumed by the client's
 * MembershipSynchronizer::sync() (Modules/Grup).
 *
 * This endpoint previously had ZERO test coverage on the hub side — a real
 * gap found while chasing the bug below. The response passed context()
 * itself (a bare ->json('data') extraction, no shape assertions) but failed
 * one layer deeper: MembershipSynchronizer validates a NESTED "group" object
 * ('group.id', 'group.legal_name', ...), while the hub was returning those
 * fields flat at the top level. Found only by processing a real
 * membership.updated event end-to-end through the actual client code, not
 * by calling context() in isolation.
 */
class GroupContextTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The exact validation rules from Modules/Grup's MembershipSynchronizer::
     * sync() on the RME-Backend side — kept in lockstep deliberately so this
     * test fails loudly the moment the hub's response shape drifts from what
     * the real client actually requires.
     */
    private function clientValidationRules(): array
    {
        return [
            'group.id' => ['required', 'uuid'],
            'group.legal_name' => ['required', 'string', 'max:255'],
            'group.legal_identifier' => ['nullable', 'string', 'max:255'],
            'group.status' => ['required', 'in:active,suspended,revoked'],
            'branches' => ['required', 'array', 'min:1', 'max:500'],
            'branches.*.id' => ['required', 'uuid'],
            'branches.*.instance_id' => ['required', 'string', 'max:255', 'distinct'],
            'branches.*.code' => ['required', 'string', 'max:64', 'distinct'],
            'branches.*.name' => ['required', 'string', 'max:255'],
            'branches.*.status' => ['required', 'in:active,suspended,revoked'],
            'branches.*.capabilities' => ['sometimes', 'array'],
            'branches.*.last_seen_at' => ['nullable', 'date'],
        ];
    }

    private function tenantWithToken(?string $groupId, string $instanceId): array
    {
        $tenant = Tenant::factory()->create(['group_id' => $groupId]);
        LicenseKey::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'license_key' => 'LIC-'.strtoupper(Str::random(8)),
            'status' => 'active',
            'instance_id' => $instanceId,
        ]);
        $token = 'rme_hub_'.Str::random(48);
        $tenant->update(['api_token_hash' => hash('sha256', $token)]);

        return [$tenant, $token];
    }

    public function test_context_response_passes_the_clients_own_membership_sync_validation(): void
    {
        $group = Group::factory()->create();
        [$caller, $token] = $this->tenantWithToken($group->id, 'INST-CALLER');
        $this->tenantWithToken($group->id, 'INST-SIBLING');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/group/context');

        $response->assertOk();

        $validator = Validator::make($response->json('data'), $this->clientValidationRules());
        $this->assertTrue(
            $validator->passes(),
            "Response failed the client's own MembershipSynchronizer validation: ".json_encode($validator->errors()->toArray())
        );
    }

    public function test_context_includes_the_callers_own_instance_id_among_branches(): void
    {
        // MembershipSynchronizer::sync() explicitly requires the CALLING
        // instance to appear in the branches list (it rejects otherwise:
        // "Hub tidak mengembalikan instance lokal sebagai anggota grup.").
        $group = Group::factory()->create();
        [$caller, $token] = $this->tenantWithToken($group->id, 'INST-CALLER');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/group/context');

        $response->assertOk();
        $instanceIds = collect($response->json('data.branches'))->pluck('instance_id');
        $this->assertContains('INST-CALLER', $instanceIds);
    }

    public function test_context_rejects_a_tenant_not_in_a_group(): void
    {
        [, $token] = $this->tenantWithToken(null, 'INST-NOGROUP');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/group/context')
            ->assertStatus(422);
    }
}
