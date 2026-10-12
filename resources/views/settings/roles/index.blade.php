@extends('layouts.app')
@section('title', 'Roles & Permissions')

@section('content')
<div class="mb-4 flex justify-end"><a href="{{ route('settings.roles.create') }}" class="btn-primary">+ New role</a></div>
<div class="card overflow-hidden">
    <table class="table">
        <thead><tr><th>Role</th><th>Description</th><th>Users</th><th>Permissions</th><th></th></tr></thead>
        <tbody class="divide-y divide-slate-100">
        @foreach ($roles as $r)
            <tr class="hover:bg-slate-50">
                <td class="font-medium">{{ ucfirst($r->name) }}</td>
                <td>{{ $r->description }}</td>
                <td>{{ $r->users_count }}</td>
                <td>
                    <div class="flex flex-wrap gap-1">
                        @forelse ($r->permissions as $p)
                            <span class="badge bg-accent-50 text-accent-700">{{ $p->name }}</span>
                        @empty
                            <span class="text-xs text-slate-400">Own claims only</span>
                        @endforelse
                    </div>
                </td>
                <td class="text-right"><a href="{{ route('settings.roles.edit', $r) }}" class="text-accent-600 hover:underline">Edit</a></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
<p class="mt-3 text-xs text-slate-500">Every user can always create, view and edit their own claims. Permissions only grant access beyond that. A user with several roles gets all their permissions combined.</p>
@endsection
