@extends('layouts.app')
@section('title', $role->exists ? 'Edit Role' : 'New Role')

@section('content')
@php $selected = old('permissions', $role->exists ? $role->permissions->pluck('id')->all() : []); @endphp
<form method="POST" action="{{ $role->exists ? route('settings.roles.update', $role) : route('settings.roles.store') }}" class="card max-w-2xl space-y-4 p-6">
    @csrf
    @if ($role->exists) @method('PUT') @endif
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="label">Role name * (lowercase)</label>
            <input name="name" value="{{ old('name', $role->name) }}" class="input" required pattern="[a-z0-9_\-]+">
        </div>
        <div>
            <label class="label">Description</label>
            <input name="description" value="{{ old('description', $role->description) }}" class="input">
        </div>
    </div>
    <div>
        <div class="label">Permissions</div>
        <div class="space-y-2">
            @foreach ($permissions as $p)
                <label class="flex items-start gap-2 rounded-lg border border-slate-200 p-3 text-sm hover:bg-slate-50">
                    <input type="checkbox" name="permissions[]" value="{{ $p->id }}" class="mt-0.5" @checked(in_array($p->id, $selected))>
                    <span><code class="text-xs text-accent-700">{{ $p->name }}</code><br><span class="text-slate-600">{{ $p->description }}</span></span>
                </label>
            @endforeach
        </div>
    </div>
    <div class="flex gap-2">
        <button class="btn-primary">Save</button>
        <a href="{{ route('settings.roles.index') }}" class="btn-secondary">Cancel</a>
    </div>
</form>
@endsection
