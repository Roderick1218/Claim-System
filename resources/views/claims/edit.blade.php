@extends('layouts.app')
@section('title', $claim->isEditableByOwner() ? 'Check & submit' : 'Edit claim')
@section('subtitle', $claim->claim_number.' · '.$claim->user->name)
@section('actions')
    @if ($claim->isEditableByOwner())
        @include('claims._steps', ['step' => 2])
    @else
        @include('claims._status', ['status' => $claim->status])
    @endif
@endsection

@section('content')
@php
    // $prefill 只放原本空着的栏位（按了 "Re-fill from OCR" 就是 OCR 读到的全部），所以优先用它
    $itemsData = old('items', $prefill['items'] ?? $claim->items->map(fn ($i) => [
        'item_name' => $i->item_name,
        'quantity' => rtrim(rtrim((string) $i->quantity, '0'), '.'),
        'unit' => $i->unit,
        'unit_price' => $i->unit_price,
        // 只有总价的行：还原成折扣/税之前的金额
        'amount' => $i->unit_price === null ? number_format((float) $i->line_total + (float) $i->discount_amount - (float) $i->tax_amount, 2, '.', '') : '',
        'discount_amount' => (float) $i->discount_amount ? $i->discount_amount : '',
        'tax_amount' => (float) $i->tax_amount ? $i->tax_amount : '',
        'receipt_id' => $i->receipt_id,
    ])->all());
    // OCR 预填、而且验证失败后没被 old input 盖掉的栏位，加黄色高亮
    $ocr = fn (string $field) => isset($prefill[$field]) && old($field) === null;
    $ocrClass = 'ocr-filled';
    $receiptOptions = $claim->receipts->values()->map(fn ($r, $i) => ['id' => $r->id, 'name' => 'Receipt '.($i + 1).' · '.\Illuminate\Support\Str::limit($r->original_filename, 20)]);
@endphp

{{-- 被拒绝时显示拒绝原因 --}}
@if ($claim->isRejected())
    <div class="alert border-rose-200 bg-rose-50 text-rose-800">
        <x-icon name="x" class="mt-0.5 h-5 w-5 text-rose-600" />
        <div>Rejected by <strong>{{ $claim->reviewer?->name }}</strong> on {{ $claim->reviewed_at?->format('d/m/Y H:i') }} — “{{ $claim->review_note }}”.
            <div>Fix the claim below, then tap <strong>Resubmit for approval</strong>.</div></div>
    </div>
@endif

