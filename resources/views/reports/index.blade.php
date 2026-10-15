@extends('layouts.app')
@section('title', 'Reports')

@section('content')
{{-- 筛选栏，多一个 Export CSV 按钮 --}}
@include('claims._filters', [
    'action' => route('reports.index'),
    'extraButtons' => new \Illuminate\Support\HtmlString('<a href="'.e(route('reports.export', $filters)).'" class="btn-success">⬇ Export CSV</a>'),
])

{{-- 汇总：总数、按类别、按员工 --}}
<div class="mb-6 grid gap-4 md:grid-cols-3">
    <div class="card p-5">
        <div class="text-xs font-medium uppercase text-slate-500">Matching claims</div>
        <div class="mt-2 text-2xl font-semibold">{{ $summary->count ?? 0 }}</div>
        <div class="mt-1 text-sm text-slate-500">Total RM {{ number_format((float) ($summary->total ?? 0), 2) }}</div>
    </div>
    <div class="card p-5">
        <div class="mb-2 text-xs font-medium uppercase text-slate-500">By category</div>
        @forelse ($byCategory as $r)
            <div class="flex justify-between text-sm"><span>{{ $r->label }} <span class="text-slate-400">({{ $r->count }})</span></span><span>{{ number_format((float) $r->total, 2) }}</span></div>
        @empty
            <p class="text-sm text-slate-500">—</p>
        @endforelse
    </div>
    <div class="card p-5">
        <div class="mb-2 text-xs font-medium uppercase text-slate-500">By employee</div>
        @forelse ($byUser as $r)
            <div class="flex justify-between text-sm"><span>{{ $r->label }} <span class="text-slate-400">({{ $r->count }})</span></span><span>{{ number_format((float) $r->total, 2) }}</span></div>
        @empty
            <p class="text-sm text-slate-500">—</p>
        @endforelse
    </div>
</div>

@include('claims._table')
@endsection
