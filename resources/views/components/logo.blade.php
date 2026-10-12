{{-- Company logo (public/images/logo.png, black on transparent — put it on a light background). Usage: <x-logo class="h-10" /> --}}
<img src="{{ asset('images/logo.png') }}" alt="{{ config('app.name') }}" {{ $attributes->merge(['class' => 'h-10 w-auto shrink-0 object-contain']) }}>
