@extends('layouts.app')
@section('title', 'Projects')

@section('content')
<div class="mb-4 flex justify-end"><a href="{{ route('settings.projects.create') }}" class="btn-primary">+ New project</a></div>
<div class="card overflow-hidden">
    <table class="table">
        <thead><tr><th>Code</th><th>Name</th><th>Customer</th><th>Claims</th><th>Status</th><th></th></tr></thead>
        <tbody class="divide-y divide-slate-100">
        @forelse ($projects as $p)
            <tr class="hover:bg-slate-50">
                <td>{{ $p->project_code ?? '—' }}</td>
                <td class="font-medium">{{ $p->name }}</td>
                <td>{{ $p->customer_name }}</td>
                <td>{{ $p->claims_count }}</td>
                <td>@include('settings._active', ['active' => $p->is_active])</td>
                <td class="text-right"><a href="{{ route('settings.projects.edit', $p) }}" class="text-accent-600 hover:underline">Edit</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="py-6 text-center text-slate-500">No projects yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
