<?php

namespace App\Livewire\Hub\Audit;

use App\Livewire\Hub\HubComponent;
use App\Models\HubAuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class Index extends HubComponent
{
    public ?string $detailId = null;

    public ?string $eventFilter = null;

    public function mount(): void
    {
        $this->requireView();
    }

    public function showDetail(HubAuditLog $log): void
    {
        Gate::authorize('viewHub');
        $this->detailId = $log->id;
    }

    public function render(): View
    {
        $query = HubAuditLog::with('tenant');
        if ($this->eventFilter) {
            $query->where('event_type', $this->eventFilter);
        }

        $detail = $this->detailId ? HubAuditLog::with('tenant')->findOrFail($this->detailId) : null;

        return view('livewire.hub.audit.index', [
            'logs' => $query->latest()->paginate(25),
            'detail' => $detail,
            'events' => HubAuditLog::distinct()->pluck('event_type'),
        ])->layout('layouts.hub');
    }
}
