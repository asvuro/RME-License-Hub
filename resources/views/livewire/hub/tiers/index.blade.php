<div>
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">Tiers</h1>
        <button wire:click="openCreate" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">New tier</button>
    </div>

    @if ($showForm)
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <h2 class="mb-3 text-lg font-semibold">{{ $editingId ? 'Edit tier' : 'New tier' }}</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">Slug *</label>
                    <input type="text" wire:model="slug" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                    @error('slug') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Name *</label>
                    <input type="text" wire:model="name" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Base max users</label>
                    <input type="number" wire:model="base_max_users" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Default duration (days)</label>
                    <input type="number" wire:model="default_duration_days" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium">Description</label>
                    <textarea wire:model="description" rows="2" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium">Included modules (comma separated)</label>
                    <input type="text" wire:model="included_modules" placeholder="SatSatuSehatIgd, ModulResep" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                </div>
                <div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="is_active" class="rounded border-gray-300 text-indigo-600" /> Active
                    </label>
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
                    <th class="px-4 py-2">Slug</th>
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Max users</th>
                    <th class="px-4 py-2">Duration</th>
                    <th class="px-4 py-2">Modules</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tiers as $tier)
                    <tr class="border-b border-gray-100 dark:border-gray-700">
                        <td class="px-4 py-2 font-mono">{{ $tier->slug }}</td>
                        <td class="px-4 py-2">{{ $tier->name }}</td>
                        <td class="px-4 py-2">{{ $tier->base_max_users ?: '∞' }}</td>
                        <td class="px-4 py-2">{{ $tier->default_duration_days }}d</td>
                        <td class="px-4 py-2">{{ is_array($tier->included_modules) ? count($tier->included_modules) : 0 }}</td>
                        <td class="px-4 py-2"><x-hub.status-badge :status="$tier->is_active ? 'active' : 'suspended'" /></td>
                        <td class="px-4 py-2">
                            <button wire:click="openEdit({{ $tier->id }})" class="text-indigo-600 hover:underline">Edit</button>
                            <button wire:click="delete({{ $tier->id }})" wire:confirm="Delete this tier?" class="ml-2 text-red-600 hover:underline">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-4 text-center text-gray-500">No tiers found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $tiers->links() }}</div>
</div>
