@extends('layouts.app')
@section('title', $claim->merchant_name ?: 'Claim '.$claim->claim_number)
@section('heading')
    <span class="inline-flex flex-wrap items-center gap-3">Claim Details @include('claims._status', ['status' => $claim->status])</span>
@endsection
@section('subtitle', $claim->claim_number.' · '.$claim->user->name.($claim->user->department ? ' · '.$claim->user->department->name : ''))
@section('actions')
    @if ($claim->receipts->isNotEmpty())
        <a href="{{ route('receipts.download', $claim->receipts->first()) }}" class="btn-secondary p-2.5" title="Download receipt"><x-icon name="download" class="h-5 w-5" /></a>
    @endif
    @can('update', $claim)
        <a href="{{ route('claims.edit', $claim) }}" class="btn-primary">
            <x-icon name="pencil" class="h-4 w-4" />
            <span class="hidden sm:inline">{{ $claim->isDraft() ? 'Continue editing' : ($claim->isRejected() ? 'Fix & resubmit' : 'Edit') }}</span>
        </a>
    @endcan
@endsection

@section('content')
@php
    $m = fn ($v) => number_format((float) $v, 2);
    $hasDiff = $claim->difference_amount !== null && (float) $claim->difference_amount !== 0.0;
    $cur = $claim->currency === 'MYR' ? 'RM' : $claim->currency;
@endphp

@php
    // Back 回到用户来的列表页，不回 edit 页（提交后已锁定）
    $prev = url()->previous();
    $listPages = [route('claims.index'), route('dashboard')];
    if (auth()->user()->hasPermission('claims.approve')) { $listPages[] = route('approvals.index'); }
    if (auth()->user()->hasPermission('reports.view')) { $listPages[] = route('reports.index'); }
    $backUrl = collect($listPages)->first(fn ($p) => $prev === $p || str_starts_with($prev, $p.'?')) ? $prev : route('claims.index');
@endphp
<a href="{{ $backUrl }}" class="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-slate-800">
    <x-icon name="arrow-left" class="h-4 w-4" /> Back
</a>

{{-- 概要：金额、状态、进度 --}}
<div class="card mb-5 p-5 sm:p-6">
    <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="text-sm text-slate-500">Total claimed</div>
            <div class="num text-3xl font-bold text-slate-900 sm:text-4xl">{{ $cur }} {{ $m($claim->total_amount) }}</div>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                @include('claims._status', ['status' => $claim->status])
                <span>{{ $claim->expense_date?->format('d M Y') ?? 'No date' }}</span>
                @if ($claim->category)<span>· {{ $claim->category->name }}{{ $claim->category->is_active ? '' : ' (inactive)' }}</span>@endif
            </div>
        </div>
        <div class="w-full sm:max-w-sm">@include('claims._progress')</div>
    </div>

    @if ($claim->isSubmitted())
        <div class="alert mt-5 mb-0 border-amber-200 bg-amber-50 text-amber-900">
            <x-icon name="clock" class="mt-0.5 h-5 w-5 text-amber-600" />
            <div>Submitted {{ $claim->submitted_at?->format('d/m/Y H:i') }} — waiting for approval.
                @if ($claim->user_id === auth()->id()) It is locked while being reviewed. @endif</div>
        </div>
    @elseif ($claim->isApproved())
        <div class="alert mt-5 mb-0 border-brand-200 bg-brand-50 text-brand-800">
            <x-icon name="check" class="mt-0.5 h-5 w-5 text-brand-600" />
            <div>Approved by <strong>{{ $claim->reviewer?->name ?? 'a deleted user' }}</strong> on {{ $claim->reviewed_at?->format('d/m/Y H:i') }}.
                @if ($claim->review_note) Remark: “{{ $claim->review_note }}” @endif</div>
        </div>
    @elseif ($claim->isRejected())
        <div class="alert mt-5 mb-0 border-rose-200 bg-rose-50 text-rose-800">
            <x-icon name="x" class="mt-0.5 h-5 w-5 text-rose-600" />
            <div>Rejected by <strong>{{ $claim->reviewer?->name ?? 'a deleted user' }}</strong> on {{ $claim->reviewed_at?->format('d/m/Y H:i') }}.
                <div class="mt-1">Reason: “{{ $claim->review_note }}”</div>
                @if ($claim->user_id === auth()->id())<div class="mt-1">Please fix the claim and submit it again.</div>@endif</div>
        </div>
    @endif
