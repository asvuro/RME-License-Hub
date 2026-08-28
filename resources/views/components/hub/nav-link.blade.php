@props(['route', 'label'])
<a href="{{ route($route) }}"
   @class([
       'rounded px-3 py-1.5',
       'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300' => request()->routeIs($route) || (str_contains($route, '.index') && request()->routeIs(str_replace('.index', '.*', $route))),
       'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => !request()->routeIs($route) && !(str_contains($route, '.index') && request()->routeIs(str_replace('.index', '.*', $route))),
   ])>
    {{ $label }}
</a>
