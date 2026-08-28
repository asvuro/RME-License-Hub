<div>
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">Groups</h1>
        <button wire:click="openCreate" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">New group</button>
    </div>

    @if ($showForm)
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <h2 class="mb-3 text-lg font-semibold">{{ $editingId ? 'Edit group' : 'New group' }}</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">Name *</label>
                    <input type="text" wire:model="name" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
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
                <div>
                    <label class="mb-1 block text-sm font-medium">Status *</label>
                    <select wire:model="status" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <option value="active">active</option>
                        <option value="suspended">suspended</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium">Notes</label>
                    <textarea wire:model="notes" rows="2" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                </div>
            </div>
            <div class="mt-4 flex gap-2">
                <button wire:click="save" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Save</button>
                <button wire:click="$set('showForm', false)" class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">Cancel</button>
            </div>
        </div>
    @endif

    @if ($detail)
        <div class="mb-4 rounded-lg border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-700 dark:bg-indigo-900/20">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="text-lg font-semibold">{{ $detail->name }} <span class="text-sm font-normal text-gray-500">({{ $detail->tenants_count }} members)</span></h2>
                <button wire:click="$set('detailId', null)" class="text-sm text-gray-500 hover:underline">Close</button>
            </div>

            <div class="mb-3 flex flex-wrap gap-2">
                <select wire:model="assignTenantId" class="rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Assign a tenant…</option>
                    @foreach ($unassignedTenants as $t)
                        <option value="{{ $t->id }}">{{ $t->client_name }} ({{ $t->client_code }})</option>
                    @endforeach
                </select>
                <button wire:click="assignTenant" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Add</button>
            </div>

            @if ($detail->tenants->isEmpty())
                <p class="text-sm text-gray-500">No tenant members yet.</p>
            @else
                <ul class="space-y-1 text-sm">
                    @foreach ($detail->tenants as $t)
                        <li class="flex items-center justify-between border-b border-indigo-100 py-1 dark:border-indigo-800">
                            <span>{{ $t->client_name }} <span class="font-mono text-gray-500">({{ $t->client_code }})</span></span>
                            <button wire:click="removeTenant({{ $t->id }})" wire:confirm="Remove this tenant from the group?" class="text-red-600 hover:underline">Remove</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase text-gray-500 dark:border-gray-700 dark:bg-gray-900">
                <tr>
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Legal entity</th>
                    <th class="px-4 py-2">Members</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($groups as $group)
                    <tr class="border-b border-gray-100 dark:border-gray-700">
                        <td class="px-4 py-2">{{ $group->name }}</td>
                        <td class="px-4 py-2">{{ $group->legal_entity_name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $group->tenants_count }}</td>
                        <td class="px-4 py-2"><x-hub.status-badge :status="$group->status" /></td>
                        <td class="px-4 py-2">
                            <button wire:click="showDetail({{ $group->id }})" class="text-indigo-600 hover:underline">Members</button>
                            <button wire:click="openEdit({{ $group->id }})" class="ml-2 text-indigo-600 hover:underline">Edit</button>
                            <button wire:click="delete({{ $group->id }})" wire:confirm="Delete this group?" class="ml-2 text-red-600 hover:underline">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-4 text-center text-gray-500">No groups found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $groups->links() }}</div>
</div>