<div class="grid gap-6 lg:grid-cols-5">
    {{-- 收据预览：手机上可折叠，大屏固定在表单旁边 --}}
    <div class="min-w-0 lg:col-span-2">
        <div class="lg:sticky lg:top-24">
            <details class="group card overflow-hidden lg:open:block" @if ($claim->receipts->isNotEmpty()) open @endif>
                <summary class="card-header cursor-pointer list-none">
                    <span class="card-title"><x-icon name="receipt" class="h-5 w-5 text-slate-400" /> Receipt{{ $claim->receipts->count() > 1 ? 's' : '' }} ({{ $claim->receipts->count() }})</span>
                    <span class="text-xs font-semibold text-brand-600 group-open:hidden">Show</span>
                    <span class="hidden text-xs font-semibold text-slate-500 group-open:inline">Hide</span>
                </summary>
                <div class="max-h-[75vh] space-y-4 overflow-y-auto p-4">
                    @include('receipts._panel', ['editable' => true])

                    {{-- 追加收据 --}}
                    <form method="POST" action="{{ route('claims.receipts.store', $claim) }}" enctype="multipart/form-data" class="rounded-xl border border-dashed border-slate-300 p-4" data-loading="Reading your receipt…">
                        @csrf
                        <label class="label">{{ $claim->receipts->isEmpty() ? 'Add a receipt' : 'Add another receipt' }}</label>
                        <input type="file" name="receipts[]" multiple required accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                               class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:font-semibold file:text-brand-700">
                        <button class="btn-secondary mt-3 w-full"><x-icon name="upload" class="h-4 w-4" /> Upload &amp; read</button>
                    </form>
                </div>
            </details>
        </div>
    </div>

    {{-- Claim 表单 --}}
    <form method="POST" action="{{ route('claims.update', $claim) }}" id="claim-form" class="min-w-0 space-y-5 lg:col-span-3">
        @csrf @method('PUT')

        {{-- OCR 结果对得上收据总额而已存明细对不上时，建议重新带入 --}}
        @if ($ocrSuggestsRefill)
            <div class="alert border-brand-200 bg-brand-50 text-brand-900">
                <x-icon name="sparkles" class="mt-0.5 h-5 w-5 text-brand-600" />
                <div class="flex-1"><strong>The receipt reading adds up, your saved items don't.</strong> OCR found items (incl. add-ons / tax) that match the receipt total. Load them into the form?</div>
                <a href="{{ route('claims.edit', ['claim' => $claim, 'refill' => 1]) }}" class="btn-primary shrink-0 px-3 py-1.5 text-xs">↻ Re-fill from OCR</a>
            </div>
        @elseif ($claim->isDraft() && $claim->receipts->contains('ocr_status', 'completed'))
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white px-4 py-3 text-sm ring-1 ring-slate-200">
                <span class="text-slate-600">Want to start again from what the receipt says?</span>
                <a href="{{ route('claims.edit', ['claim' => $claim, 'refill' => 1]) }}" class="btn-ghost px-3 py-1.5 text-xs"
                   onclick="return confirm('Replace the form with the values read from the receipt? Your saved draft is only changed when you click Save Draft / Submit for approval.')">↻ Re-fill from OCR</a>
            </div>
        @endif

        @if (! empty($prefill))
            <div id="ocr-banner" class="alert border-amber-300 bg-amber-50 text-amber-900">
                <x-icon name="sparkles" class="mt-0.5 h-5 w-5 text-amber-600" />
                <div><strong>Auto-filled from the receipt (OCR).</strong> Fields in <span class="rounded bg-amber-100 px-1 ring-1 ring-amber-300">yellow</span> were filled automatically — please check them against the receipt photo. Nothing is saved until you click Save Draft / Submit for approval.</div>
            </div>
        @endif

        {{-- 1. 基本资料 --}}
        <div class="card">
            <div class="card-header"><h2 class="card-title"><span class="grid h-6 w-6 place-items-center rounded-full bg-brand-600 text-xs font-bold text-white">1</span> Details</h2></div>
            <div class="grid gap-4 p-5 sm:grid-cols-2">
                <div>
                    <label class="label">Merchant name</label>
                    <input name="merchant_name" id="merchant_name" value="{{ old('merchant_name', $prefill['merchant_name'] ?? $claim->merchant_name) }}" class="input {{ $ocr('merchant_name') ? $ocrClass : '' }}" maxlength="255">
                </div>
                <div>
                    <label class="label">Location</label>
                    <input name="location" id="location" value="{{ old('location', $prefill['location'] ?? $claim->location) }}" class="input {{ $ocr('location') ? $ocrClass : '' }}" maxlength="255">
                </div>
                <div>
                    <label class="label">Expense date <span class="text-rose-500">*</span></label>
                    <input type="date" name="expense_date" id="expense_date" value="{{ old('expense_date', $prefill['expense_date'] ?? $claim->expense_date?->format('Y-m-d')) }}" class="input {{ $ocr('expense_date') ? $ocrClass : '' }}">
                    @if ($claim->isDraft() && ! $claim->expense_date && empty($prefill['expense_date']) && $claim->receipts->contains('ocr_status', 'completed'))
                        <p class="mt-1 text-xs text-rose-600">Couldn't read the date from the receipt — please type it from the photo.</p>
                    @endif
                </div>
                <div>
                    <label class="label">Expense time</label>
                    <input type="time" name="expense_time" id="expense_time" value="{{ old('expense_time', $prefill['expense_time'] ?? ($claim->expense_time ? substr($claim->expense_time, 0, 5) : '')) }}" class="input {{ $ocr('expense_time') ? $ocrClass : '' }}">
                </div>
                <div>
                    <label class="label">Category <span class="text-rose-500">*</span></label>
                    <select name="category_id" class="input {{ $ocr('category_id') ? $ocrClass : '' }}">
                        <option value="">— Select —</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}" @selected(old('category_id', $prefill['category_id'] ?? $claim->category_id) == $c->id)>{{ $c->name }}{{ $c->is_active ? '' : ' (inactive)' }}{{ $c->requires_receipt ? '' : ' · no receipt needed' }}</option>
                        @endforeach
                    </select>
                    @if ($ocr('category_id') && ! empty($prefill['category_reason']))
                        <p class="mt-1 text-xs text-amber-700">Auto-selected — {{ $prefill['category_reason'] }}. Please check.</p>
                    @endif
                </div>
                <div>
                    <label class="label">Project <span class="font-normal text-slate-400">(optional)</span></label>
                    <select name="project_id" class="input">
                        <option value="">— None —</option>
                        @foreach ($projects as $p)
                            <option value="{{ $p->id }}" @selected(old('project_id', $claim->project_id) == $p->id)>{{ $p->label }}{{ $p->is_active ? '' : ' (inactive)' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="label">Business purpose</label>
                    <input name="business_purpose" value="{{ old('business_purpose', $claim->business_purpose) }}" class="input" maxlength="500" placeholder="e.g. Site visit to customer in Klang">
                </div>
                <div class="sm:col-span-2">
                    <label class="label">Notes <span class="font-normal text-slate-400">(optional)</span></label>
                    <textarea name="notes" rows="2" class="input" maxlength="5000">{{ old('notes', $claim->notes) }}</textarea>
                </div>
            </div>
        </div>

        {{-- 2. 明细，行由 claim-form.js 生成 --}}
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><span class="grid h-6 w-6 place-items-center rounded-full bg-brand-600 text-xs font-bold text-white">2</span> What was bought</h2>
                <button type="button" id="add-item" class="btn-secondary px-3 py-1.5 text-xs"><x-icon name="plus" class="h-4 w-4" /> Add item</button>
            </div>
            <div class="p-4 sm:p-5">
                <p class="mb-3 text-xs text-slate-500">Line total = Qty × Unit price − Discount + Tax. If the receipt only shows a total, leave Unit price empty and type the <em>Amount</em>.</p>
                <div id="items-body" class="space-y-3"></div>
            </div>
        </div>

        {{-- 3. 合计 --}}
        <div class="card">
            <div class="card-header"><h2 class="card-title"><span class="grid h-6 w-6 place-items-center rounded-full bg-brand-600 text-xs font-bold text-white">3</span> Total</h2></div>
            <div class="grid gap-5 p-5 sm:grid-cols-2">
                <dl class="num space-y-1.5 text-sm">
                    <div class="flex justify-between text-slate-600"><dt>Subtotal</dt><dd id="sum-subtotal">0.00</dd></div>
                    <div class="flex justify-between text-slate-600"><dt>Discount</dt><dd id="sum-discount">0.00</dd></div>
                    <div class="flex justify-between text-slate-600"><dt>Tax</dt><dd id="sum-tax">0.00</dd></div>
                    <div class="flex justify-between border-t border-slate-200 pt-2 text-lg font-bold text-slate-900"><dt>Total ({{ $claim->currency === 'MYR' ? 'RM' : $claim->currency }})</dt><dd id="sum-total">0.00</dd></div>
                    <p class="text-xs text-slate-400">Preview — the server recalculates on save.</p>
                </dl>
                <div>
                    <label class="label">Total printed on the receipt</label>
                    <input type="number" step="0.01" min="0" name="receipt_total_amount" id="receipt_total_amount" value="{{ old('receipt_total_amount', $prefill['receipt_total_amount'] ?? $claim->receipt_total_amount) }}" class="input {{ $ocr('receipt_total_amount') ? $ocrClass : '' }}" placeholder="Used to check the items add up">
                    <div id="diff-box" class="mt-2 hidden rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800"></div>
                </div>
            </div>
        </div>

        {{-- 底部操作栏，滚动时固定在下方 --}}
        <div class="sticky bottom-20 z-20 lg:bottom-4">
            <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white/95 p-3 shadow-lg backdrop-blur sm:p-4">
                <div class="min-w-0 flex-1">
                    <div class="text-xs text-slate-500">Total</div>
                    <div class="num truncate text-lg font-bold text-slate-900">{{ $claim->currency === 'MYR' ? 'RM' : $claim->currency }} <span id="sum-total-sticky">0.00</span></div>
                </div>
                @if ($claim->isEditableByOwner())
                    <button name="action" value="draft" class="btn-secondary px-3 sm:px-4">Save<span class="hidden sm:inline">&nbsp;Draft</span></button>
                    <button name="action" value="submit" class="btn-success px-3 sm:px-4" onclick="return confirm('Submit this claim for approval? You cannot change it while it is being reviewed.')">
                        <x-icon name="upload" class="hidden h-5 w-5 sm:block" />
                        <span class="sm:hidden">{{ $claim->isRejected() ? 'Resubmit' : 'Submit' }}</span>
                        <span class="hidden sm:inline">{{ $claim->isRejected() ? 'Resubmit for approval' : 'Submit for approval' }}</span>
                    </button>
                @else
                    {{-- 等待审批中的 claim 只有 admin（claims.manage_all）能修改 --}}
                    <a href="{{ route('claims.show', $claim) }}" class="btn-secondary">Cancel</a>
                    <button name="action" value="submit" class="btn-primary">Save Changes</button>
                @endif
            </div>
        </div>
    </form>
</div>

{{-- 单行明细模板，由 resources/js/claim-form.js 复制使用 --}}
<template id="item-row">
    <div class="item-row rounded-xl border border-slate-200 bg-slate-50/50 p-3">
        <div class="flex items-start gap-2">
            <input data-name="item_name" class="input min-w-0 flex-1 font-medium" maxlength="255" placeholder="Item">
            <div class="shrink-0 pt-2 text-right">
                <div class="text-[11px] text-slate-400">Line total</div>
                <div class="line-total num font-bold text-slate-900">0.00</div>
            </div>
            <button type="button" class="remove-item btn-ghost p-2 text-rose-500 hover:bg-rose-50" title="Remove item"><x-icon name="trash" class="h-5 w-5" /></button>
        </div>
        <div class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-6 [&>label]:min-w-0">
            <label class="text-[11px] font-medium text-slate-500">Qty<input data-name="quantity" type="number" step="0.001" min="0.001" class="input mt-0.5" value="1"></label>
            <label class="text-[11px] font-medium text-slate-500">Unit<input data-name="unit" class="input mt-0.5" maxlength="20" placeholder="pcs / L"></label>
            <label class="text-[11px] font-medium text-slate-500">Unit price<input data-name="unit_price" type="number" step="0.01" min="0" class="input mt-0.5"></label>
            <label class="text-[11px] font-medium text-slate-500">Amount<input data-name="amount" type="number" step="0.01" min="0" class="input mt-0.5" title="Only used when unit price is empty"></label>
            <label class="text-[11px] font-medium text-slate-500">Discount<input data-name="discount_amount" type="number" step="0.01" min="0" class="input mt-0.5"></label>
            <label class="text-[11px] font-medium text-slate-500">Tax<input data-name="tax_amount" type="number" step="0.01" min="0" class="input mt-0.5"></label>
        </div>
        @if ($receiptOptions->isNotEmpty())
            <label class="mt-2 block text-[11px] font-medium text-slate-500">From receipt
                <select data-name="receipt_id" class="input mt-0.5">
                    <option value="">—</option>
                    @foreach ($receiptOptions as $r)<option value="{{ $r['id'] }}">{{ $r['name'] }}</option>@endforeach
                </select>
            </label>
        @endif
    </div>
</template>
@endsection

@push('scripts')
<script>
    window.claimItems = @json(array_values($itemsData));
</script>
@vite('resources/js/claim-form.js')
@endpush
