{{-- Receipt preview + OCR text. $editable = show retry / delete buttons. $hints only on the edit page. --}}
@foreach ($claim->receipts as $receipt)
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div class="relative bg-slate-100">
            @if ($receipt->isImage())
                <a href="{{ route('receipts.preview', $receipt) }}" target="_blank" rel="noopener" title="Open full size">
                    <img src="{{ route('receipts.preview', $receipt) }}" alt="Receipt" class="mx-auto max-h-[480px] object-contain" loading="lazy">
                </a>
            @else
                <iframe src="{{ route('receipts.preview', $receipt) }}" class="h-[480px] w-full" title="Receipt PDF"></iframe>
            @endif
            <div class="absolute top-2 left-2">@include('receipts._ocr_status', ['status' => $receipt->ocr_status])</div>
        </div>

        <div class="space-y-3 p-3">
            <div class="flex items-center justify-between gap-2">
                <div class="min-w-0">
                    <div class="truncate text-sm font-medium text-slate-900" title="{{ $receipt->original_filename }}">{{ $receipt->original_filename }}</div>
                    <div class="text-xs text-slate-500">{{ $receipt->file_size_label }} · {{ $receipt->created_at->format('d M Y, H:i') }}</div>
                </div>
                <div class="flex shrink-0 gap-1">
                    <a href="{{ route('receipts.download', $receipt) }}" class="btn-ghost p-2" title="Download"><x-icon name="download" class="h-4 w-4" /></a>
                    @if ($editable ?? false)
                        @can('retryOcr', $receipt)
                            <form method="POST" action="{{ route('receipts.ocr.retry', $receipt) }}" data-loading="Reading the receipt again…">
                                @csrf
                                <button class="btn-ghost p-2" title="Read again (Retry OCR)"><x-icon name="refresh" class="h-4 w-4" /><span class="sr-only">Retry OCR</span></button>
                            </form>
                        @endcan
                        @can('delete', $receipt)
                            <form method="POST" action="{{ route('receipts.destroy', $receipt) }}" onsubmit="return confirm('Remove this receipt?')">
                                @csrf @method('DELETE')
                                <button class="btn-ghost p-2 text-rose-500 hover:bg-rose-50" title="Remove"><x-icon name="trash" class="h-4 w-4" /><span class="sr-only">Remove</span></button>
                            </form>
                        @endcan
                    @endif
                </div>
            </div>

            @if ($receipt->ocr_status === 'failed')
                <div class="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{{ $receipt->ocr_error ?: 'OCR failed.' }} You can enter all details manually.</div>
            @elseif ($receipt->ocr_status !== 'completed')
                <div class="rounded-lg bg-slate-100 p-3 text-sm text-slate-600">OCR {{ $receipt->ocr_status }}…</div>
            @endif

            @if (! empty($hints[$receipt->id]) && collect(\Illuminate\Support\Arr::only($hints[$receipt->id], ['totals', 'dates', 'times']))->flatten()->isNotEmpty())
                <div class="rounded-lg bg-brand-50/70 p-3 text-xs">
                    <div class="mb-2 font-semibold text-brand-800">Found in OCR text — click to copy into the form (please check):</div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($hints[$receipt->id]['totals'] as $v)
                            <button type="button" class="rounded-lg bg-white px-2 py-1 ring-1 ring-brand-200 hover:bg-brand-100" data-fill="receipt_total_amount" data-value="{{ $v }}">Receipt total {{ $v }}</button>
                        @endforeach
                        @foreach ($hints[$receipt->id]['dates'] as $d)
                            <button type="button" class="rounded-lg bg-white px-2 py-1 ring-1 ring-brand-200 hover:bg-brand-100" data-fill="expense_date" data-value="{{ $d['iso'] }}">Date {{ $d['raw'] }}</button>
                        @endforeach
                        @foreach ($hints[$receipt->id]['times'] as $t)
                            <button type="button" class="rounded-lg bg-white px-2 py-1 ring-1 ring-brand-200 hover:bg-brand-100" data-fill="expense_time" data-value="{{ $t }}">Time {{ $t }}</button>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($receipt->ocr_status === 'completed')
                <details class="group">
                    <summary class="cursor-pointer text-xs font-semibold text-slate-500 hover:text-slate-700">Show the text read from this receipt</summary>
                    <pre class="mt-2 max-h-64 overflow-auto rounded-lg bg-slate-900 p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap text-slate-100">{{ $receipt->ocr_text }}</pre>
                </details>
            @endif
        </div>
    </div>
@endforeach
