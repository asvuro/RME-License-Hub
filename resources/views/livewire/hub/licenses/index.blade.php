<div>
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">Licenses</h1>
        <button wire:click="openIssue" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Issue license</button>
    </div>

    <div class="mb-4 flex flex-wrap gap-2">
        <select wire:model.live="statusFilter" class="rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            <option value="">All statuses</option>
            @foreach ($statuses as $s)
                <option value="{{ $s }}">{{ $s }}</option>
            @endforeach
        </select>
        <select wire:model.live="tenantFilter" class="rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            <option value="">All tenants</option>
            @foreach ($tenants as $t)
                <option value="{{ $t->id }}">{{ $t->client_name }}</option>
            @endforeach
        </select>
    </div>

    @if ($showIssue)
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <h2 class="mb-3 text-lg font-semibold">Issue new license</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium">Tenant *</label>
                    <select wire:model="tenant_id" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <option value="">Select tenant…</option>
                        @foreach ($tenants as $t)
                            <option value="{{ $t->id }}">{{ $t->client_name }} ({{ $t->client_code }})</option>
                        @endforeach
                    </select>
                    @error('tenant_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Tier *</label>
                    <select wire:model="tier_id" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <option value="">Select tier…</option>
                        @foreach ($tiers as $t)
                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                        @endforeach
                    </select>
                    @error('tier_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Duration (days, optional)</label>
                    <input type="number" wire:model="duration_days" placeholder="tier default" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                </div>
            </div>
            <div class="mt-4 flex gap-2">
                <button wire:click="issue" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Issue</button>
                <button wire:click="$set('showIssue', false)" class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">Cancel</button>
            </div>
        </div>
    @endif

    @if ($detail)
        <div class="mb-4 rounded-lg border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-700 dark:bg-indigo-900/20">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="text-lg font-semibold font-mono">{{ $detail->license_key }}</h2>
                <button wire:click="$set('detailId', null)" class="text-sm text-gray-500 hover:underline">Close</button>
            </div>
            <p class="text-sm">Tenant: <span class="font-medium">{{ $detail->tenant?->client_name ?? '—' }}</span></p>
            <p class="text-sm">Status: <x-hub.status-badge :status="$detail->status" /></p>
            @if ($detail->entitlement)
                <p class="text-sm">Tier: {{ $detail->entitlement->tier?->name ?? '—' }}</p>
                <p class="text-sm">Max users: {{ $detail->entitlement->effective_max_users }} &middot; Max branches: {{ $detail->entitlement->effective_max_branches }}</p>
                <p class="text-sm">Valid until: {{ $detail->entitlement->valid_until?->format('Y-m-d') ?? '—' }}</p>
                <p class="text-sm">Modules: {{ is_array($detail->entitlement->effective_modules) ? implode(', ', $detail->entitlement->effective_modules) : '—' }}</p>
            @endif

            @can('manageHub')
            <div class="mt-3 rounded border border-indigo-100 p-3 dark:border-indigo-800">
                <h3 class="mb-2 text-sm font-semibold">Assign add-on</h3>
                <input type="hidden" wire:model="addon_entitlement_id" value="{{ $detail->entitlement?->id }}" />
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-4">
                    <select wire:model="addon_type" class="rounded-md border border-gray-300 px-2 py-1 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <option value="module">module</option>
                        <option value="user_quota">user_quota</option>
                        <option value="branch_quota">branch_quota</option>
                        <option value="time_extension">time_extension</option>
                    </select>
                    <input type="text" wire:model="addon_target_module_slug" placeholder="module slug" class="rounded-md border border-gray-300 px-2 py-1 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                    <input type="number" wire:model="addon_quantity" class="rounded-md border border-gray-300 px-2 py-1 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                    <input type="text" wire:model="addon_label" placeholder="label" class="rounded-md border border-gray-300 px-2 py-1 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                </div>
                <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <input type="date" wire:model="addon_effective_from" class="rounded-md border border-gray-300 px-2 py-1 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                    <input type="date" wire:model="addon_effective_until" class="rounded-md border border-gray-300 px-2 py-1 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                </div>
                <button wire:click="assignAddon" class="mt-2 rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">Assign add-on</button>
            </div>
            @endcan

            @if ($detail->entitlement && $detail->entitlement->addons->isNotEmpty())
                <div class="mt-3">
                    <h3 class="mb-1 text-sm font-semibold">Assigned add-ons</h3>
                    <ul class="space-y-1 text-sm">
                        @foreach ($detail->entitlement->addons as $addon)
                            <li class="border-b border-indigo-100 py-1 dark:border-indigo-800">
                                <span class="font-medium">{{ $addon->addon_type }}</span>
                                @if ($addon->target_module_slug) <span class="font-mono">({{ $addon->target_module_slug }})</span> @endif
                                &middot; qty {{ $addon->quantity }} &middot; <x-hub.status-badge :status="$addon->status" />
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase text-gray-500 dark:border-gray-700 dark:bg-gray-900">
                <tr>
                    <th class="px-4 py-2">License key</th>
                    <th class="px-4 py-2">Tenant</th>
                    <th class="px-4 py-2">Tier</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Valid until</th>
                    <th class="px-4 py-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($licenses as $license)
                    <tr class="border-b border-gray-100 dark:border-gray-700">
                        <td class="px-4 py-2 font-mono">{{ $license->license_key }}</td>
                        <td class="px-4 py-2">{{ $license->tenant?->client_name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $license->entitlement?->tier?->name ?? '—' }}</td>
                        <td class="px-4 py-2"><x-hub.status-badge :status="$license->status" /></td>
                        <td class="px-4 py-2">{{ $license->valid_until?->format('Y-m-d') ?? '—' }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            <button wire:click="showDetail({{ $license->id }})" class="text-indigo-600 hover:underline">Detail</button>
                            @can('manageHub')
                                @if (in_array($license->status, ['active', 'unused']))
                                    <button wire:click="suspend({{ $license->id }})" wire:confirm="Suspend this license?" class="ml-2 text-yellow-600 hover:underline">Suspend</button>
                                @endif
                                @if ($license->status === 'suspended')
                                    <button wire:click="reactivate({{ $license->id }})" class="ml-2 text-green-600 hover:underline">Reactivate</button>
                                @endif
                                @if ($license->status !== 'revoked')
                                    <button wire:click="revoke({{ $license->id }})" wire:confirm="Revoke this license?" class="ml-2 text-red-600 hover:underline">Revoke</button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-4 text-center text-gray-500">No licenses found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $licenses->links() }}</div>
</div>
