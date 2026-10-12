@extends('layouts.app')
@section('title', $category->exists ? 'Edit Category' : 'New Category')

@section('content')
<form method="POST" action="{{ $category->exists ? route('settings.categories.update', $category) : route('settings.categories.store') }}" class="card max-w-xl space-y-4 p-6">
    @csrf
    @if ($category->exists) @method('PUT') @endif
    <div>
        <label class="label">Name *</label>
        <input name="name" value="{{ old('name', $category->name) }}" class="input" required>
    </div>
    <div>
        <label class="label">Description</label>
        <textarea name="description" rows="3" class="input">{{ old('description', $category->description) }}</textarea>
    </div>
    <div>
        <label class="label">Receipt keywords (one per line) — used to auto-select this category</label>
        <textarea name="keywords" rows="8" class="input font-mono text-xs" placeholder="e.g.&#10;petronas&#10;shell&#10;ron95">{{ old('keywords', $category->keywords) }}</textarea>
        <p class="mt-1 text-xs text-slate-500">Shop names, brands or words printed on the receipt (English or Malay). A word in the shop name counts more than one elsewhere on the receipt. If a merchant was claimed before, its last category is preferred.</p>
    </div>
    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="requires_receipt" value="1" @checked(old('requires_receipt', $category->requires_receipt))> Receipt required before a claim can be submitted
    </label>
    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $category->is_active))> Active
    </label>
    <div class="flex gap-2">
        <button class="btn-primary">Save</button>
        <a href="{{ route('settings.categories.index') }}" class="btn-secondary">Cancel</a>
    </div>
</form>
@endsection
