@extends('layouts.app')
@section('title', 'Departments')

@section('content')
<div class="mb-4 flex justify-end"><a href="{{ route('settings.departments.create') }}" class="btn-primary">+ New department</a></div>
<div class="card overflow-hidden">
    <table class="table">
        <thead><tr><th>Name</th><th>Description</th><th>Users</th><th>Status</th><th></th></tr></thead>
        <tbody class="divide-y divide-slate-100">
        @foreach ($departments as $d)
            <tr class="hover:bg-slate-50">
                <td class="font-medium">{{ $d->name }}</td>
                <td>{{ $d->description }}</td>
                <td>{{ $d->users_count }}</td>
                <td>@include('settings._active', ['active' => $d->is_active])</td>
                <td class="text-right"><a href="{{ route('settings.departments.edit', $d) }}" class="text-accent-600 hover:underline">Edit</a></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
