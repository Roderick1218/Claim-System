@extends('layouts.app')
@section('title', 'Audit Logs')

@section('content')
<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div>
        <label class="label">Action</label>
        <select name="action" class="input">
            <option value="">All</option>
            @foreach ($actions as $a)<option @selected(request('action') === $a)>{{ $a }}</option>@endforeach
        </select>
    </div>
    <div>
        <label class="label">Entity type</label>
        <select name="entity_type" class="input">
            <option value="">All</option>
            @foreach ($entityTypes as $t)<option @selected(request('entity_type') === $t)>{{ $t }}</option>@endforeach
        </select>
    </div>
    <div>
        <label class="label">Entity ID</label>
        <input name="entity_id" value="{{ request('entity_id') }}" class="input w-28">
    </div>
    <button class="btn-primary">Filter</button>
    <a href="{{ route('settings.audit-logs.index') }}" class="btn-secondary">Reset</a>
</form>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Target</th><th>Changes</th><th>IP</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($logs as $log)
                <tr>
                    <td class="whitespace-nowrap">{{ $log->created_at?->format('d/m/Y H:i:s') }}</td>
                    <td>{{ $log->user?->name ?? 'System' }}</td>
                    <td><code class="text-xs">{{ $log->action }}</code></td>
                    <td class="whitespace-nowrap">{{ $log->entity_type }} #{{ $log->entity_id }}</td>
                    <td class="max-w-xl">
                        @if ($log->old_values || $log->new_values)
                            <details>
                                <summary class="cursor-pointer text-xs text-accent-600">{{ implode(', ', array_keys($log->new_values ?? $log->old_values ?? [])) }}</summary>
                                <div class="mt-2 grid gap-2 md:grid-cols-2">
                                    <pre class="overflow-auto rounded bg-rose-50 p-2 text-xs">{{ $log->old_values ? json_encode($log->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '—' }}</pre>
                                    <pre class="overflow-auto rounded bg-brand-50 p-2 text-xs">{{ $log->new_values ? json_encode($log->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '—' }}</pre>
                                </div>
                            </details>
                        @endif
                    </td>
                    <td class="text-xs text-slate-500">{{ $log->ip_address }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-6 text-center text-slate-500">No log entries.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($logs->hasPages())<div class="border-t border-slate-100 p-4">{{ $logs->links() }}</div>@endif
</div>
@endsection
