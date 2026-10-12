{{-- Draft → Submitted → Approved (or Rejected) --}}
@php
    $rejected = $claim->isRejected();
    $steps = [
        ['Draft', 'pencil', $claim->created_at],
        ['Submitted', 'upload', $claim->submitted_at],
        [$rejected ? 'Rejected' : 'Approved', $rejected ? 'x' : 'check', $claim->reviewed_at],
    ];
    $reached = match ($claim->status) { 'draft' => 0, 'submitted' => 1, default => 2 };
@endphp
<ol class="flex items-start">
    @foreach ($steps as $i => [$label, $icon, $at])
        @php
            $done = $i <= $reached;
            $color = ! $done ? 'bg-white text-slate-400 ring-slate-200'
                : ($i === 2 && $rejected ? 'bg-rose-600 text-white ring-rose-600'
                : ($i === $reached && $claim->isSubmitted() ? 'bg-amber-500 text-white ring-amber-500' : 'bg-brand-600 text-white ring-brand-600'));
        @endphp
        <li class="relative flex flex-1 flex-col items-center text-center">
            @if ($i > 0)
                <span class="absolute top-4 right-1/2 h-0.5 w-full {{ $done ? 'bg-brand-500' : 'bg-slate-200' }}"></span>
            @endif
            <span class="relative grid h-8 w-8 place-items-center rounded-full ring-2 {{ $color }}"><x-icon :name="$icon" class="h-4 w-4" /></span>
            <span class="mt-2 text-xs font-semibold {{ $done ? 'text-slate-900' : 'text-slate-400' }}">{{ $label }}</span>
            <span class="text-[11px] text-slate-500">{{ $done && $at ? $at->format('d M, H:i') : ($i === $reached + 1 && $claim->isSubmitted() ? 'Waiting…' : '') }}</span>
        </li>
    @endforeach
</ol>
