{{-- 单条 claim 的列表行，手机版和短列表用。需要 $c（claim），$showOwner 可选，是否显示提交人 --}}
<a href="{{ route('claims.show', $c) }}" class="flex items-center gap-3 px-4 py-3.5 transition hover:bg-slate-50 sm:px-5">
    @include('claims._avatar', ['c' => $c])
    <div class="min-w-0 flex-1">
        <div class="truncate font-semibold text-slate-900">{{ $c->merchant_name ?: 'No merchant yet' }}</div>
        <div class="truncate text-xs text-slate-500">
            {{ $c->claim_number }}<span class="hidden sm:inline"> · {{ $c->expense_date?->format('d M Y') ?? 'no date' }}</span>{{ ($showOwner ?? false) && $c->user ? ' · '.$c->user->name : '' }}
        </div>
    </div>
    <div class="flex shrink-0 flex-col items-end gap-1 sm:flex-row sm:items-center sm:gap-3">
        <div class="num text-sm font-semibold text-slate-900">RM {{ number_format((float) $c->total_amount, 2) }}</div>
        <div class="sm:w-[86px] sm:text-right">@include('claims._status', ['status' => $c->status])</div>
    </div>
</a>
