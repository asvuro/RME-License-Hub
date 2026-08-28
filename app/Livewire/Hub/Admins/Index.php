<?php

namespace App\Livewire\Hub\Admins;

use App\Livewire\Hub\HubComponent;
use App\Models\HubAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class Index extends HubComponent
{
    public ?int $editingId = null;

    public bool $showForm = false;

    public string $name = '';

    public string $email = '';

    public ?string $password = null;

    public string $password_confirmation = '';

    public string $role = 'operator';

    public bool $is_active = true;

    protected function rules(): array
    {
        $unique = Rule::unique('hub_admins', 'email')->ignore($this->editingId);

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', $unique],
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(['superadmin', 'operator'])],
            'is_active' => ['boolean'],
        ];
    }

    public function mount(): void
    {
        // Only superadmin may access this page.
        Gate::authorize('administerHub');
    }

    public function openCreate(): void
    {
        Gate::authorize('administerHub');
        $this->reset(['editingId', 'name', 'email', 'password', 'password_confirmation', 'role', 'is_active']);
        $this->role = 'operator';
        $this->is_active = true;
        $this->showForm = true;
    }

    public function openEdit(HubAdmin $admin): void
    {
        Gate::authorize('administerHub');
        $this->editingId = $admin->id;
        $this->name = $admin->name;
        $this->email = $admin->email;
        $this->password = null;
        $this->password_confirmation = '';
        $this->role = $admin->role;
        $this->is_active = $admin->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        Gate::authorize('administerHub');
        $data = $this->validate();

        $password = $data['password'] ?? null;
        $payload = [
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'is_active' => $data['is_active'],
        ];
        if ($password) {
            $payload['password'] = bcrypt($password);
        }

        if ($this->editingId) {
            HubAdmin::findOrFail($this->editingId)->update($payload);
            session()->flash('status', 'Admin updated.');
        } else {
            HubAdmin::create($payload);
            session()->flash('status', 'Admin created.');
        }

        $this->showForm = false;
        $this->reset(['editingId', 'name', 'email', 'password', 'password_confirmation', 'role', 'is_active']);
    }

    public function toggleActive(HubAdmin $admin): void
    {
        Gate::authorize('administerHub');
        if ($admin->id === $this->admin()->id) {
            session()->flash('error', 'You cannot disable your own account.');

            return;
        }
        $admin->update(['is_active' => ! $admin->is_active]);
        session()->flash('status', 'Admin status updated.');
    }

    public function delete(HubAdmin $admin): void
    {
        Gate::authorize('administerHub');
        if ($admin->id === $this->admin()->id) {
            session()->flash('error', 'You cannot delete your own account.');

            return;
        }
        $admin->delete();
        session()->flash('status', 'Admin deleted.');
    }

    public function render(): View
    {
        return view('livewire.hub.admins.index', [
            'admins' => HubAdmin::latest()->paginate(15),
        ])->layout('layouts.hub');
    }
}
