@extends('layouts.app')
@php
    $hour = (int) now()->format('H');
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    $firstName = explode(' ', auth()->user()->name)[0];
@endphp
@section('title', 'Dashboard')
@section('heading', $greeting.', '.$firstName.' 👋')
@section('subtitle', "Here's your claim overview for today.")
@section('actions')
    <span class="hidden items-center gap-2 rounded-xl sm:inline-flex border border-slate-200 bg-white px-3.5 py-2 text-sm font-medium text-slate-600 shadow-sm">
        {{ now()->format('D, j M Y') }} <x-icon name="calendar" class="h-4 w-4 text-slate-400" />
    </span>
@endsection

@section('content')
@php
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
    $maxCat = max(1, (float) $byCategory->max('total'));
    // "+20% vs last month" — null when there is nothing to compare with.
    $delta = function (array $pair) {
        [$now, $before] = $pair;
        if ($before == 0) {
            return $now > 0 ? ['new', 'text-brand-600'] : null;
        }
        $pct = round(($now - $before) / $before * 100);

        return [($pct >= 0 ? '+ ' : '− ').abs($pct).'%', $pct >= 0 ? 'text-brand-600' : 'text-rose-500'];
    };

    // Line chart geometry (0–100 box, stretched by CSS; labels are HTML so they stay readable).
    $values = $trend->pluck('total')->values();
    $rawMax = max(1, (float) $values->max());
    $step = (function ($v) {
        $raw = $v / 4;
        $mag = 10 ** floor(log10($raw));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $mag >= $raw) {
                return $m * $mag;
            }
        }
    })($rawMax);
    $chartMax = $step * 4;
    $n = $values->count();
    $pts = $values->map(fn ($v, $i) => [$n > 1 ? $i / ($n - 1) * 100 : 50, 100 - $v / $chartMax * 100])->all();
    $line = 'M'.$pts[0][0].','.$pts[0][1];
    for ($i = 0; $i < $n - 1; $i++) { // Catmull-Rom → cubic Bézier for a smooth curve
        $p0 = $pts[max(0, $i - 1)]; $p1 = $pts[$i]; $p2 = $pts[$i + 1]; $p3 = $pts[min($n - 1, $i + 2)];
        $c1 = [$p1[0] + ($p2[0] - $p0[0]) / 6, min(100, $p1[1] + ($p2[1] - $p0[1]) / 6)];
        $c2 = [$p2[0] - ($p3[0] - $p1[0]) / 6, min(100, $p2[1] - ($p3[1] - $p1[1]) / 6)];
        $line .= " C{$c1[0]},{$c1[1]} {$c2[0]},{$c2[1]} {$p2[0]},{$p2[1]}";
    }
    $area = $line." L100,100 L0,100 Z";
    $peak = $values->search($values->max());
    $tick = fn ($v) => $v >= 1000 ? rtrim(rtrim(number_format($v / 1000, 1), '0'), '.').'k' : number_format($v);
@endphp

