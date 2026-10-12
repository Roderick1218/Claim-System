@extends('layouts.app')
@section('title', $project->exists ? 'Edit Project' : 'New Project')

@section('content')
<form method="POST" action="{{ $project->exists ? route('settings.projects.update', $project) : route('settings.projects.store') }}" class="card max-w-xl space-y-4 p-6">
    @csrf
    @if ($project->exists) @method('PUT') @endif
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="label">Project code</label>
            <input name="project_code" value="{{ old('project_code', $project->project_code) }}" class="input">
        </div>
        <div>
            <label class="label">Name *</label>
            <input name="name" value="{{ old('name', $project->name) }}" class="input" required>
        </div>
        <div class="sm:col-span-2">
            <label class="label">Customer name</label>
            <input name="customer_name" value="{{ old('customer_name', $project->customer_name) }}" class="input">
        </div>
        <div class="sm:col-span-2">
            <label class="label">Description</label>
            <textarea name="description" rows="3" class="input">{{ old('description', $project->description) }}</textarea>
        </div>
    </div>
    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $project->is_active))> Active
    </label>
    <div class="flex gap-2">
        <button class="btn-primary">Save</button>
        <a href="{{ route('settings.projects.index') }}" class="btn-secondary">Cancel</a>
    </div>
</form>
@endsection
