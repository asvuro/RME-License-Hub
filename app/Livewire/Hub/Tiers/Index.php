<?php

namespace App\Livewire\Hub\Tiers;

use App\Livewire\Hub\HubComponent;
use App\Models\Tier;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class Index extends HubComponent
{
    public ?int $editingId = null;

    public bool $showForm = false;

    public string $slug = '';

    public string $name = '';

    public ?string $description = null;

    public int $base_max_users = 0;

    public int $default_duration_days = 365;

    public array $included_modules = [];

    public bool $is_active = true;

    protected function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:50', Rule::unique('tiers', 'slug')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'base_max_users' => ['required', 'integer', 'min:0'],
            'default_duration_days' => ['required', 'integer', 'min:1'],
            'included_modules' => ['nullable', 'array'],
            'included_modules.*' => ['string', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }

    public function mount(): void
    {
        $this->requireManage();
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'slug', 'name', 'description', 'base_max_users', 'default_duration_days', 'included_modules', 'is_active']);
        $this->base_max_users = 0;
        $this->default_duration_days = 365;
        $this->included_modules = [];
        $this->is_active = true;
        $this->showForm = true;
    }

    public function openEdit(Tier $tier): void
    {
        Gate::authorize('manageHub');
        $this->editingId = $tier->id;
        $this->slug = $tier->slug;
        $this->name = $tier->name;
        $this->description = $tier->description;
        $this->base_max_users = $tier->base_max_users;
        $this->default_duration_days = $tier->default_duration_days;
        $this->included_modules = $tier->included_modules ?? [];
        $this->is_active = $tier->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        Gate::authorize('manageHub');
        $data = $this->validate();

        if ($this->editingId) {
            Tier::findOrFail($this->editingId)->update($data);
            session()->flash('status', 'Tier updated.');
        } else {
            Tier::create($data);
            session()->flash('status', 'Tier created.');
        }

        $this->showForm = false;
        $this->reset(['editingId', 'slug', 'name', 'description', 'base_max_users', 'default_duration_days', 'included_modules', 'is_active']);
    }

    public function delete(Tier $tier): void
    {
        Gate::authorize('manageHub');
        if ($tier->entitlements()->exists()) {
            session()->flash('error', 'Cannot delete a tier that has issued entitlements.');

            return;
        }
        $tier->delete();
        session()->flash('status', 'Tier deleted.');
    }

    public function render(): View
    {
        return view('livewire.hub.tiers.index', [
            'tiers' => Tier::latest()->paginate(15),
        ])->layout('layouts.hub');
    }
}
