<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b1533">
    <title>Sign in · {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-[#f5f7fb] antialiased">
<div class="grid min-h-screen lg:grid-cols-2">
    {{-- Brand panel (large screens) --}}
    <div class="relative hidden overflow-hidden bg-gradient-to-br from-navy-900 via-navy-800 to-navy-700 p-12 text-white lg:flex lg:flex-col lg:justify-between">
        <div class="flex items-center gap-4">
            <span class="rounded-2xl bg-white px-5 py-3 shadow-lg shadow-black/20"><x-logo class="h-14" /></span>
            <span class="text-2xl font-bold tracking-tight">{{ config('app.name') }}</span>
        </div>
        <div class="relative z-10 max-w-md">
            <h2 class="text-4xl leading-tight font-bold">Less Paper.<br>More Progress.</h2>
            <p class="mt-3 text-slate-300">Smart claim system for a better tomorrow.</p>
            <ul class="mt-10 space-y-4 text-slate-200">
                @foreach ([['camera', 'Smart OCR', 'Auto read receipt & fill details'], ['clock', 'Real-time status', 'Track your claim anytime'], ['shield', 'Secure & reliable', 'Staff · Manager · Admin access']] as [$icon, $title, $text])
                    <li class="flex items-center gap-4">
                        <span class="grid h-11 w-11 place-items-center rounded-xl bg-white/10 text-brand-300"><x-icon :name="$icon" class="h-5 w-5" /></span>
                        <span><span class="block font-semibold text-white">{{ $title }}</span><span class="text-sm text-slate-400">{{ $text }}</span></span>
                    </li>
                @endforeach
            </ul>
        </div>
        <div class="relative z-10 flex items-center gap-3 text-sm text-slate-400">
            <span>Fast</span><span class="h-1 w-1 rounded-full bg-slate-500"></span><span>Accurate</span><span class="h-1 w-1 rounded-full bg-slate-500"></span><span>Secure</span>
        </div>
        <div class="pointer-events-none absolute -right-32 -bottom-32 h-[28rem] w-[28rem] rounded-full bg-gradient-to-br from-accent-500/30 to-brand-500/20 blur-2xl"></div>
        <div class="pointer-events-none absolute top-20 -right-10 h-40 w-40 rounded-full bg-accent-400/20 blur-2xl"></div>
    </div>

    {{-- Sign-in form --}}
    <div class="flex items-center justify-center p-6">
        <div class="w-full max-w-sm">
            <div class="mb-8 text-center lg:text-left">
                <div class="mb-6 flex justify-center lg:hidden"><x-logo class="h-16" /></div>
                <h1 class="text-2xl font-bold text-slate-900">Welcome back</h1>
                <p class="mt-1 text-sm text-slate-500">Sign in with your work email to manage your claims.</p>
            </div>
            <form method="POST" action="{{ url('/login') }}" class="card space-y-5 p-6">
                @csrf
                @if ($errors->any())
                    <div class="alert mb-0 border-rose-200 bg-rose-50 text-rose-700"><x-icon name="exclamation" class="mt-0.5 h-5 w-5" /><div>{{ $errors->first() }}</div></div>
                @endif
                <div>
                    <label class="label" for="email">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" class="input py-3" required autofocus autocomplete="username" placeholder="name@company.com">
                </div>
                <div>
                    <label class="label" for="password">Password</label>
                    <input id="password" name="password" type="password" class="input py-3" required autocomplete="current-password">
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="remember" value="1" class="h-4 w-4 rounded border-slate-300 accent-brand-600"> Keep me signed in
                </label>
                <button class="btn-primary btn-lg w-full">Sign in</button>
            </form>
            <p class="mt-8 text-center text-xs text-slate-400">Forgot your password? Ask your administrator to reset it.</p>
        </div>
    </div>
</div>
</body>
</html>
