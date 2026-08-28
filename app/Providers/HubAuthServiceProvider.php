<?php

namespace App\Providers;

use App\Models\HubAdmin;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider;
use Illuminate\Support\Facades\Gate;

class HubAuthServiceProvider extends AuthServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Any authenticated, active PT staff member may view the dashboard.
        Gate::define('viewHub', function (HubAdmin $admin) {
            return $admin->is_active;
        });

        // Active superadmin OR operator may perform management actions
        // (CRUD on tenants/tiers/addons/licenses/groups, issue/suspend/revoke).
        Gate::define('manageHub', function (HubAdmin $admin) {
            return $admin->is_active
                && in_array($admin->role, ['superadmin', 'operator'], true);
        });

        // Only superadmin may administer other hub admin accounts.
        Gate::define('administerHub', function (HubAdmin $admin) {
            return $admin->is_active && $admin->role === 'superadmin';
        });
    }
}
