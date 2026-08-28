<?php

namespace Tests\Feature;

use App\Models\HubAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guard test for the hub admin dashboard (GET /api/v1/hub/dashboard).
 *
 * The dashboard route is wired inside the `auth:sanctum` group in routes/api.php,
 * so only an authenticated hub-admin session (via a Sanctum token) may reach it.
 *
 * IMPORTANT SCOPE NOTE: the current implementation gates the dashboard solely on
 * `auth:sanctum` -- it does NOT distinguish between an `operator` and a
 * `superadmin`. Both roles reach the same dashboard payload. This test therefore
 * verifies the *real* contract: authentication is required; an unauthenticated
 * caller is rejected with 401. If a role gate is added later, extend this test
 * to assert 403 for insufficient roles. The "non-admin cannot access" part of the
 * original spec maps to "the caller is not a hub admin at all" (no Sanctum token)
 * because hub admins have no separate non-admin tier in this API.
 */
class AdminDashboardGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/hub/dashboard');

        $response->assertStatus(401);
    }

    public function test_dashboard_is_reachable_by_an_authenticated_hub_admin(): void
    {
        $admin = HubAdmin::factory()->create();
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/hub/dashboard');

        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'data' => ['tenants', 'groups', 'licenses']]);
    }

    public function test_dashboard_is_reachable_by_any_hub_admin_role(): void
    {
        // Both operator and superadmin currently reach the dashboard (no role
        // gate is enforced by the route). Documented as the real behavior.
        $operator = HubAdmin::factory()->create(['role' => 'operator']);
        $opToken = $operator->createToken('op')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$opToken)
            ->getJson('/api/v1/hub/dashboard')
            ->assertStatus(200);

        $super = HubAdmin::factory()->superadmin()->create();
        $superToken = $super->createToken('sup')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$superToken)
            ->getJson('/api/v1/hub/dashboard')
            ->assertStatus(200);
    }
}
