<div>
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">Audit Log</h1>
        <select wire:model.live="eventFilter" class="rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            <option value="">All events</option>
            @foreach ($events as $e)
                <option value="{{ $e }}">{{ $e }}</option>
            @endforeach
        </select>
    </div>

    @if ($detail)
        <div class="mb-4 rounded-lg border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-700 dark:bg-indigo-900/20">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="text-lg font-semibold">{{ $detail->event_type }}</h2>
                <button wire:click="$set('detailId', null)" class="text-sm text-gray-500 hover:underline">Close</button>
            </div>
            <p class="text-sm">Tenant: {{ $detail->tenant?->client_name ?? '—' }}</p>
            <p class="text-sm">Actor: {{ $detail->actor_type ? class_basename($detail->actor_type).' #'.$detail->actor_id : 'system' }}</p>
            <p class="text-sm">IP: {{ $detail->ip_address ?? '—' }}</p>
            <p class="text-sm">Time: {{ $detail->created_at->format('Y-m-d H:i:s') }}</p>
            <div class="mt-2">
                <h3 class="text-sm font-semibold">Details</h3>
                <pre class="max-h-64 overflow-auto rounded bg-gray-900 p-2 text-xs text-gray-100">{{ json_encode($detail->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        </div>
    @endif

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase text-gray-500 dark:border-gray-700 dark:bg-gray-900">
                <tr>
                    <th class="px-4 py-2">Event</th>
                    <th class="px-4 py-2">Tenant</th>
                    <th class="px-4 py-2">Actor</th>
                    <th class="px-4 py-2">Time</th>
                    <th class="px-4 py-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr class="border-b border-gray-100 dark:border-gray-700">
                        <td class="px-4 py-2">{{ $log->event_type }}</td>
                        <td class="px-4 py-2">{{ $log->tenant?->client_name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $log->actor_type ? class_basename($log->actor_type).' #'.$log->actor_id : 'system' }}</td>
                        <td class="px-4 py-2">{{ $log->created_at->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-2">
                            <button wire:click="showDetail({{ $log->id }})" class="text-indigo-600 hover:underline">Detail</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-4 text-center text-gray-500">No audit records found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $logs->links() }}</div>
</div>
