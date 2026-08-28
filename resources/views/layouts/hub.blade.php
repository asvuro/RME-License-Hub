<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ?? 'RME License Hub' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-gray-100 text-gray-900 dark:bg-gray-900 dark:text-gray-100">
    <div class="min-h-full">
        <nav class="border-b border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3">
                <a href="{{ route('hub.dashboard') }}" class="text-lg font-semibold text-indigo-600 dark:text-indigo-400">RME License Hub</a>
                <div class="flex items-center gap-4 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">
                        {{ auth('hub')->user()->name }}
                        <span class="ml-1 rounded bg-gray-200 px-1.5 py-0.5 text-xs uppercase dark:bg-gray-700">{{ auth('hub')->user()->role }}</span>
                    </span>
                    <form method="POST" action="{{ route('hub.logout') }}">
                        @csrf
                        <button type="submit" class="text-gray-500 hover:text-gray-900 dark:hover:text-gray-100">Log out</button>
                    </form>
                </div>
            </div>
            <div class="mx-auto max-w-7xl px-4">
                <div class="flex flex-wrap gap-1 pb-2 text-sm">
                    <x-hub.nav-link route="hub.dashboard" label="Dashboard" />
                    <x-hub.nav-link route="hub.tenants.index" label="Tenants" />
                    <x-hub.nav-link route="hub.tiers.index" label="Tiers" />
                    <x-hub.nav-link route="hub.addons.index" label="Add-ons" />
                    <x-hub.nav-link route="hub.groups.index" label="Groups" />
                    <x-hub.nav-link route="hub.licenses.index" label="Licenses" />
                    <x-hub.nav-link route="hub.webhooks.index" label="Webhooks" />
                    <x-hub.nav-link route="hub.audit.index" label="Audit" />
                    @can('administerHub')
                        <x-hub.nav-link route="hub.admins.index" label="Admins" />
                    @endcan
                </div>
            </div>
        </nav>

        <main class="mx-auto max-w-7xl px-4 py-6">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 px-3 py-2 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                    {{ session('status') }}
                </div>
            @endif
            @if (session('error'))
                <div class="mb-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">
                    {{ session('error') }}
                </div>
            @endif

            {{ $slot }}
        </main>
    </div>
    @livewireScripts
</body>
</html>