{{-- Things that need the user's action come first --}}
@if ($toApproveCount)
    <a href="{{ route('approvals.index') }}" class="mb-3 flex items-center gap-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 transition hover:bg-amber-100">
        <div class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-amber-500 text-white"><x-icon name="check-badge" class="h-6 w-6" /></div>
        <div class="flex-1 text-amber-900"><strong>{{ $toApproveCount }}</strong> claim(s) waiting for your approval.<div class="text-sm text-amber-700">Oldest first — review them one after another.</div></div>
        <x-icon name="arrow-right" class="h-5 w-5 text-amber-700" />
    </a>
@endif

@foreach ($myRejected as $r)
    <a href="{{ route('claims.edit', $r) }}" class="mb-3 flex items-center gap-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 transition hover:bg-rose-100">
        <div class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-rose-500 text-white"><x-icon name="x" class="h-6 w-6" /></div>
        <div class="min-w-0 flex-1 text-rose-900">
            Your claim <strong>{{ $r->claim_number }}</strong> was rejected
            <div class="truncate text-sm text-rose-700">“{{ $r->review_note }}”</div>
        </div>
        <span class="hidden text-sm font-semibold text-rose-700 sm:inline">Fix &amp; resubmit</span>
        <x-icon name="arrow-right" class="h-5 w-5 text-rose-700" />
    </a>
@endforeach

{{-- Stat cards --}}
<div class="grid grid-cols-2 gap-3 sm:gap-5 xl:grid-cols-4">
    @foreach ([
        ['Total Claims', $stats['total'][0], 'this month', $delta($stats['total']), 'receipt', 'bg-accent-50 text-accent-600', route('claims.index')],
        ['Pending Approval', $pendingCount, $money($pendingTotal), null, 'clock', 'bg-amber-50 text-amber-500', route('claims.index', ['status' => 'submitted'])],
        ['Approved', $monthCount, $money($monthTotal).' this month', $delta($stats['approved']), 'check', 'bg-brand-50 text-brand-600', route('claims.index', ['status' => 'approved'])],
        ['Rejected', $stats['rejected'][0], 'this month', $delta($stats['rejected']), 'x', 'bg-rose-50 text-rose-500', route('claims.index', ['status' => 'rejected'])],
    ] as [$label, $value, $sub, $change, $icon, $tone, $href])
        <a href="{{ $href }}" class="card flex flex-col gap-3 p-4 transition hover:-translate-y-0.5 hover:shadow-md sm:flex-row sm:items-center sm:gap-4 sm:p-5">
            <div class="grid h-11 w-11 shrink-0 place-items-center rounded-full sm:h-14 sm:w-14 {{ $tone }}"><x-icon :name="$icon" class="h-6 w-6 sm:h-7 sm:w-7" /></div>
            <div class="min-w-0">
                <div class="truncate text-xs font-medium text-slate-500 sm:text-sm">{{ $label }}</div>
                <div class="num text-2xl font-bold text-slate-900 sm:text-[28px]">{{ $value }}</div>
                <div class="truncate text-xs text-slate-500">
                    @if ($change)<span class="font-semibold {{ $change[1] }}">{{ $change[0] }}</span> vs last month
                    @else {{ $sub }} @endif
                </div>
            </div>
        </a>
    @endforeach
</div>

<a href="{{ route('claims.create') }}" class="btn-primary btn-lg mt-4 w-full lg:hidden"><x-icon name="plus" class="h-5 w-5" /> New Claim</a>

<div class="mt-5 grid gap-5 lg:grid-cols-2">
    {{-- Claim summary chart --}}
    <div class="card">
        <div class="card-header border-0 pb-0">
            <h2 class="card-title">Claim Summary</h2>
            <form method="GET">
                <select name="range" onchange="this.form.submit()" class="rounded-lg border-0 bg-accent-50 py-1.5 pr-8 pl-3 text-xs font-semibold text-accent-700 focus:ring-2 focus:ring-accent-200">
                    <option value="year" @selected($range === 'year')>This year</option>
                    <option value="6" @selected($range === '6')>Last 6 months</option>
                    <option value="12" @selected($range === '12')>Last 12 months</option>
                </select>
            </form>
        </div>
        <div class="px-5 pt-2 text-xs text-slate-400">Approved amount per month (RM)</div>
        <div class="flex gap-2 p-5 pt-3">
            <div class="num relative h-46 w-8 shrink-0 text-right text-[11px] text-slate-400">
                @foreach ([4, 3, 2, 1, 0] as $k)<span class="absolute right-0 -translate-y-1/2" style="top: {{ 100 - $k * 25 }}%">{{ $tick($step * $k) }}</span>@endforeach
            </div>
            <div class="relative min-w-0 flex-1">
                <div class="relative h-46">
                    @foreach ([0, 25, 50, 75, 100] as $g)<div class="absolute inset-x-0 border-t border-dashed border-slate-100" style="top: {{ $g }}%"></div>@endforeach
                    <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 h-full w-full overflow-visible">
                        <defs>
                            <linearGradient id="trend-fill" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0" stop-color="#00c896" stop-opacity=".28"/><stop offset="1" stop-color="#00c896" stop-opacity="0"/>
                            </linearGradient>
                        </defs>
                        <path d="{{ $area }}" fill="url(#trend-fill)"/>
                        <path d="{{ $line }}" fill="none" stroke="#00b386" stroke-width="2.5" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>
                    </svg>
                    @foreach ($pts as $i => [$x, $y])
                        <div class="group absolute -translate-x-1/2 -translate-y-1/2" style="left: {{ $x }}%; top: {{ $y }}%">
                            <span class="block h-2.5 w-2.5 rounded-full border-2 border-brand-600 bg-white"></span>
                            <span class="num pointer-events-none absolute bottom-4 left-1/2 -translate-x-1/2 rounded-md bg-navy-900 px-2 py-1 text-[11px] font-semibold whitespace-nowrap text-white {{ $i === $peak && $values[$i] > 0 ? '' : 'hidden group-hover:block' }}">{{ $money($values[$i]) }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="mt-2 flex justify-between text-[11px] text-slate-400">
                    @foreach ($trend as $i => $t)
                        <span class="{{ $n > 8 && $i % 2 ? 'hidden sm:inline' : '' }}">{{ \Illuminate\Support\Str::before($t['label'], ' ') }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- Recent claims --}}
    <div class="card overflow-hidden">
        <div class="card-header border-0 pb-1">
            <h2 class="card-title">Recent Claims</h2>
            <a href="{{ route('claims.index') }}" class="text-sm font-semibold text-accent-600 hover:text-accent-700">View all</a>
        </div>
        <div class="divide-y divide-slate-100">
            @forelse ($recent as $c)
                @include('claims._row', ['c' => $c, 'showOwner' => $c->user_id !== auth()->id()])
            @empty
                <div class="px-5 py-12 text-center">
                    <div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-brand-50 text-brand-600"><x-icon name="inbox" class="h-7 w-7" /></div>
                    <p class="mt-3 font-semibold text-slate-900">No claims yet</p>
                    <p class="text-sm text-slate-500">Take a photo of your first receipt to get started.</p>
                    <a href="{{ route('claims.create') }}" class="btn-primary mt-4"><x-icon name="camera" class="h-5 w-5" /> New Claim</a>
                </div>
            @endforelse
        </div>
    </div>
</div>

<div class="mt-5 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
    {{-- Spending by category --}}
    <div class="card">
        <div class="card-header"><h2 class="card-title"><x-icon name="tag" class="h-5 w-5 text-slate-400" /> This month by category</h2></div>
        <div class="space-y-4 p-5">
            @forelse ($byCategory as $row)
                <div>
                    <div class="flex justify-between gap-2 text-sm"><span class="truncate text-slate-700">{{ $row->label }} <span class="text-slate-400">· {{ $row->count }}</span></span><span class="num font-semibold">{{ $money($row->total) }}</span></div>
                    <div class="mt-1.5 h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-gradient-to-r from-accent-500 to-brand-500" style="width: {{ max(3, round($row->total / $maxCat * 100)) }}%"></div></div>
                </div>
            @empty
                <p class="text-sm text-slate-500">No approved claims this month yet.</p>
            @endforelse
        </div>
    </div>

    @if ($byProject->isNotEmpty())
        <div class="card">
            <div class="card-header"><h2 class="card-title"><x-icon name="folder" class="h-5 w-5 text-slate-400" /> This month by project</h2></div>
            <div class="divide-y divide-slate-100">
                @foreach ($byProject as $row)
                    <div class="flex justify-between gap-2 px-5 py-2.5 text-sm"><span class="truncate text-slate-700">{{ $row->label }} <span class="text-slate-400">· {{ $row->count }}</span></span><span class="num font-semibold">{{ $money($row->total) }}</span></div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($receiptAttention->isNotEmpty())
        <div class="card">
            <div class="card-header"><h2 class="card-title"><x-icon name="exclamation" class="h-5 w-5 text-rose-500" /> Receipts OCR couldn't read</h2></div>
            <div class="divide-y divide-slate-100">
                @foreach ($receiptAttention as $r)
                    <a href="{{ route('claims.show', $r->claim) }}" class="flex items-center justify-between gap-3 px-5 py-3 text-sm hover:bg-slate-50">
                        <span class="truncate"><span class="font-semibold">{{ $r->claim->claim_number }}</span> · {{ $r->original_filename }}</span>
                        @include('receipts._ocr_status', ['status' => $r->ocr_status])
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <div class="card flex flex-col justify-between overflow-hidden bg-gradient-to-br from-navy-900 to-navy-700 p-5 text-white">
        <div>
            <div class="text-sm text-slate-300">My drafts</div>
            <div class="num mt-1 text-3xl font-bold">{{ $myDraftCount }}</div>
            <div class="text-sm text-slate-300">not submitted yet</div>
        </div>
        <p class="mt-6 text-lg font-semibold leading-snug">Less paper.<br>More progress.</p>
        @if ($myDraftCount)
            <a href="{{ route('claims.index', ['status' => 'draft']) }}" class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-brand-300 hover:text-brand-200">Continue a draft <x-icon name="arrow-right" class="h-4 w-4" /></a>
        @endif
    </div>
</div>
@endsection
