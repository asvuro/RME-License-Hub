@props(['label', 'value'])
<div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
    <div class="text-2xl font-bold text-indigo-600 dark:text-indigo-400">{{ $value }}</div>
    <div class="mt-1 text-xs uppercase tracking-wide text-gray-500">{{ $label }}</div>
</div>
