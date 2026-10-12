@php
    $current = request('sort');
    $dir = request('dir', 'desc');
    $next = $current === $column && $dir === 'asc' ? 'desc' : 'asc';
@endphp
<a href="{{ request()->fullUrlWithQuery(['sort' => $column, 'dir' => $next, 'page' => null]) }}" class="inline-flex items-center gap-1 hover:text-slate-800">
    {{ $label }}@if ($current === $column)<span>{{ $dir === 'asc' ? '▲' : '▼' }}</span>@endif
</a>
