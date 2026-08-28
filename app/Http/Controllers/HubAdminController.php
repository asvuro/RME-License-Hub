<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\HubAuditLog;
use App\Models\LicenseAddon;
use App\Models\LicenseEntitlement;
use App\Models\LicenseKey;
use App\Models\Tenant;
use App\Models\Tier;
use App\Services\EntitlementCalculator;
use App\Services\ForceDisableManager;
use App\Services\LicenseTokenSigner;
use App\Services\ModuleSyncService;
use App\Services\WebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class HubAdminController extends Controller
{
    public function __construct(
        protected EntitlementCalculator $calculator,
        protected LicenseTokenSigner $tokenSigner,
        protected WebhookDispatcher $webhookDispatcher,
        protected ModuleSyncService $moduleSyncService,
        protected ForceDisableManager $forceDisableManager,
    ) {}

    // ─── Groups ───

    public function createGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_entity_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
        ]);

        $group = Group::create(array_merge($data, ['id' => Str::uuid()->toString()]));

        HubAuditLog::create([
            'tenant_id' => null,
            'event_type' => 'group.created',
            'details' => [
                'group_id' => $group->id,
                'name' => $group->name,
                'legal_entity_name' => $group->legal_entity_name,
            ],
            'actor_id' => $request->user()?->id,
            'actor_type' => 'admin',
        ]);

        return response()->json(['success' => true, 'data' => $group], 201);
    }

    public function listGroups(): JsonResponse
    {
        $groups = Group::withCount('tenants')->get();

        return response()->json(['success' => true, 'data' => $groups]);
    }

    public function addTenantToGroup(Request $request, Group $group): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'uuid', 'exists:tenants,id'],
        ]);

        $tenant = Tenant::findOrFail($data['tenant_id']);
        $tenant->update(['group_id' => $group->id]);

        HubAuditLog::create([
            'tenant_id' => $tenant->id,
            'event_type' => 'group.member_added',
            'details' => ['group_id' => $group->id, 'group_name' => $group->name],
            'actor_id' => $request->user()?->id,
            'actor_type' => 'admin',
        ]);

        return response()->json(['success' => true, 'message' => 'Tenant added to group.']);
    }

    // ─── Tenants ───

    public function createTenant(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group_id' => ['nullable', 'uuid', 'exists:groups,id'],
            'client_code' => ['required', 'string', 'max:50', 'unique:tenants,client_code'],
            'client_name' => ['required', 'string', 'max:255'],
            'legal_entity_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            // Provisioning-only: where the hub pushes webhooks / relays to.
            // Never accepted from the client at runtime (SSRF guard).
            'instance_url' => ['nullable', 'url', 'max:255'],
        ]);

        // Generate webhook secret for this tenant
        $webhookSecret = Str::random(48);

        $tenant = Tenant::create(array_merge($data, [
            'id' => Str::uuid()->toString(),
            'status' => 'active',
            'webhook_secret_hash' => hash('sha256', $webhookSecret),
            'webhook_secret' => $webhookSecret, // encrypted cast stores ciphertext
        ]));

        HubAuditLog::create([
            'tenant_id' => $tenant->id,
            'event_type' => 'tenant.created',
            'details' => ['client_code' => $tenant->client_code],
            'actor_id' => $request->user()?->id,
            'actor_type' => 'admin',
        ]);

        return response()->json([
            'success' => true,
            'data' => $tenant,
            'webhook_secret' => $webhookSecret,
            'webhook_secret_note' => 'Store this securely. It is needed for the client to verify hub webhooks. This will not be shown again.',
        ], 201);
    }

    public function listTenants(): JsonResponse
    {
        $tenants = Tenant::with(['group', 'activeEntitlement.tier'])
            ->withCount('licenseKeys')
            ->get();

        return response()->json(['success' => true, 'data' => $tenants]);
    }

    public function showTenant(Tenant $tenant): JsonResponse
    {
        $tenant->load(['group', 'licenseKeys.entitlement.tier', 'licenseKeys.entitlement.addons', 'heartbeats' => fn ($q) => $q->latest()->limit(5)]);

        return response()->json(['success' => true, 'data' => $tenant]);
    }

    public function suspendTenant(Request $request, Tenant $tenant): JsonResponse
    {
        $tenant->update(['status' => 'suspended']);
        $this->webhookDispatcher->dispatchLicenseSuspend($tenant);

        HubAuditLog::create([
            'tenant_id' => $tenant->id,
            'event_type' => 'license.suspended',
            'details' => ['reason' => $request->input('reason', 'manual')],
            'actor_id' => $request->user()?->id,
            'actor_type' => 'admin',
        ]);

        return response()->json(['success' => true, 'message' => 'Tenant suspended. Webhook dispatched.']);
    }

    // ─── License Keys ───

    public function issueLicenseKey(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'uuid', 'exists:tenants,id'],
            'tier_id' => ['required', 'exists:tiers,id'],
            'valid_until' => ['nullable', 'date'],
            'addons' => ['nullable', 'array'],
            'addons.*.type' => ['required_with:addons', 'in:module,user_quota,branch_quota,time_extension'],
            'addons.*.quantity' => ['required_with:addons', 'integer', 'min:1'],
            'addons.*.target_module_slug' => ['nullable', 'string'],
            'addons.*.effective_until' => ['nullable', 'date'],
        ]);

        $tenant = Tenant::findOrFail($data['tenant_id']);
        $tier = Tier::findOrFail($data['tier_id']);

        // Generate unique license key
        $licenseKeyStr = 'LIC-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(8));

        $validFrom = now();
        $validUntil = isset($data['valid_until']) ? Carbon::parse($data['valid_until']) : $validFrom->copy()->addDays($tier->default_duration_days);

        $licenseKey = LicenseKey::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'license_key' => $licenseKeyStr,
            'status' => 'unused',
            'valid_until' => $validUntil,
        ]);

        // Create entitlement
        $entitlement = $this->calculator->createEntitlement(
            licenseKeyId: $licenseKey->id,
            tenantId: $tenant->id,
            tierId: $tier->id,
            validFrom: $validFrom->toIso8601String(),
            validUntil: $validUntil->toIso8601String(),
        );

        // Add addons if provided
        if (! empty($data['addons'])) {
            foreach ($data['addons'] as $addonData) {
                $this->calculator->addAddon(
                    entitlement: $entitlement,
                    addonType: $addonData['type'],
                    quantity: $addonData['quantity'],
                    targetModuleSlug: $addonData['target_module_slug'] ?? null,
                    effectiveUntil: $addonData['effective_until'] ?? null,
                );
            }
        }

        $this->calculator->recalculate($entitlement);

        HubAuditLog::create([
            'tenant_id' => $tenant->id,
            'event_type' => 'license.issued',
            'details' => [
                'license_key' => $licenseKeyStr,
                'tier' => $tier->slug,
                'valid_until' => $validUntil->toIso8601String(),
            ],
            'actor_id' => $request->user()?->id,
            'actor_type' => 'admin',
        ]);

        $licenseKey->load('entitlement.tier', 'entitlement.addons', 'tenant');

        return response()->json([
            'success' => true,
            'data' => $licenseKey,
        ], 201);
    }

    public function listLicenseKeys(Request $request): JsonResponse
    {
        $query = LicenseKey::with(['tenant', 'entitlement.tier', 'entitlement.addons']);

        if ($request->has('tenant_id')) {
            $query->where('tenant_id', $request->input('tenant_id'));
        }

        $keys = $query->latest()->get();

        return response()->json(['success' => true, 'data' => $keys]);
    }

    // ─── Add-ons ───

    public function addAddon(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entitlement_id' => ['required', 'uuid', 'exists:license_entitlements,id'],
            'type' => ['required', 'in:module,user_quota,branch_quota,time_extension'],
            'quantity' => ['required', 'integer', 'min:1'],
            'target_module_slug' => ['nullable', 'string'],
            'effective_until' => ['nullable', 'date'],
        ]);

        $entitlement = LicenseEntitlement::findOrFail($data['entitlement_id']);
        $previousMaxUsers = $entitlement->effective_max_users;

        $addon = $this->calculator->addAddon(
            entitlement: $entitlement,
            addonType: $data['type'],
            quantity: $data['quantity'],
            targetModuleSlug: $data['target_module_slug'] ?? null,
            effectiveUntil: $data['effective_until'] ?? null,
        );

        $entitlement->refresh();

        // If quota increased, cancel any pending force-disable actions
        if ($entitlement->effective_max_users > $previousMaxUsers) {
            $pendingActions = $entitlement->forceDisableActions()
                ->whereIn('status', ['pending', 'warning_sent'])
                ->get();
            foreach ($pendingActions as $action) {
                $this->forceDisableManager->cancel($action, 'Quota increased by new add-on');
            }
        }

        HubAuditLog::create([
            'tenant_id' => $entitlement->tenant_id,
            'event_type' => 'addon.added',
            'details' => [
                'entitlement_id' => $entitlement->id,
                'type' => $data['type'],
                'quantity' => $data['quantity'],
                'module' => $data['target_module_slug'] ?? null,
            ],
            'actor_id' => $request->user()?->id,
            'actor_type' => 'admin',
        ]);

        return response()->json(['success' => true, 'data' => $addon], 201);
    }

    // ─── Sync ───

    public function pushModuleSync(Request $request, Tenant $tenant): JsonResponse
    {
        $moduleCount = $this->moduleSyncService->pushToTenant($tenant);

        HubAuditLog::create([
            'tenant_id' => $tenant->id,
            'event_type' => 'modules.synced',
            'details' => [
                'tenant_id' => $tenant->id,
                'tenant_code' => $tenant->client_code,
                'modules_pushed' => $moduleCount,
            ],
            'actor_id' => $request->user()?->id,
            'actor_type' => 'admin',
        ]);

        return response()->json(['success' => true, 'message' => 'Module sync webhook dispatched.']);
    }

    public function pushAllModuleSync(Request $request): JsonResponse
    {
        $count = $this->moduleSyncService->pushToAllTenants();

        HubAuditLog::create([
            'tenant_id' => null,
            'event_type' => 'modules.synced_all',
            'details' => [
                'tenants_synced' => $count,
            ],
            'actor_id' => $request->user()?->id,
            'actor_type' => 'admin',
        ]);

        return response()->json(['success' => true, 'message' => "Sync dispatched to {$count} tenants."]);
    }

    // ─── Stats ───

    public function dashboard(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'tenants' => [
                    'total' => Tenant::count(),
                    'active' => Tenant::where('status', 'active')->count(),
                    'suspended' => Tenant::where('status', 'suspended')->count(),
                    'offline' => Tenant::offline()->count(),
                ],
                'groups' => Group::count(),
                'licenses' => [
                    'total' => LicenseKey::count(),
                    'active' => LicenseKey::where('status', 'active')->count(),
                    'unused' => LicenseKey::where('status', 'unused')->count(),
                    'expired' => LicenseKey::where('status', 'expired')->count(),
                    'revoked' => LicenseKey::where('status', 'revoked')->count(),
                ],
                'entitlements' => [
                    'active' => LicenseEntitlement::where('status', 'active')->count(),
                    'expired' => LicenseEntitlement::where('status', 'expired')->count(),
                ],
                'addons' => [
                    'active' => LicenseAddon::where('status', 'active')->count(),
                    'expired' => LicenseAddon::where('status', 'expired')->count(),
                ],
            ],
        ]);
    }
}
