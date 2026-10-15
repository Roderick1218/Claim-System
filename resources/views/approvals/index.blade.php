@extends('layouts.app')
@section('title', 'Approvals')
@section('subtitle', 'Review and approve claims. You cannot approve your own claims.')

@section('content')
<div class="card">
    {{-- Pending / Approved / Rejected tab --}}
    <div class="overflow-x-auto border-b border-slate-100 px-4 pt-4 sm:px-5">
        <div class="flex gap-6">
            @foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $key => $label)
                <a href="{{ route('approvals.index', $key === 'pending' ? [] : ['tab' => $key]) }}" class="tab {{ $tab === $key ? 'active' : '' }}">
                    {{ $label }} <span class="num rounded-full px-1.5 text-xs {{ $tab === $key ? 'bg-brand-50 text-brand-700' : 'bg-slate-100 text-slate-500' }}">{{ $counts[$key] }}</span>
                </a>
            @endforeach
        </div>
    </div>

    @if ($pending->isEmpty())
        <div class="px-6 py-16 text-center">
            <div class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-brand-50 text-brand-600"><x-icon name="check-badge" class="h-8 w-8" /></div>
            @if ($tab === 'pending')
                <p class="mt-4 text-lg font-semibold text-slate-900">All caught up</p>
                <p class="text-sm text-slate-500">Nothing waiting for approval. 🎉</p>
            @else
                <p class="mt-4 text-lg font-semibold text-slate-900">No {{ $tab }} claims yet</p>
            @endif
        </div>
    @else
        {{-- claim 列表，左边是第一张收据的缩略图 --}}
        <div class="divide-y divide-slate-100">
            @foreach ($pending as $c)
                @php $first = $c->receipts->first(); @endphp
                <a href="{{ route('claims.show', $c) }}" class="flex items-center gap-4 px-4 py-4 transition hover:bg-slate-50 sm:px-5">
                    <div class="h-20 w-16 shrink-0 overflow-hidden rounded-lg bg-slate-100 ring-1 ring-slate-200">
                        @if ($first && $first->isImage())
                            <img src="{{ route('receipts.preview', $first) }}" alt="" class="h-full w-full object-cover" loading="lazy">
                        @else
                            <div class="grid h-full place-items-center text-slate-400"><x-icon name="receipt" class="h-7 w-7" /></div>
                        @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-xs font-semibold text-slate-500">{{ $c->claim_number }}</div>
                        <div class="truncate font-semibold text-slate-900">{{ $c->merchant_name ?: 'No merchant' }}</div>
                        <div class="num text-sm font-semibold text-slate-700">{{ $c->currency === 'MYR' ? 'RM' : $c->currency }} {{ number_format((float) $c->total_amount, 2) }}
                            <span class="font-normal text-slate-400">· {{ $c->category?->name ?? 'No category' }}</span></div>
                        <div class="mt-0.5 truncate text-xs text-slate-400">
                            Submitted by {{ $c->user?->name }}{{ $c->user?->department ? ' · '.$c->user->department->name : '' }} · <span title="{{ $c->submitted_at?->format('d/m/Y H:i') }}">{{ $c->submitted_at?->diffForHumans() }}</span>
                            @if ($tab !== 'pending' && $c->reviewer) · {{ $tab === 'approved' ? 'approved' : 'rejected' }} by {{ $c->reviewer->name }} @endif
                        </div>
                    </div>
                    @if ($tab === 'pending')
                        <span class="btn-primary shrink-0 px-3 py-2 sm:px-5">Review</span>
                    @else
                        <span class="shrink-0">@include('claims._status', ['status' => $c->status])</span>
                    @endif
                </a>
            @endforeach
        </div>
    @endif

    @if ($pending->hasPages())<div class="border-t border-slate-100 px-4 py-3">{{ $pending->links() }}</div>@endif
</div>
@endsection
