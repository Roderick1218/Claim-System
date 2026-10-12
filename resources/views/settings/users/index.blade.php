@extends('layouts.app')
@section('title', 'Users')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <form method="GET" class="flex gap-2">
        <input name="q" value="{{ request('q') }}" class="input w-64" placeholder="Search name, email, code">
        <button class="btn-secondary">Search</button>
    </form>
    <a href="{{ route('settings.users.create') }}" class="btn-primary">+ New user</a>
</div>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr><th>Name</th><th>Code</th><th>Email</th><th>Department</th><th>Roles</th><th>Status</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @foreach ($users as $u)
                <tr class="hover:bg-slate-50">
                    <td class="font-medium">{{ $u->name }}</td>
                    <td>{{ $u->employee_code }}</td>
                    <td>{{ $u->email }}</td>
                    <td>{{ $u->department?->name ?? '—' }}</td>
                    <td>{{ $u->roles->pluck('name')->map(fn ($r) => ucfirst($r))->implode(', ') ?: '—' }}</td>
                    <td>@include('settings._active', ['active' => $u->is_active])</td>
                    <td class="text-right"><a href="{{ route('settings.users.edit', $u) }}" class="text-accent-600 hover:underline">Edit</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @if ($users->hasPages())<div class="border-t border-slate-100 p-4">{{ $users->links() }}</div>@endif
</div>
<p class="mt-3 text-xs text-slate-500">Users are never deleted, so their claim history is kept. Deactivate a user to block login.</p>
@endsection
