<?php

namespace App\Livewire\Hub\Licenses;

use App\Livewire\Hub\HubComponent;
use App\Models\HubAuditLog;
use App\Models\LicenseEntitlement;
use App\Models\LicenseKey;
use App\Models\Tenant;
use App\Models\Tier;
use App\Services\License\LicenseIssuer;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class Index extends HubComponent
{
    public ?string $detailId = null;

    // issue form
    public bool $showIssue = false;

    public ?string $tenant_id = null;

    public ?int $tier_id = null;

    public ?int $duration_days = null;

    public ?string $tenantFilter = null;

    public ?string $statusFilter = null;

    protected function issueRules(): array
    {
        return [
            'tenant_id' => ['required', 'uuid', 'exists:tenants,id'],
            'tier_id' => ['required', 'integer', 'exists:tiers,id'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }

    public function mount(): void
    {
        $this->requireManage();
    }

    public function openIssue(): void
    {
        Gate::authorize('manageHub');
        $this->reset(['tenant_id', 'tier_id', 'duration_days']);
        $this->showIssue = true;
    }

    public function issue(LicenseIssuer $issuer): void
    {
        Gate::authorize('manageHub');
        $data = $this->validate($this->issueRules());

        $tenant = Tenant::findOrFail($data['tenant_id']);
        if ($tenant->status === 'terminated') {
            session()->flash('error', 'Cannot issue a license to a terminated tenant.');

            return;
        }

        $tier = Tier::findOrFail($data['tier_id']);
        $license = $issuer->issue($tenant, $tier, $data['duration_days'] ?? null);

        HubAuditLog::record('license.issued', $this->admin(), [
            'tenant_id' => $tenant->id,
            'tier_id' => $tier->id,
            'license_key' => $license->license_key,
        ], $tenant->id);

        $this->showIssue = false;
        $this->reset(['tenant_id', 'tier_id', 'duration_days']);
        session()->flash('status', 'License issued: '.$license->license_key);
    }

    public function suspend(LicenseKey $license): void
    {
        Gate::authorize('manageHub');
        if (! in_array($license->status, ['active', 'unused'])) {
            session()->flash('error', 'Only active/unused licenses can be suspended.');

            return;
        }
        $license->update(['status' => 'suspended']);
        HubAuditLog::record('license.suspended', $this->admin(), ['license_key' => $license->license_key], $license->tenant_id);
        session()->flash('status', 'License suspended.');
    }

    public function revoke(LicenseKey $license): void
    {
        Gate::authorize('manageHub');
        if ($license->status === 'revoked') {
            session()->flash('error', 'License is already revoked.');

            return;
        }
        $license->update(['status' => 'revoked']);
        HubAuditLog::record('license.revoked', $this->admin(), ['license_key' => $license->license_key], $license->tenant_id);
        session()->flash('status', 'License revoked.');
    }

    public function reactivate(LicenseKey $license): void
    {
        Gate::authorize('manageHub');
        if ($license->status !== 'suspended') {
            session()->flash('error', 'Only suspended licenses can be reactivated.');

            return;
        }
        $license->update(['status' => 'active']);
        HubAuditLog::record('license.reactivated', $this->admin(), ['license_key' => $license->license_key], $license->tenant_id);
        session()->flash('status', 'License reactivated.');
    }

    public function showDetail(LicenseKey $license): void
    {
        Gate::authorize('viewHub');
        $this->detailId = $license->id;
    }

    // --- add-on assignment (on the detail panel) ---
    public ?string $addon_entitlement_id = null;

    public string $addon_type = 'module';

    public ?string $addon_target_module_slug = null;

    public int $addon_quantity = 1;

    public ?string $addon_label = null;

    public ?string $addon_effective_from = null;

    public ?string $addon_effective_until = null;

    protected function addonRules(): array
    {
        return [
            'addon_entitlement_id' => ['required', 'uuid', 'exists:license_entitlements,id'],
            'addon_type' => ['required', Rule::in(['module', 'user_quota', 'branch_quota', 'time_extension'])],
            'addon_target_module_slug' => ['required_if:addon_type,module', 'nullable', 'string', 'max:100'],
            'addon_quantity' => ['required', 'integer', 'min:1'],
            'addon_label' => ['nullable', 'string', 'max:255'],
            'addon_effective_from' => ['nullable', 'date'],
            'addon_effective_until' => ['nullable', 'date', 'after_or_equal:addon_effective_from'],
        ];
    }

    public function assignAddon(): void
    {
        Gate::authorize('manageHub');
        $data = $this->validate($this->addonRules());

        $entitlement = LicenseEntitlement::findOrFail($data['addon_entitlement_id']);
        $entitlement->addons()->create([
            'addon_type' => $data['addon_type'],
            'target_module_slug' => $data['addon_type'] === 'module' ? $data['addon_target_module_slug'] : null,
            'quantity' => $data['addon_quantity'],
            'label' => $data['addon_label'],
            'effective_from' => $data['addon_effective_from'] ? Carbon::parse($data['addon_effective_from']) : now(),
            'effective_until' => $data['addon_effective_until'] ? Carbon::parse($data['addon_effective_until']) : null,
            'status' => 'active',
        ]);

        HubAuditLog::record('license.addon_assigned', $this->admin(), [
            'entitlement_id' => $entitlement->id,
            'addon_type' => $data['addon_type'],
        ], $entitlement->tenant_id);

        $this->reset(['addon_entitlement_id', 'addon_type', 'addon_target_module_slug', 'addon_quantity', 'addon_label', 'addon_effective_from', 'addon_effective_until']);
        $this->addon_quantity = 1;
        session()->flash('status', 'Add-on assigned to license.');
    }

    public function render(): View
    {
        $query = LicenseKey::with('tenant', 'entitlement.tier', 'entitlement.addons');

        if ($this->tenantFilter) {
            $query->where('tenant_id', $this->tenantFilter);
        }
        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        $detail = $this->detailId ? LicenseKey::with('tenant', 'entitlement.tier', 'entitlement.addons')->findOrFail($this->detailId) : null;

        return view('livewire.hub.licenses.index', [
            'licenses' => $query->latest('issued_at')->paginate(15),
            'detail' => $detail,
            'tenants' => Tenant::orderBy('client_name')->get(),
            'tiers' => Tier::where('is_active', true)->orderBy('name')->get(),
            'statuses' => ['unused', 'active', 'suspended', 'expired', 'revoked'],
        ])->layout('layouts.hub');
    }
}
