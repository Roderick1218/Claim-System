{{-- 新建 claim 的 stepper：1 Upload → 2 Review → 3 Submit。$step = 当前步骤（1-3） --}}
<ol class="flex items-center gap-2 text-xs font-semibold sm:gap-3 sm:text-sm">
    @foreach (['Upload', 'Review', 'Submit'] as $i => $label)
        @php $n = $i + 1; $done = $n < $step; $current = $n === $step; @endphp
        @if ($i)<li class="h-px w-6 sm:w-12 {{ $n <= $step ? 'bg-brand-400' : 'bg-slate-200' }}" aria-hidden="true"></li>@endif
        <li class="flex items-center gap-1.5 sm:gap-2 {{ $done || $current ? 'text-brand-700' : 'text-slate-400' }}">
            <span class="grid h-6 w-6 place-items-center rounded-full text-[11px] {{ $done || $current ? 'bg-brand-600 text-white' : 'bg-white text-slate-400 ring-1 ring-slate-200' }}">
                @if ($done)<x-icon name="check" class="h-3.5 w-3.5" />@else{{ $n }}@endif
            </span>
            {{ $label }}
        </li>
    @endforeach
</ol>
