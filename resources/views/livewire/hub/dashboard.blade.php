<div>
    <h1 class="mb-4 text-2xl font-bold">Dashboard</h1>

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        <x-hub.stat label="Tenants" :value="$stats['tenants']" />
        <x-hub.stat label="Active tenants" :value="$stats['tenants_active']" />
        <x-hub.stat label="Suspended tenants" :value="$stats['tenants_suspended']" />
        <x-hub.stat label="Groups" :value="$stats['groups']" />
        <x-hub.stat label="Active tiers" :value="$stats['tiers']" />
        <x-hub.stat label="Active add-ons" :value="$stats['addons']" />
        <x-hub.stat label="Licenses (active)" :value="$stats['licenses_active']" />
        <x-hub.stat label="Licenses (suspended)" :value="$stats['licenses_suspended']" />
        <x-hub.stat label="Licenses (revoked)" :value="$stats['licenses_revoked']" />
        <x-hub.stat label="Webhooks pending" :value="$stats['webhooks_pending']" />
        <x-hub.stat label="Webhooks failed" :value="$stats['webhooks_failed']" />
        <x-hub.stat label="Tenants offline" :value="$stats['tenants_offline']" />
    </div>

    @if ($offline_tenants->isNotEmpty())
        <section class="mt-8 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-900/20">
            <h2 class="mb-3 text-lg font-semibold text-amber-800 dark:text-amber-300">⚠ Tenant offline (belum heartbeat &gt; {{ config('license.max_offline_days', 14) }} hari)</h2>
            <ul class="space-y-2 text-sm">
                @foreach ($offline_tenants as $tenant)
                    <li class="border-b border-amber-100 pb-2 dark:border-amber-800">
                        <span class="font-medium">{{ $tenant->client_name }}</span>
                        <span class="text-gray-500"> ({{ $tenant->client_code }})</span>
                        <span class="text-gray-500"> &middot; terakhir terlihat: {{ $tenant->last_heartbeat_at?->diffForHumans() ?? 'belum pernah' }}</span>
                    </li>
                @endforeach
            </ul>
            <a href="{{ route('hub.tenants.index') }}" class="mt-2 inline-block text-sm text-indigo-600 hover:underline">Lihat semua tenant &rarr;</a>
        </section>
    @endif

    <div class="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <h2 class="mb-3 text-lg font-semibold">Recent activity (audit)</h2>
            @if ($recent_audit->isEmpty())
                <p class="text-sm text-gray-500">No audit records yet.</p>
            @else
                <ul class="space-y-2 text-sm">
                    @foreach ($recent_audit as $log)
                        <li class="border-b border-gray-100 pb-2 dark:border-gray-700">
                            <span class="font-medium">{{ $log->event_type }}</span>
                            <span class="text-gray-500"> &middot; {{ $log->created_at->format('Y-m-d H:i') }}</span>
                            @if ($log->tenant)
                                <span class="text-gray-500"> &middot; {{ $log->tenant->client_name }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('hub.audit.index') }}" class="mt-2 inline-block text-sm text-indigo-600 hover:underline">View all &rarr;</a>
            @endif
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <h2 class="mb-3 text-lg font-semibold">Recent webhook deliveries</h2>
            @if ($recent_webhooks->isEmpty())
                <p class="text-sm text-gray-500">No webhook deliveries yet.</p>
            @else
                <ul class="space-y-2 text-sm">
                    @foreach ($recent_webhooks as $wh)
                        <li class="border-b border-gray-100 pb-2 dark:border-gray-700">
                            <span class="font-medium">{{ $wh->event_type }}</span>
                            <span class="text-gray-500"> &middot; {{ $wh->tenant?->client_name ?? '—' }} &middot; {{ $wh->isDelivered() ? 'delivered' : 'pending' }}</span>
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('hub.webhooks.index') }}" class="mt-2 inline-block text-sm text-indigo-600 hover:underline">View all &rarr;</a>
            @endif
        </section>
    </div>
</div>
