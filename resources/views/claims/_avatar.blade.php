{{-- Coloured icon tile for a claim, picked from its category. $c = claim, $size = tailwind size classes --}}
@php
    $name = mb_strtolower($c->category?->name ?? '');
    [$icon, $tone] = match (true) {
        str_contains($name, 'food') => ['utensils', 'bg-rose-50 text-rose-500'],
        str_contains($name, 'petrol'), str_contains($name, 'fuel') => ['bolt', 'bg-amber-50 text-amber-500'],
        str_contains($name, 'toll'), str_contains($name, 'parking'), str_contains($name, 'transport') => ['truck', 'bg-sky-50 text-sky-500'],
        str_contains($name, 'accommodation') => ['building', 'bg-violet-50 text-violet-500'],
        str_contains($name, 'material') => ['wrench', 'bg-accent-50 text-accent-600'],
        str_contains($name, 'office') => ['briefcase', 'bg-brand-50 text-brand-600'],
        default => ['receipt', 'bg-slate-100 text-slate-500'],
    };
@endphp
<div class="grid shrink-0 place-items-center rounded-xl {{ $size ?? 'h-10 w-10' }} {{ $tone }}"><x-icon :name="$icon" class="h-5 w-5" /></div>
