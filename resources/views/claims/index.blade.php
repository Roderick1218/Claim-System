@extends('layouts.app')
@php $seesOthers = auth()->user()->hasAnyPermission(['claims.view_all', 'claims.view_department']); @endphp
@section('title', $seesOthers ? 'Claims' : 'My Claims')
@section('subtitle', $seesOthers ? 'Your claims and the claims you are allowed to see.' : 'Track your claim status and history.')
@section('actions')
    <a href="{{ route('claims.create') }}" class="btn-primary"><x-icon name="plus" class="h-5 w-5" /> <span class="hidden sm:inline">New Claim</span></a>
@endsection

@section('content')
@php
    $current = $filters['status'] ?? '';
    $tabs = ['' => 'All'] + \App\Models\Claim::STATUSES;
    $tabs['submitted'] = 'Pending';
    $base = array_diff_key($filters, ['status' => 1, 'page' => 1]);
@endphp

<div class="card">
    {{-- Status tabs --}}
    <div class="overflow-x-auto border-b border-slate-100 px-4 pt-4 sm:px-5">
        <div class="flex gap-6">
            @foreach ($tabs as $key => $label)
                @php $n = $key === '' ? $statusCounts->sum() : ($statusCounts[$key] ?? 0); @endphp
                <a href="{{ route('claims.index', $base + ($key !== '' ? ['status' => $key] : [])) }}" class="tab {{ $current === $key ? 'active' : '' }}">
                    {{ $label }} <span class="num rounded-full px-1.5 text-xs {{ $current === $key ? 'bg-brand-50 text-brand-700' : 'bg-slate-100 text-slate-500' }}">{{ $n }}</span>
                </a>
            @endforeach
        </div>
    </div>

    @include('claims._filters', ['action' => route('claims.index'), 'bare' => true])
    @include('claims._table', ['bare' => true])
</div>
@endsection
