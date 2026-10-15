@extends('layouts.app')
@section('title', $user->exists ? 'Edit User' : 'New User')

@section('content')
@php $userRoleIds = old('roles', $user->exists ? $user->roles->pluck('id')->all() : []); @endphp
<form method="POST" action="{{ $user->exists ? route('settings.users.update', $user) : route('settings.users.store') }}" class="card max-w-2xl space-y-4 p-6">
    @csrf
    @if ($user->exists) @method('PUT') @endif

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="label">Name *</label>
            <input name="name" value="{{ old('name', $user->name) }}" class="input" required>
        </div>
        <div>
            <label class="label">Employee code</label>
            <input name="employee_code" value="{{ old('employee_code', $user->employee_code) }}" class="input">
        </div>
        <div>
            <label class="label">Email (login) *</label>
            <input type="email" name="email" value="{{ old('email', $user->email) }}" class="input" required>
        </div>
        <div>
            <label class="label">Department</label>
            <select name="department_id" class="input">
                <option value="">— None —</option>
                @foreach ($departments as $d)
                    <option value="{{ $d->id }}" @selected(old('department_id', $user->department_id) == $d->id)>{{ $d->name }}{{ $d->is_active ? '' : ' (inactive)' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label">{{ $user->exists ? 'New password (leave blank to keep)' : 'Password *' }}</label>
            <input type="password" name="password" class="input" autocomplete="new-password" {{ $user->exists ? '' : 'required' }}>
        </div>
        <div>
            <label class="label">Confirm password</label>
            <input type="password" name="password_confirmation" class="input" autocomplete="new-password">
        </div>
    </div>

    <div>
        <div class="label">Roles</div>
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach ($roles as $role)
                <label class="flex items-start gap-2 rounded-lg border border-slate-200 p-3 text-sm hover:bg-slate-50">
                    <input type="checkbox" name="roles[]" value="{{ $role->id }}" class="mt-0.5" @checked(in_array($role->id, $userRoleIds))>
                    <span><span class="font-medium">{{ ucfirst($role->name) }}</span><br><span class="text-xs text-slate-500">{{ $role->description }}</span></span>
                </label>
            @endforeach
        </div>
    </div>

    {{-- 没勾选时 checkbox 不会提交，用 hidden 的 0 垫底 --}}
    <label class="flex items-center gap-2 text-sm">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active))> Active (can log in)
    </label>

    <div class="flex gap-2">
        <button class="btn-primary">Save</button>
        <a href="{{ route('settings.users.index') }}" class="btn-secondary">Cancel</a>
    </div>
</form>
@endsection
