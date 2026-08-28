<?php

namespace App\Livewire\Hub\Webhooks;

use App\Livewire\Hub\HubComponent;
use App\Models\HubAuditLog;
use App\Models\WebhookDelivery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class Index extends HubComponent
{
    public ?string $detailId = null;

    public ?string $statusFilter = null;

    public function mount(): void
    {
        $this->requireView();
    }

    public function retry(WebhookDelivery $wh): void
    {
        Gate::authorize('manageHub');
        if (! $wh->canRetry()) {
            session()->flash('error', 'This webhook cannot be retried.');

            return;
        }
        $wh->update([
            'attempts' => 0,
            'next_attempt_at' => now(),
            'last_response_code' => null,
            'last_response_body' => null,
        ]);
        HubAuditLog::record('webhook.retry_queued', $this->admin(), ['webhook_id' => $wh->id], $wh->tenant_id);
        session()->flash('status', 'Webhook queued for retry.');
    }

    public function showDetail(WebhookDelivery $wh): void
    {
        Gate::authorize('viewHub');
        $this->detailId = $wh->id;
    }

    public function render(): View
    {
        $query = WebhookDelivery::with('tenant');
        if ($this->statusFilter === 'delivered') {
            $query->whereNotNull('delivered_at');
        } elseif ($this->statusFilter === 'failed') {
            $query->whereNull('delivered_at');
        }

        $detail = $this->detailId ? WebhookDelivery::with('tenant')->findOrFail($this->detailId) : null;

        return view('livewire.hub.webhooks.index', [
            'webhooks' => $query->latest()->paginate(20),
            'detail' => $detail,
        ])->layout('layouts.hub');
    }
}
