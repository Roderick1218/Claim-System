@php
    $styles = [
        'draft' => ['bg-slate-100 text-slate-600', 'bg-slate-400', 'Draft'],
        'submitted' => ['bg-amber-50 text-amber-700', 'bg-amber-500', 'Pending'],
        'approved' => ['bg-brand-50 text-brand-700', 'bg-brand-500', 'Approved'],
        'rejected' => ['bg-rose-50 text-rose-600', 'bg-rose-500', 'Rejected'],
    ];
    [$class, $dot, $label] = $styles[$status] ?? ['bg-slate-100 text-slate-600', 'bg-slate-400', ucfirst((string) $status)];
@endphp
<span class="badge {{ $class }}"><span class="h-1.5 w-1.5 rounded-full {{ $dot }}"></span>{{ $label }}</span>