</div>

{{-- 审批区：批准 / 拒绝 --}}
@can('review', $claim)
    <div class="card mb-5 overflow-hidden ring-2 ring-brand-200">
        <div class="card-header bg-brand-50/60">
            <h2 class="card-title"><x-icon name="check-badge" class="h-5 w-5 text-brand-600" /> Review this claim</h2>
        </div>
        <div class="p-5">
            <p class="mb-4 text-sm text-slate-600">Compare the receipt with the details and amounts below. Total claimed: <strong class="num">{{ $cur }} {{ $m($claim->total_amount) }}</strong>
                @if ($hasDiff)
                    <span class="font-medium text-amber-700">— ⚠ differs from the receipt total by {{ $claim->difference_amount }}</span>
                @endif
            </p>
            <div class="grid gap-4 md:grid-cols-2">
                <form method="POST" action="{{ route('claims.approve', $claim) }}" class="space-y-2 rounded-xl border border-brand-200 bg-brand-50/40 p-4">
                    @csrf
                    <label class="label">Remark <span class="font-normal text-slate-400">(optional)</span></label>
                    <input name="review_note" class="input" maxlength="1000" placeholder="e.g. Approved for October payout">
                    <button class="btn-success btn-lg w-full" onclick="return confirm('Approve claim {{ $claim->claim_number }} for {{ $claim->currency }} {{ $m($claim->total_amount) }}?')"><x-icon name="check" class="h-5 w-5" /> Approve</button>
                </form>
                <form method="POST" action="{{ route('claims.reject', $claim) }}" class="space-y-2 rounded-xl border border-rose-200 bg-rose-50/40 p-4">
                    @csrf
                    <label class="label">Reason for rejecting <span class="font-normal text-rose-500">(required — staff will see this)</span></label>
                    <input name="review_note" class="input" maxlength="1000" required minlength="3" placeholder="e.g. Receipt date does not match, please re-upload">
                    <button class="btn-danger btn-lg w-full"><x-icon name="x" class="h-5 w-5" /> Reject</button>
                </form>
            </div>
        </div>
    </div>
@endcan

