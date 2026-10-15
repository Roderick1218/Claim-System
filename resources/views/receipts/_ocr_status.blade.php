{{-- 收据的 OCR 状态 badge。需要 $status --}}
@php
    $styles = [
        'pending' => ['bg-white/90 text-slate-700 ring-slate-200', 'Waiting to read'],
        'processing' => ['bg-white/90 text-sky-700 ring-sky-200', 'Reading…'],
        'completed' => ['bg-white/90 text-brand-700 ring-brand-200', 'Text read'],
        'failed' => ['bg-white/90 text-rose-700 ring-rose-200', "Couldn't read"],
    ];
    [$class, $label] = $styles[$status] ?? ['bg-white/90 text-slate-700 ring-slate-200', ucfirst($status)];
@endphp
<span class="badge shadow-sm ring-1 ring-inset {{ $class }}" title="OCR {{ ucfirst($status) }}">OCR · {{ $label }}</span>
