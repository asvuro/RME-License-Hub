<?php

use App\Http\Controllers\GroupApiController;
use App\Http\Controllers\GroupRelayController;
use App\Http\Controllers\HubAdminController;
use App\Http\Controllers\HubAuthController;
use App\Http\Controllers\LicenseApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Client-facing API (called by SystemLicenseGuard on RME-Backend instances)
|--------------------------------------------------------------------------
| These endpoints are the "server side" of the client-server license contract.
| Activation is unauthenticated (the license_key itself is the credential).
| Heartbeat/validate/roster use the dedicated `tenant` guard (stateless
| service-to-service token, hashed against tenants.api_token_hash) — a single
| trust path, consistent with the Reverb broadcast auth.
*/

Route::prefix('v1/licenses')->group(function () {
    Route::post('/activate', [LicenseApiController::class, 'activate'])
        ->middleware('throttle:10,1');

    // Authenticated via the `tenant` guard (service-to-service bearer token).
    Route::middleware(['auth:tenant', 'throttle:60,1'])->group(function () {
        Route::post('/heartbeat', [LicenseApiController::class, 'heartbeat']);
        Route::post('/validate', [LicenseApiController::class, 'validate']);
        // Roster reporting (hub-authoritative force-disable needs the client roster)
        Route::post('/roster', [LicenseApiController::class, 'reportRoster']);
    });
});

/*
|--------------------------------------------------------------------------
| Group Realtime Relay API (tenant -> hub -> siblings via Reverb)
|--------------------------------------------------------------------------
| Contract surface for RME-Backend Modules/Grup (reconciled).
| All authenticated via the `tenant` guard.
*/
Route::prefix('v1/group')->middleware(['auth:tenant', 'throttle:120,1'])->group(function () {
    Route::get('/context', [GroupApiController::class, 'context']);
    Route::post('/realtime/auth', [GroupApiController::class, 'realtimeAuth']);
    Route::post('/relay', [GroupApiController::class, 'relay']);
    // Hub-proxied clinical/referral fetch (signed egress to target branch).
    Route::get('/relay/{path}', [GroupApiController::class, 'relayProxy'])->where('path', '.*');
});

Route::prefix('v1/hub')->group(function () {
    Route::post('/auth/login', [HubAuthController::class, 'login']);

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/auth/logout', [HubAuthController::class, 'logout']);

        // Dashboard
        Route::get('/dashboard', [HubAdminController::class, 'dashboard']);

        // Groups
        Route::post('/groups', [HubAdminController::class, 'createGroup']);
        Route::get('/groups', [HubAdminController::class, 'listGroups']);
        Route::post('/groups/{group}/tenants', [HubAdminController::class, 'addTenantToGroup']);

        // Tenants
        Route::post('/tenants', [HubAdminController::class, 'createTenant']);
        Route::get('/tenants', [HubAdminController::class, 'listTenants']);
        Route::get('/tenants/{tenant}', [HubAdminController::class, 'showTenant']);
        Route::post('/tenants/{tenant}/suspend', [HubAdminController::class, 'suspendTenant']);

        // License Keys
        Route::post('/licenses/issue', [HubAdminController::class, 'issueLicenseKey']);
        Route::get('/licenses', [HubAdminController::class, 'listLicenseKeys']);

        // Add-ons
        Route::post('/addons', [HubAdminController::class, 'addAddon']);

        // Module Sync
        Route::post('/sync/tenant/{tenant}', [HubAdminController::class, 'pushModuleSync']);
        Route::post('/sync/all', [HubAdminController::class, 'pushAllModuleSync']);
    });
});
