<?php

use App\Http\Controllers\Auth\HubLoginController;
use App\Livewire\Hub\Admins\Index as AdminsIndex;
use App\Livewire\Hub\Audit\Index as AuditIndex;
use App\Livewire\Hub\Dashboard;
use App\Livewire\Hub\Addons\Index as AddonsIndex;
use App\Livewire\Hub\Groups\Index as GroupsIndex;
use App\Livewire\Hub\Licenses\Index as LicensesIndex;
use App\Livewire\Hub\Tenants\Index as TenantsIndex;
use App\Livewire\Hub\Tiers\Index as TiersIndex;
use App\Livewire\Hub\Webhooks\Index as WebhooksIndex;
use Illuminate\Support\Facades\Route;

/*
 * Admin dashboard routes.
 *
 * These use the dedicated "hub" auth guard and are COMPLETELY SEPARATE from the
 * client Sanctum API (routes/api.php, guard "sanctum"/"web"). A client API token
 * cannot authenticate here, and a hub session cannot be used to call the client API.
 */

Route::middleware('guest:hub')->group(function () {
    Route::get('/hub/login', [HubLoginController::class, 'showLoginForm'])->name('hub.login');
    Route::post('/hub/login', [HubLoginController::class, 'login']);
});

Route::middleware('auth:hub')->prefix('hub')->group(function () {
    Route::post('/logout', [HubLoginController::class, 'logout'])->name('hub.logout');

    Route::get('/', Dashboard::class)->name('hub.dashboard');

    Route::get('/tenants', TenantsIndex::class)->name('hub.tenants.index');
    Route::get('/tiers', TiersIndex::class)->name('hub.tiers.index');
    Route::get('/addons', AddonsIndex::class)->name('hub.addons.index');
    Route::get('/groups', GroupsIndex::class)->name('hub.groups.index');
    Route::get('/licenses', LicensesIndex::class)->name('hub.licenses.index');
    Route::get('/webhooks', WebhooksIndex::class)->name('hub.webhooks.index');
    Route::get('/audit', AuditIndex::class)->name('hub.audit.index');
    Route::get('/admins', AdminsIndex::class)->name('hub.admins.index');
});

// Redirect the site root to the admin login for convenience.
Route::get('/', fn () => redirect()->route('hub.login'));
