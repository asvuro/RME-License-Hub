<div>
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">Webhook Deliveries</h1>
        <div>
            <select wire:model.live="statusFilter" class="rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <option value="">All</option>
                <option value="delivered">Delivered</option>
                <option value="failed">Pending / Failed</option>
            </select>
        </div>
    </div>

    @if ($detail)
        <div class="mb-4 rounded-lg border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-700 dark:bg-indigo-900/20">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="text-lg font-semibold">{{ $detail->event_type }}</h2>
                <button wire:click="$set('detailId', null)" class="text-sm text-gray-500 hover:underline">Close</button>
            </div>
            <p class="text-sm">Tenant: {{ $detail->tenant?->client_name ?? '—' }}</p>
            <p class="text-sm">Event ID: <span class="font-mono">{{ $detail->event_id }}</span></p>
            <p class="text-sm">URL: <span class="font-mono">{{ $detail->url }}</span></p>
            <p class="text-sm">Attempts: {{ $detail->attempts }}/{{ $detail->max_attempts }} &middot; Status: @if ($detail->isDelivered()) <x-hub.status-badge status="delivered" /> @else <x-hub.status-badge status="pending" /> @endif</p>
            @if ($detail->last_response_code)
                <p class="text-sm">Last response: {{ $detail->last_response_code }}</p>
            @endif
            <div class="mt-2">
                <h3 class="text-sm font-semibold">Payload</h3>
                <pre class="max-h-64 overflow-auto rounded bg-gray-900 p-2 text-xs text-gray-100">{{ json_encode($detail->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            @if ($detail->last_response_body)
                <div class="mt-2">
                    <h3 class="text-sm font-semibold">Last response body</h3>
                    <pre class="max-h-40 overflow-auto rounded bg-gray-900 p-2 text-xs text-gray-100">{{ $detail->last_response_body }}</pre>
                </div>
            @endif
            @can('manageHub')
                @if ($detail->canRetry())
                    <button wire:click="retry({{ $detail->id }})" wire:confirm="Queue this webhook for retry?" class="mt-2 rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">Retry</button>
                @endif
            @endcan
        </div>
    @endif

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase text-gray-500 dark:border-gray-700 dark:bg-gray-900">
                <tr>
                    <th class="px-4 py-2">Event</th>
                    <th class="px-4 py-2">Tenant</th>
                    <th class="px-4 py-2">Attempts</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Created</th>
                    <th class="px-4 py-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($webhooks as $wh)
                    <tr class="border-b border-gray-100 dark:border-gray-700">
                        <td class="px-4 py-2">{{ $wh->event_type }}</td>
                        <td class="px-4 py-2">{{ $wh->tenant?->client_name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $wh->attempts }}/{{ $wh->max_attempts }}</td>
                        <td class="px-4 py-2">@if ($wh->isDelivered()) <x-hub.status-badge status="delivered" /> @else <x-hub.status-badge status="pending" /> @endif</td>
                        <td class="px-4 py-2">{{ $wh->created_at->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-2">
                            <button wire:click="showDetail({{ $wh->id }})" class="text-indigo-600 hover:underline">Detail</button>
                            @can('manageHub')
                                @if ($wh->canRetry())
                                    <button wire:click="retry({{ $wh->id }})" wire:confirm="Queue this webhook for retry?" class="ml-2 text-indigo-600 hover:underline">Retry</button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-4 text-center text-gray-500">No webhook deliveries found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $webhooks->links() }}</div>
</div>
