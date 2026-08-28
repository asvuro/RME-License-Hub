<?php

namespace App\Livewire\Hub\Groups;

use App\Livewire\Hub\HubComponent;
use App\Models\Group;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class Index extends HubComponent
{
    public ?string $editingId = null;

    public bool $showForm = false;

    public string $name = '';

    public ?string $legal_entity_name = null;

    public ?string $contact_email = null;

    public ?string $contact_phone = null;

    public string $status = 'active';

    public ?string $notes = null;

    public ?string $detailId = null;

    public ?string $assignTenantId = null;

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_entity_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function mount(): void
    {
        $this->requireManage();
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'name', 'legal_entity_name', 'contact_email', 'contact_phone', 'status', 'notes']);
        $this->status = 'active';
        $this->showForm = true;
    }

    public function openEdit(Group $group): void
    {
        Gate::authorize('manageHub');
        $this->editingId = $group->id;
        $this->name = $group->name;
        $this->legal_entity_name = $group->legal_entity_name;
        $this->contact_email = $group->contact_email;
        $this->contact_phone = $group->contact_phone;
        $this->status = $group->status;
        $this->notes = $group->notes;
        $this->showForm = true;
    }

    public function save(): void
    {
        Gate::authorize('manageHub');
        $data = $this->validate();

        if ($this->editingId) {
            Group::findOrFail($this->editingId)->update($data);
            session()->flash('status', 'Group updated.');
        } else {
            Group::create($data);
            session()->flash('status', 'Group created.');
        }

        $this->showForm = false;
        $this->reset(['editingId', 'name', 'legal_entity_name', 'contact_email', 'contact_phone', 'status', 'notes']);
    }

    public function delete(Group $group): void
    {
        Gate::authorize('manageHub');
        if ($group->tenants()->exists()) {
            session()->flash('error', 'Cannot delete a group that still has tenant members.');

            return;
        }
        $group->delete();
        session()->flash('status', 'Group deleted.');
    }

    public function showDetail(Group $group): void
    {
        Gate::authorize('viewHub');
        $this->detailId = $group->id;
        $this->assignTenantId = null;
    }

    public function assignTenant(): void
    {
        Gate::authorize('manageHub');
        $this->validateOnly('assignTenantId', [
            'assignTenantId' => ['required', 'uuid', 'exists:tenants,id'],
        ]);

        $group = Group::findOrFail($this->detailId);
        $tenant = Tenant::findOrFail($this->assignTenantId);
        $tenant->update(['group_id' => $group->id]);
        $this->assignTenantId = null;
        session()->flash('status', 'Tenant assigned to group.');
    }

    public function removeTenant(Tenant $tenant): void
    {
        Gate::authorize('manageHub');
        $tenant->update(['group_id' => null]);
        session()->flash('status', 'Tenant removed from group.');
    }

    public function render(): View
    {
        $detail = $this->detailId ? Group::with('tenants')->findOrFail($this->detailId) : null;

        return view('livewire.hub.groups.index', [
            'groups' => Group::withCount('tenants')->latest()->paginate(15),
            'detail' => $detail,
            'unassignedTenants' => Tenant::whereNull('group_id')->orderBy('client_name')->get(),
        ])->layout('layouts.hub');
    }
}
