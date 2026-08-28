<?php

namespace App\Livewire\Hub\Addons;

use App\Livewire\Hub\HubComponent;
use App\Models\ModuleModel;
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

    public bool $is_active = true;

    protected function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:100', Rule::unique('modules', 'slug')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }

    public function mount(): void
    {
        $this->requireManage();
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'slug', 'name', 'description', 'is_active']);
        $this->is_active = true;
        $this->showForm = true;
    }

    public function openEdit(ModuleModel $addon): void
    {
        Gate::authorize('manageHub');
        $this->editingId = $addon->id;
        $this->slug = $addon->slug;
        $this->name = $addon->name;
        $this->description = $addon->description;
        $this->is_active = $addon->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        Gate::authorize('manageHub');
        $data = $this->validate();

        if ($this->editingId) {
            ModuleModel::findOrFail($this->editingId)->update($data);
            session()->flash('status', 'Add-on module updated.');
        } else {
            ModuleModel::create($data);
            session()->flash('status', 'Add-on module created.');
        }

        $this->showForm = false;
        $this->reset(['editingId', 'slug', 'name', 'description', 'is_active']);
    }

    public function delete(ModuleModel $addon): void
    {
        Gate::authorize('manageHub');
        $addon->delete();
        session()->flash('status', 'Add-on module deleted.');
    }

    public function render(): View
    {
        return view('livewire.hub.addons.index', [
            'addons' => ModuleModel::latest()->paginate(15),
        ])->layout('layouts.hub');
    }
}