<div class="grid gap-5 lg:grid-cols-5">
    {{-- 收据预览 --}}
    <div class="min-w-0 lg:col-span-2">
        <div class="space-y-4 lg:sticky lg:top-24">
            <h2 class="card-title px-1"><x-icon name="receipt" class="h-5 w-5 text-slate-400" /> Receipt{{ $claim->receipts->count() > 1 ? 's' : '' }}</h2>
            @include('receipts._panel', ['editable' => auth()->user()->can('update', $claim), 'hints' => []])
            @if ($claim->receipts->isEmpty())
                <div class="card p-6 text-center text-sm text-slate-500">No receipt attached.</div>
            @endif
        </div>
    </div>

    <div class="min-w-0 space-y-5 lg:col-span-3">
        {{-- 基本资料 --}}
        <div class="card">
            <div class="card-header"><h2 class="card-title"><x-icon name="info" class="h-5 w-5 text-slate-400" /> Details</h2></div>
            <dl class="grid gap-x-6 gap-y-4 p-5 text-sm sm:grid-cols-2">
                @foreach ([
                    'Merchant' => $claim->merchant_name,
                    'Location' => $claim->location,
                    'Expense date' => $claim->expense_date?->format('d/m/Y'),
                    'Expense time' => $claim->expense_time ? substr($claim->expense_time, 0, 5) : null,
                    'Category' => $claim->category ? $claim->category->name.($claim->category->is_active ? '' : ' (inactive)') : null,
                    'Project' => $claim->project?->label,
                    'Business purpose' => $claim->business_purpose,
                    'Claim number' => $claim->claim_number,
                ] as $label => $value)
                    <div>
                        <dt class="text-xs font-medium text-slate-500">{{ $label }}</dt>
                        <dd class="mt-0.5 {{ $value ? 'text-slate-900' : 'text-slate-400' }}">{{ $value ?: 'Not filled' }}</dd>
                    </div>
                @endforeach
                @if ($claim->notes)
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-medium text-slate-500">Notes</dt>
                        <dd class="mt-0.5 whitespace-pre-line text-slate-900">{{ $claim->notes }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- 明细 + 合计 --}}
        <div class="card overflow-hidden">
            <div class="card-header"><h2 class="card-title"><x-icon name="receipt" class="h-5 w-5 text-slate-400" /> Items</h2></div>
            <div class="divide-y divide-slate-100">
                @forelse ($claim->items as $item)
                    <div class="flex items-start justify-between gap-4 px-5 py-3">
                        <div class="min-w-0">
                            <div class="font-medium text-slate-900">{{ $item->item_name ?: '—' }}</div>
                            <div class="num text-xs text-slate-500">
                                {{ rtrim(rtrim((string) $item->quantity, '0'), '.') }}{{ $item->unit ? ' '.$item->unit : '' }}
                                × {{ $item->unit_price !== null ? $m($item->unit_price) : 'total only' }}
                                @if ((float) $item->discount_amount) · discount {{ $m($item->discount_amount) }} @endif
                                @if ((float) $item->tax_amount) · tax {{ $m($item->tax_amount) }} @endif
                                @if ($item->receipt && $claim->receipts->count() > 1) · receipt {{ $claim->receipts->search(fn ($r) => $r->id === $item->receipt_id) + 1 }} @endif
                            </div>
                        </div>
                        <div class="num shrink-0 font-semibold {{ (float) $item->line_total < 0 ? 'text-rose-600' : 'text-slate-900' }}">{{ $m($item->line_total) }}</div>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-slate-500">No items yet.</div>
                @endforelse
            </div>
            <dl class="num space-y-1.5 border-t border-slate-200 bg-slate-50/60 px-5 py-4 text-sm">
                <div class="flex justify-between text-slate-600"><dt>Subtotal</dt><dd>{{ $m($claim->subtotal_amount) }}</dd></div>
                <div class="flex justify-between text-slate-600"><dt>Discount</dt><dd>{{ (float) $claim->discount_amount ? '-'.$m($claim->discount_amount) : '0.00' }}</dd></div>
                <div class="flex justify-between text-slate-600"><dt>Tax</dt><dd>{{ $m($claim->tax_amount) }}</dd></div>
                <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-bold text-slate-900"><dt>Total ({{ $cur }})</dt><dd>{{ $m($claim->total_amount) }}</dd></div>
                @if ($claim->receipt_total_amount !== null)
                    <div class="flex justify-between pt-1 text-slate-500"><dt>Total printed on receipt</dt><dd>{{ $m($claim->receipt_total_amount) }}</dd></div>
                @endif
            </dl>
            @if ($hasDiff)
                <div class="mx-5 mb-5 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">⚠ Items total differs from the receipt total by {{ $claim->difference_amount }}.</div>
            @endif
        </div>

        <p class="px-1 text-xs text-slate-400">
            Created {{ $claim->created_at->format('d/m/Y H:i') }} by {{ $claim->user->name }}
            · Last updated {{ $claim->updated_at->format('d/m/Y H:i') }}{{ $claim->updatedBy ? ' by '.$claim->updatedBy->name : '' }}
        </p>

        @can('delete', $claim)
            <form method="POST" action="{{ route('claims.destroy', $claim) }}" onsubmit="return confirm('Delete claim {{ $claim->claim_number }}? It will be hidden from lists but kept in the audit history.')">
                @csrf @method('DELETE')
                <button class="btn-ghost px-2 text-sm text-rose-600 hover:bg-rose-50"><x-icon name="trash" class="h-4 w-4" /> Delete this claim</button>
            </form>
        @endcan
    </div>
</div>
@endsection
