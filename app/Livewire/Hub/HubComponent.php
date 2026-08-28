<?php

namespace App\Livewire\Hub;

use App\Models\HubAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Base class for all admin-dashboard Livewire components.
 *
 * Enforces authorization against the dedicated "hub" guard (PT staff only),
 * never the client Sanctum API surface. Mutating pages call {@see requireManage}
 * (superadmin|operator), read-only pages call {@see requireView}.
 */
abstract class HubComponent extends Component
{
    use AuthorizesRequests;

    /**
     * Require an active hub admin with management rights (superadmin|operator),
     * then return the authenticated admin.
     */
    protected function requireManage(): HubAdmin
    {
        Gate::authorize('manageHub');

        /** @var HubAdmin $admin */
        $admin = auth('hub')->user();

        return $admin;
    }

    /**
     * Require an active hub admin (any role) for read-only access.
     */
    protected function requireView(): HubAdmin
    {
        Gate::authorize('viewHub');

        /** @var HubAdmin $admin */
        $admin = auth('hub')->user();

        return $admin;
    }

    abstract public function render(): View;
}
