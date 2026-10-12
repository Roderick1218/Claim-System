@extends('layouts.app')
@section('title', 'Claim Categories')

@section('content')
<div class="mb-4 flex justify-end"><a href="{{ route('settings.categories.create') }}" class="btn-primary">+ New category</a></div>
<div class="card overflow-hidden">
    <table class="table">
        <thead><tr><th>Name</th><th>Description</th><th>Auto-select keywords</th><th>Receipt required</th><th>Claims</th><th>Status</th><th></th></tr></thead>
        <tbody class="divide-y divide-slate-100">
        @foreach ($categories as $c)
            <tr class="hover:bg-slate-50">
                <td class="font-medium">{{ $c->name }}</td>
                <td>{{ $c->description }}</td>
                <td>{{ count(\App\Services\CategoryGuesser::keywordsOf($c)) ?: '—' }}</td>
                <td>{{ $c->requires_receipt ? 'Yes' : 'No' }}</td>
                <td>{{ $c->claims_count }}</td>
                <td>@include('settings._active', ['active' => $c->is_active])</td>
                <td class="text-right"><a href="{{ route('settings.categories.edit', $c) }}" class="text-accent-600 hover:underline">Edit</a></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
<p class="mt-3 text-xs text-slate-500">Deactivated categories can no longer be chosen for new claims, but old claims still show them.</p>
@endsection
