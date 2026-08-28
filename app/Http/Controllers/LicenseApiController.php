<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActivateLicenseRequest;
use App\Http\Requests\HeartbeatRequest;
use App\Models\HubAuditLog;
use App\Models\LicenseKey;
use App\Models\Tenant;
use App\Models\TenantHeartbeat;
use App\Services\EntitlementCalculator;
use App\Services\ForceDisableManager;
use App\Services\LicenseTokenSigner;
use App\Services\ModuleSyncService;
use App\Services\WebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class LicenseApiController extends Controller
{
    public function __construct(
        protected LicenseTokenSigner $tokenSigner,
        protected EntitlementCalculator $calculator,
        protected WebhookDispatcher $webhookDispatcher,
        protected ModuleSyncService $moduleSyncService,
    ) {}

    /**
     * POST /api/v1/licenses/activate
     *
     * Called by CentralHubClientService::activateOnline() on the client side.
     * Expects: license_key, hardware_id, hostname, app_version
     * Returns: { token: "base64(payload).base64(signature)", ... }
     */
    public function activate(ActivateLicenseRequest $request): JsonResponse
    {
        $licenseKey = $request->input('license_key');
        $hardwareId = $request->input('hardware_id');
        $hostname = $request->input('hostname', gethostname());
        $appVersion = $request->input('app_version', '1.0.0');

        // Find the license key
        $licenseKeyModel = LicenseKey::where('license_key', $licenseKey)->first();

        if (! $licenseKeyModel) {
            return response()->json([
                'success' => false,
                'message' => 'License key not found.',
            ], 422);
        }

        $tenant = $licenseKeyModel->tenant;

        // Check tenant status
        if ($tenant->status === 'suspended' || $tenant->status === 'terminated') {
            return response()->json([
                'success' => false,
                'message' => 'Tenant account is suspended or terminated.',
            ], 422);
        }

        // Check license key status
        if ($licenseKeyModel->status === 'revoked') {
            return response()->json([
                'success' => false,
                'message' => 'License key has been revoked.',
            ], 422);
        }

        // Check if already activated on different hardware
        if ($licenseKeyModel->status === 'active' && $licenseKeyModel->hardware_id && $licenseKeyModel->hardware_id !== $hardwareId) {
            return response()->json([
                'success' => false,
                'message' => 'License is already activated on a different machine.',
            ], 422);
        }

        // Get or create entitlement
        $entitlement = $licenseKeyModel->entitlement;
        if (! $entitlement) {
            return response()->json([
                'success' => false,
                'message' => 'No entitlement found for this license key.',
            ], 422);
        }

        // Check if license has expired
        if ($entitlement->isExpired()) {
            $licenseKeyModel->update(['status' => 'expired']);

            return response()->json([
                'success' => false,
                'message' => 'License has expired.',
            ], 422);
        }

        // Generate instance ID
        $instanceId = $licenseKeyModel->instance_id ?: 'INST-'.strtoupper(Str::random(16));

        // Recalculate entitlement to get latest effective values
        $this->calculator->recalculate($entitlement);

        // Build the license token payload (must match what SystemLicenseGuard expects)
        $issuedAt = Carbon::now();
        $validUntil = $entitlement->valid_until ?: $issuedAt->copy()->addYear();

        $payload = $this->tokenSigner->buildPayload(
            instanceId: $instanceId,
            clientName: $tenant->client_name,
            clientCode: $tenant->client_code,
            licenseKey: $licenseKey,
            hardwareId: $hardwareId,
            tier: $entitlement->tier->slug,
            issuedAt: $issuedAt->toIso8601String(),
            validUntil: $validUntil->toIso8601String(),
            maxUsers: $entitlement->effective_max_users,
            allowedModules: $entitlement->effective_modules ?? [],
        );

        // Sign the token
        try {
            $token = $this->tokenSigner->sign($payload);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to sign license token.',
            ], 500);
        }

        // Update license key
        $licenseKeyModel->update([
            'status' => 'active',
            'issued_at' => $issuedAt,
            'valid_until' => $validUntil,
            'last_synced_at' => $issuedAt,
            'hardware_id' => $hardwareId,
            'instance_id' => $instanceId,
            'hostname' => $hostname,
            'app_version' => $appVersion,
        ]);

        // Generate a service-to-service API token for this tenant (for heartbeat auth)
        $s2sToken = config('license.s2s_token_prefix').Str::random(48);
        $tenant->update([
            'api_token_hash' => hash('sha256', $s2sToken),
            'last_heartbeat_at' => $issuedAt,
        ]);

        // Audit log
        HubAuditLog::create([
            'tenant_id' => $tenant->id,
            'event_type' => 'license.activated',
            'details' => [
                'license_key' => $licenseKey,
                'hardware_id' => $hardwareId,
                'instance_id' => $instanceId,
                'tier' => $entitlement->tier->slug,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'actor_type' => 'api',
        ]);

        // Push module statuses via webhook
        $this->moduleSyncService->pushToTenant($tenant);

        return response()->json([
            'token' => $token,
            'status' => 'active',
            'instance_id' => $instanceId,
            'valid_until' => $validUntil->toIso8601String(),
            's2s_token' => $s2sToken,
            's2s_token_note' => 'Use this token in Authorization: Bearer header for heartbeat requests.',
        ]);
    }

    /**
     * POST /api/v1/licenses/heartbeat
     *
     * Called by CentralHubClientService::sendHeartbeat() on the client side.
     * Authenticated via service-to-service token (Authorization: Bearer).
     * Expects: instance_id, client_code, license_key, hardware_id, app_version, php_version, timestamp
     * Returns: { status: "active", token: "...", ... }
     */
    public function heartbeat(HeartbeatRequest $request): JsonResponse
    {
        $tenant = $request->user(); // Tenant resolved by the `tenant` guard
        if (! $tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not authenticated.',
            ], 401);
        }

        // Record heartbeat
        TenantHeartbeat::create([
            'tenant_id' => $tenant->id,
            'instance_id' => $request->input('instance_id'),
            'license_key' => $request->input('license_key'),
            'hardware_id' => $request->input('hardware_id'),
            'app_version' => $request->input('app_version'),
            'php_version' => $request->input('php_version'),
            'hostname' => $request->input('hostname'),
            'ip_address' => $request->ip(),
            'metadata' => $request->only(['timestamp']),
        ]);

        // Clear any standing offline alert — a fresh one can fire the next
        // time this tenant goes quiet for max_offline_days (see Tenant::
        // isOffline() / CheckTenantHeartbeats).
        $tenant->update(['last_heartbeat_at' => now(), 'offline_alert_sent_at' => null]);

        // Check entitlement status
        $entitlement = $tenant->activeEntitlement;
        if (! $entitlement) {
            return response()->json([
                'success' => false,
                'status' => 'unlicensed',
                'message' => 'No active entitlement found.',
            ], 200);
        }

        // Expire due add-ons and recalculate
        $previousMaxUsers = $entitlement->effective_max_users;
        $this->calculator->expireDueAddons($entitlement);
        $this->calculator->recalculate($entitlement);

        // Check if force-disable is needed
        if ($entitlement->effective_max_users < $previousMaxUsers) {
            $forceDisableManager = app(ForceDisableManager::class);
            $forceDisableManager->checkAndTrigger($entitlement, $previousMaxUsers);
        }

        // Check if license expired
        if ($entitlement->isExpired()) {
            $entitlement->update(['status' => 'expired']);

            return response()->json([
                'success' => false,
                'status' => 'expired',
                'message' => 'License has expired.',
            ], 200);
        }

        // Generate fresh token with latest entitlement values
        $licenseKeyModel = $entitlement->licenseKey;
        $issuedAt = Carbon::now();
        $validUntil = $entitlement->valid_until;

        $payload = $this->tokenSigner->buildPayload(
            instanceId: $licenseKeyModel->instance_id ?? 'INST-'.strtoupper(Str::random(16)),
            clientName: $tenant->client_name,
            clientCode: $tenant->client_code,
            licenseKey: $licenseKeyModel->license_key,
            hardwareId: $licenseKeyModel->hardware_id ?? $request->input('hardware_id'),
            tier: $entitlement->tier->slug,
            issuedAt: $issuedAt->toIso8601String(),
            validUntil: $validUntil->toIso8601String(),
            maxUsers: $entitlement->effective_max_users,
            allowedModules: $entitlement->effective_modules ?? [],
        );

        $token = null;
        try {
            $token = $this->tokenSigner->sign($payload);
        } catch (\RuntimeException $e) {
            // Still acknowledge heartbeat even if token signing fails
        }

        // Update sync timestamp
        $licenseKeyModel->update([
            'last_synced_at' => now(),
            'app_version' => $request->input('app_version'),
            'php_version' => $request->input('php_version'),
        ]);

        HubAuditLog::create([
            'tenant_id' => $tenant->id,
            'event_type' => 'license.heartbeat',
            'details' => [
                'instance_id' => $request->input('instance_id'),
                'hardware_id' => $request->input('hardware_id'),
            ],
            'ip_address' => $request->ip(),
            'actor_type' => 'api',
        ]);

        return response()->json([
            'success' => true,
            'status' => 'active',
            'token' => $token,
            'valid_until' => $validUntil->toIso8601String(),
            'effective_max_users' => $entitlement->effective_max_users,
            'effective_max_branches' => $entitlement->effective_max_branches,
            'effective_modules' => $entitlement->effective_modules,
        ]);
    }

    /**
     * POST /api/v1/licenses/validate
     *
     * Lightweight validation endpoint - just checks if the license is valid
     * without generating a new token. Uses service token auth.
     */
    public function validate(Request $request): JsonResponse
    {
        $tenant = $request->user(); // Tenant resolved by the `tenant` guard
        if (! $tenant) {
            return response()->json(['success' => false, 'message' => 'Not authenticated.'], 401);
        }

        $entitlement = $tenant->activeEntitlement;
        if (! $entitlement) {
            return response()->json([
                'success' => false,
                'status' => 'unlicensed',
                'message' => 'No active entitlement found.',
            ]);
        }

        $this->calculator->recalculate($entitlement);

        if ($entitlement->isExpired()) {
            return response()->json([
                'success' => false,
                'status' => 'expired',
                'message' => 'License expired.',
            ]);
        }

        return response()->json([
            'success' => true,
            'status' => 'active',
            'valid_until' => $entitlement->valid_until->toIso8601String(),
            'effective_max_users' => $entitlement->effective_max_users,
            'effective_max_branches' => $entitlement->effective_max_branches,
            'effective_modules' => $entitlement->effective_modules,
        ]);
    }
}
