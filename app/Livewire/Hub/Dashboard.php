<?php

namespace App\Livewire\Hub;

use App\Models\Group;
use App\Models\HubAuditLog;
use App\Models\LicenseKey;
use App\Models\ModuleModel;
use App\Models\Tenant;
use App\Models\Tier;
use App\Models\WebhookDelivery;
use Illuminate\Contracts\View\View;

class Dashboard extends HubComponent
{
    public function mount(): void
    {
        $this->requireView();
    }

    public function render(): View
    {
        return view('livewire.hub.dashboard', [
            'stats' => [
                'tenants' => Tenant::count(),
                'tenants_active' => Tenant::where('status', 'active')->count(),
                'tenants_suspended' => Tenant::where('status', 'suspended')->count(),
                'groups' => Group::count(),
                'tiers' => Tier::where('is_active', true)->count(),
                'addons' => ModuleModel::where('is_active', true)->count(),
                'licenses_active' => LicenseKey::where('status', 'active')->count(),
                'licenses_suspended' => LicenseKey::where('status', 'suspended')->count(),
                'licenses_revoked' => LicenseKey::where('status', 'revoked')->count(),
                'webhooks_pending' => WebhookDelivery::whereNull('delivered_at')->count(),
                'webhooks_failed' => WebhookDelivery::whereNotNull('delivered_at')->whereColumn('attempts', '>=', 'max_attempts')->count(),
            ],
            'recent_audit' => HubAuditLog::with('tenant')->latest()->limit(10)->get(),
            'recent_webhooks' => WebhookDelivery::with('tenant')->latest()->limit(10)->get(),
        ])->layout('layouts.hub');
    }
}
