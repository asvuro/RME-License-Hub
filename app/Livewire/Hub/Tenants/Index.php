<?php

namespace App\Livewire\Hub\Tenants;

use App\Livewire\Hub\HubComponent;
use App\Models\Group;
use App\Models\HubAuditLog;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class Index extends HubComponent
{
    public ?string $editingId = null;

    public bool $showForm = false;

    // form fields
    public ?string $group_id = null;

    public string $client_code = '';

    public string $client_name = '';

    public ?string $legal_entity_name = null;

    public ?string $contact_email = null;

    public ?string $contact_phone = null;

    public ?string $address = null;

    public string $status = 'active';

    public ?string $search = null;

    protected function rules(): array
    {
        return [
            'group_id' => ['nullable', 'uuid', 'exists:groups,id'],
            'client_code' => ['required', 'string', 'max:50', Rule::unique('tenants', 'client_code')->ignore($this->editingId)],
            'client_name' => ['required', 'string', 'max:255'],
            'legal_entity_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['active', 'suspended', 'terminated'])],
        ];
    }

    public function mount(): void
    {
        $this->requireManage();
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'group_id', 'client_code', 'client_name', 'legal_entity_name', 'contact_email', 'contact_phone', 'address', 'status']);
        $this->status = 'active';
        $this->showForm = true;
    }

    public function openEdit(Tenant $tenant): void
    {
        Gate::authorize('manageHub');
        $this->editingId = $tenant->id;
        $this->group_id = $tenant->group_id;
        $this->client_code = $tenant->client_code;
        $this->client_name = $tenant->client_name;
        $this->legal_entity_name = $tenant->legal_entity_name;
        $this->contact_email = $tenant->contact_email;
        $this->contact_phone = $tenant->contact_phone;
        $this->address = $tenant->address;
        $this->status = $tenant->status;
        $this->showForm = true;
    }

    public function save(): void
    {
        Gate::authorize('manageHub');
        $data = $this->validate();

        if ($this->editingId) {
            $tenant = Tenant::findOrFail($this->editingId);
            $tenant->update($data);
            HubAuditLog::record('tenant.updated', $this->admin(), ['tenant_id' => $tenant->id], $tenant->id);
            session()->flash('status', 'Tenant updated.');
        } else {
            $tenant = Tenant::create($data);
            HubAuditLog::record('tenant.created', $this->admin(), ['tenant_id' => $tenant->id], $tenant->id);
            session()->flash('status', 'Tenant created.');
        }

        $this->showForm = false;
        $this->reset(['editingId', 'group_id', 'client_code', 'client_name', 'legal_entity_name', 'contact_email', 'contact_phone', 'address', 'status']);
    }

    public function delete(Tenant $tenant): void
    {
        Gate::authorize('manageHub');
        HubAuditLog::record('tenant.deleted', $this->admin(), ['tenant_id' => $tenant->id], $tenant->id);
        $tenant->delete();
        session()->flash('status', 'Tenant deleted.');
    }

    public function render(): View
    {
        $query = Tenant::with('group')->withCount('licenseKeys');
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('client_name', 'like', "%{$this->search}%")
                    ->orWhere('client_code', 'like', "%{$this->search}%");
            });
        }

        return view('livewire.hub.tenants.index', [
            'tenants' => $query->latest()->paginate(15),
            'groups' => Group::orderBy('name')->get(),
        ])->layout('layouts.hub');
    }
}
