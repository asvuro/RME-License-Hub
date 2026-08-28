<div>
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">Tenants</h1>
        <button wire:click="openCreate" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">New tenant</button>
    </div>

    <div class="mb-4">
        <input type="text" wire:model.live="search" placeholder="Search client name / code..."
            class="w-64 rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
    </div>

    @if ($showForm)
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <h2 class="mb-3 text-lg font-semibold">{{ $editingId ? 'Edit tenant' : 'New tenant' }}</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">Client code *</label>
                    <input type="text" wire:model="client_code" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                    @error('client_code') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Client name *</label>
                    <input type="text" wire:model="client_name" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                    @error('client_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Group</label>
                    <select wire:model="group_id" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <option value="">— No group —</option>
                        @foreach ($groups as $g)
                            <option value="{{ $g->id }}">{{ $g->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Status *</label>
                    <select wire:model="status" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <option value="active">active</option>
                        <option value="suspended">suspended</option>
                        <option value="terminated">terminated</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Legal entity</label>
                    <input type="text" wire:model="legal_entity_name" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Contact email</label>
                    <input type="email" wire:model="contact_email" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                    @error('contact_email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Contact phone</label>
                    <input type="text" wire:model="contact_phone" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium">Address</label>
                    <textarea wire:model="address" rows="2" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                </div>
            </div>
            <div class="mt-4 flex gap-2">
                <button wire:click="save" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Save</button>
                <button wire:click="$set('showForm', false)" class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">Cancel</button>
            </div>
        </div>
    @endif

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase text-gray-500 dark:border-gray-700 dark:bg-gray-900">
                <tr>
                    <th class="px-4 py-2">Client code</th>
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Group</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Licenses</th>
                    <th class="px-4 py-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tenants as $tenant)
                    <tr class="border-b border-gray-100 dark:border-gray-700">
                        <td class="px-4 py-2 font-mono">{{ $tenant->client_code }}</td>
                        <td class="px-4 py-2">{{ $tenant->client_name }}</td>
                        <td class="px-4 py-2">{{ $tenant->group?->name ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <x-hub.status-badge :status="$tenant->status" />
                        </td>
                        <td class="px-4 py-2">{{ $tenant->license_keys_count }}</td>
                        <td class="px-4 py-2">
                            <a href="{{ route('hub.licenses.index', ['tenant_id' => $tenant->id]) }}" class="text-indigo-600 hover:underline">Licenses</a>
                            <button wire:click="openEdit({{ $tenant->id }})" class="ml-2 text-indigo-600 hover:underline">Edit</button>
                            <button wire:click="delete({{ $tenant->id }})" wire:confirm="Delete this tenant?" class="ml-2 text-red-600 hover:underline">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-4 text-center text-gray-500">No tenants found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $tenants->links() }}
    </div>
</div>
