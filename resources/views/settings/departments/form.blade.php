@extends('layouts.app')
@section('title', $department->exists ? 'Edit Department' : 'New Department')

@section('content')
<form method="POST" action="{{ $department->exists ? route('settings.departments.update', $department) : route('settings.departments.store') }}" class="card max-w-xl space-y-4 p-6">
    @csrf
    @if ($department->exists) @method('PUT') @endif
    <div>
        <label class="label">Name *</label>
        <input name="name" value="{{ old('name', $department->name) }}" class="input" required>
    </div>
    <div>
        <label class="label">Description</label>
        <textarea name="description" rows="3" class="input">{{ old('description', $department->description) }}</textarea>
    </div>
    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $department->is_active))> Active
    </label>
    <div class="flex gap-2">
        <button class="btn-primary">Save</button>
        <a href="{{ route('settings.departments.index') }}" class="btn-secondary">Cancel</a>
    </div>
</form>
@endsection
