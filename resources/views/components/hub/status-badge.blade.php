@props(['status'])
@php
    $colors = [
        'active' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
        'suspended' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
        'terminated' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        'unused' => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
        'expired' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        'revoked' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        'delivered' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
        'failed' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        'pending' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
    ];
    $color = $colors[$status] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300';
@endphp
<span class="inline-block rounded px-2 py-0.5 text-xs font-medium uppercase {{ $color }}">{{ $status }}</span>
