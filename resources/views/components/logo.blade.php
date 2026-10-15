{{-- 公司 logo（public/images/logo.png，透明底黑字，要放在浅色背景上）。用法：<x-logo class="h-10" /> --}}
<img src="{{ asset('images/logo.png') }}" alt="{{ config('app.name') }}" {{ $attributes->merge(['class' => 'h-10 w-auto shrink-0 object-contain']) }}>
