<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0b1533">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#f5f7fb] text-slate-800 antialiased">
{{-- 通知数量和菜单项在这里统一算好，下面直接用 --}}
@php
    $u = auth()->user();
    $canApprove = $u->hasPermission('claims.approve');
    $toApprove = $canApprove
        ? \App\Models\Claim::query()->visibleTo($u)->where('claims.status', 'submitted')->where('claims.user_id', '<>', $u->id)->count()
        : 0;
    $myRejectedCount = \App\Models\Claim::query()->where('user_id', $u->id)->where('status', 'rejected')->count();
    $myDraftCount = \App\Models\Claim::query()->where('user_id', $u->id)->where('status', 'draft')->count();
    $notifications = array_filter([
        $toApprove ? [route('approvals.index'), 'check-badge', 'bg-amber-50 text-amber-600', "{$toApprove} claim(s) waiting for your approval"] : null,
        $myRejectedCount ? [route('claims.index', ['status' => 'rejected']), 'x', 'bg-rose-50 text-rose-600', "{$myRejectedCount} of your claim(s) rejected — fix & resubmit"] : null,
        $myDraftCount ? [route('claims.index', ['status' => 'draft']), 'pencil', 'bg-slate-100 text-slate-600', "{$myDraftCount} draft(s) not submitted yet"] : null,
    ]);

    // 每项：[名称, route, 高亮匹配, icon, 是否显示, 红点数量]
    $nav = [
        ['Dashboard', 'dashboard', ['dashboard'], 'home', true, 0],
        ['My Claims', 'claims.index', ['claims.index', 'claims.show', 'claims.edit'], 'receipt', true, 0],
        ['New Claim', 'claims.create', ['claims.create'], 'plus-circle', true, 0],
        ['Approvals', 'approvals.index', ['approvals.*'], 'check-badge', $canApprove, $toApprove],
        ['Reports', 'reports.index', ['reports.*'], 'chart', $u->hasPermission('reports.view'), 0],
        ['Users', 'settings.users.index', ['settings.users.*'], 'users', $u->hasPermission('settings.users'), 0],
    ];
    $settingsNav = array_filter([
        ['Departments', 'settings.departments.index', 'settings.departments.*', 'settings.departments'],
        ['Roles & Permissions', 'settings.roles.index', 'settings.roles.*', 'settings.roles'],
        ['Claim Categories', 'settings.categories.index', 'settings.categories.*', 'settings.categories'],
        ['Projects', 'settings.projects.index', 'settings.projects.*', 'settings.projects'],
        ['Audit Logs', 'settings.audit-logs.index', 'settings.audit-logs.*', 'audit.view'],
    ], fn ($i) => $u->hasPermission($i[3]));
    $inSettings = request()->routeIs('settings.*') && ! request()->routeIs('settings.users.*');
    $initials = collect(explode(' ', $u->name))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    $roleLabel = $u->roles->pluck('name')->map(fn ($r) => ucfirst($r))->implode(', ') ?: 'No role';
@endphp

<div class="flex min-h-screen">
    {{-- 侧边栏（电脑）/ 抽屉菜单（手机） --}}
    <div id="drawer-backdrop" class="fixed inset-0 z-40 hidden bg-slate-900/50 lg:hidden" onclick="toggleDrawer(false)"></div>
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col bg-gradient-to-b from-navy-900 to-navy-950 text-white transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:w-64 lg:translate-x-0">
        <a href="{{ route('dashboard') }}" class="mx-4 mt-4 mb-2 block rounded-2xl bg-white px-4 py-3 shadow-lg shadow-black/20">
            <x-logo class="h-auto w-full" />
            <span class="mt-1 block text-center text-[11px] font-semibold tracking-wider text-slate-500 uppercase">{{ config('app.name') }}</span>
        </a>

        <nav class="flex-1 space-y-1 overflow-y-auto px-4 pt-2 pb-4">
            @foreach ($nav as [$label, $route, $patterns, $icon, $show, $count])
                @continue(! $show)
                @php $active = request()->routeIs(...$patterns); @endphp
                <a href="{{ route($route) }}" class="nav-link {{ $active ? 'active' : '' }}">
                    <x-icon :name="$icon" class="h-5 w-5" />
                    <span class="flex-1">{{ $label }}</span>
                    @if ($count)<span class="grid h-5 min-w-5 place-items-center rounded-full bg-rose-500 px-1.5 text-[11px] font-bold text-white">{{ $count }}</span>@endif
                    @if ($active && ! $count)<x-icon name="chevron-right" class="h-4 w-4 opacity-70" />@endif
                </a>
            @endforeach

            {{-- Settings 子菜单，只列出有权限的项目 --}}
            @if ($settingsNav)
                <details class="group" @if ($inSettings) open @endif>
                    <summary class="nav-link cursor-pointer list-none {{ $inSettings ? 'text-white' : '' }}">
                        <x-icon name="cog" class="h-5 w-5" />
                        <span class="flex-1">Settings</span>
                        <x-icon name="chevron-down" class="h-4 w-4 opacity-70 transition group-open:rotate-180" />
                    </summary>
                    <div class="mt-1 ml-5 space-y-0.5 border-l border-white/10 pl-3">
                        @foreach ($settingsNav as [$label, $route, $pattern])
                            @php $active = request()->routeIs($pattern); @endphp
                            <a href="{{ route($route) }}" class="block rounded-lg px-3 py-2 text-sm {{ $active ? 'bg-white/10 font-semibold text-white' : 'text-slate-400 hover:bg-white/5 hover:text-white' }}">{{ $label }}</a>
                        @endforeach
                    </div>
                </details>
            @endif
        </nav>

        {{-- 用户资料：改密码 / 登出 --}}
        <details class="relative m-4 mt-0" data-dropdown>
            <summary class="flex cursor-pointer list-none items-center gap-3 rounded-xl px-2 py-2 hover:bg-white/5">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-gradient-to-br from-accent-500 to-brand-500 text-sm font-bold text-white ring-2 ring-white/20">{{ $initials }}</span>
                <span class="min-w-0 flex-1 leading-tight">
                    <span class="block truncate text-sm font-semibold">{{ $u->name }}</span>
                    <span class="block truncate text-xs text-slate-400">{{ $roleLabel }}{{ $u->department ? ' · '.$u->department->name : '' }}</span>
                </span>
                <x-icon name="chevron-down" class="h-4 w-4 text-slate-400" />
            </summary>
            <div class="absolute inset-x-0 bottom-full mb-2 overflow-hidden rounded-xl bg-white py-1 text-sm text-slate-700 shadow-xl">
                <a href="{{ route('password.edit') }}" class="flex items-center gap-2 px-4 py-2.5 hover:bg-slate-50"><x-icon name="key" class="h-4 w-4 text-slate-400" /> Change password</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="flex w-full items-center gap-2 px-4 py-2.5 text-rose-600 hover:bg-rose-50"><x-icon name="logout" class="h-4 w-4" /> Log out</button>
                </form>
            </div>
        </details>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        {{-- 顶部栏：搜索 + 通知 + 头像 --}}
        <header class="sticky top-0 z-30 border-b border-slate-200/70 bg-white/90 backdrop-blur">
            <div class="flex h-16 items-center gap-3 px-4 lg:h-[72px] lg:px-8">
                <button type="button" class="btn-ghost -ml-2 p-2 lg:hidden" onclick="toggleDrawer(true)" aria-label="Open menu"><x-icon name="menu" class="h-6 w-6" /></button>
                <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-2 lg:hidden">
                    <x-logo class="h-9" />
                </a>

                <form method="GET" action="{{ route('claims.index') }}" class="relative hidden max-w-md flex-1 lg:block">
                    <x-icon name="search" class="pointer-events-none absolute top-1/2 left-4 h-5 w-5 -translate-y-1/2 text-slate-400" />
                    <input name="q" value="{{ request()->routeIs('claims.index') ? request('q') : '' }}" class="w-full rounded-xl border-0 bg-slate-100/80 py-2.5 pr-4 pl-11 text-sm text-slate-900 placeholder:text-slate-400 focus:bg-white focus:ring-4 focus:ring-brand-100 focus:outline-none" placeholder="Search claim ID, merchant, or date…">
                </form>

                <div class="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
                    <details class="relative" data-dropdown>
                        <summary class="relative grid h-10 w-10 cursor-pointer list-none place-items-center rounded-full text-slate-500 hover:bg-slate-100 hover:text-slate-800" aria-label="Notifications">
                            <x-icon name="bell" class="h-6 w-6" />
                            @if ($notifications)<span class="absolute top-2 right-2.5 h-2.5 w-2.5 rounded-full bg-rose-500 ring-2 ring-white"></span>@endif
                        </summary>
                        <div class="absolute right-0 mt-2 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl">
                            <div class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-900">Notifications</div>
                            @forelse ($notifications as [$href, $icon, $tone, $text])
                                <a href="{{ $href }}" class="flex items-center gap-3 px-4 py-3 text-sm hover:bg-slate-50">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl {{ $tone }}"><x-icon :name="$icon" class="h-5 w-5" /></span>
                                    <span class="text-slate-700">{{ $text }}</span>
                                </a>
                            @empty
                                <div class="px-4 py-8 text-center text-sm text-slate-500">You're all caught up.</div>
                            @endforelse
                        </div>
                    </details>
                    <button type="button" onclick="toggleDrawer(true)" class="grid h-10 w-10 place-items-center rounded-full bg-gradient-to-br from-accent-500 to-brand-500 text-sm font-bold text-white lg:pointer-events-none" title="{{ $u->name }}">{{ $initials }}</button>
                </div>
            </div>
        </header>

        <main class="mx-auto w-full max-w-7xl flex-1 px-4 pt-5 pb-28 lg:px-8 lg:pt-8 lg:pb-10">
            {{-- 页面标题 --}}
            <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-[28px]">
                        @hasSection('heading') @yield('heading') @else @yield('title', 'Dashboard') @endif
                    </h1>
                    @hasSection('subtitle')<p class="mt-1 text-sm text-slate-500">@yield('subtitle')</p>@endif
                </div>
                @hasSection('actions')<div class="flex shrink-0 flex-wrap items-center gap-2">@yield('actions')</div>@endif
            </div>

            {{-- flash 提示和表单错误 --}}
            @if (session('success'))
                <div class="alert border-brand-200 bg-brand-50 text-brand-900" data-autohide>
                    <x-icon name="check" class="mt-0.5 h-5 w-5 text-brand-600" /><div class="flex-1">{{ session('success') }}</div>
                </div>
            @endif
            @if (session('error'))
                <div class="alert border-rose-200 bg-rose-50 text-rose-800">
                    <x-icon name="exclamation" class="mt-0.5 h-5 w-5 text-rose-600" /><div class="flex-1">{{ session('error') }}</div>
                </div>
            @endif
            @foreach ((array) session('warnings', []) as $warning)
                <div class="alert border-amber-200 bg-amber-50 text-amber-900">
                    <x-icon name="exclamation" class="mt-0.5 h-5 w-5 text-amber-600" /><div class="flex-1">{{ $warning }}</div>
                </div>
            @endforeach
            @if ($errors->any())
                <div class="alert border-rose-200 bg-rose-50 text-rose-800">
                    <x-icon name="exclamation" class="mt-0.5 h-5 w-5 text-rose-600" />
                    <div class="flex-1">
                        <div class="font-semibold">Please fix the following:</div>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>

{{-- 手机底部导航 --}}
<nav class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden">
    <div class="grid grid-cols-4 text-[11px] font-medium">
        @php
            // 有审批权限的显示 Approvals，没有的显示 New
            $tabs = [
                ['Home', 'dashboard', ['dashboard'], 'home', 0],
                ['Claims', 'claims.index', ['claims.*'], 'receipt', 0],
                $canApprove ? ['Approvals', 'approvals.index', ['approvals.*'], 'check-badge', $toApprove]
                    : ['New', 'claims.create', ['claims.create'], 'plus-circle', 0],
            ];
        @endphp
        @foreach ($tabs as [$label, $route, $patterns, $icon, $count])
            @php $active = request()->routeIs(...$patterns) && ! ($label === 'Claims' && request()->routeIs('claims.create')); @endphp
            <a href="{{ route($route) }}" class="relative flex flex-col items-center gap-1 py-2.5 {{ $active ? 'text-brand-700' : 'text-slate-500' }}">
                <x-icon :name="$icon" class="h-6 w-6" />
                {{ $label }}
                @if ($count)<span class="absolute top-1 right-1/4 grid h-4 min-w-4 place-items-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white">{{ $count }}</span>@endif
            </a>
        @endforeach
        <button type="button" onclick="toggleDrawer(true)" class="flex flex-col items-center gap-1 py-2.5 text-slate-500">
            <x-icon name="dots" class="h-6 w-6 rotate-90" />
            More
        </button>
    </div>
</nav>

{{-- 全屏 loading 遮罩，带 data-loading="提示文字" 的表单提交时显示（例如上传收据跑 OCR） --}}
<div id="loading-overlay" class="fixed inset-0 z-[60] hidden place-items-center bg-navy-950/60 backdrop-blur-sm">
    <div class="mx-4 w-full max-w-sm rounded-2xl bg-white p-6 text-center shadow-xl">
        <div class="mx-auto h-12 w-12 animate-spin rounded-full border-4 border-brand-100 border-t-brand-500"></div>
        <div id="loading-text" class="mt-4 font-semibold text-slate-900">Please wait…</div>
        <div class="mt-1 text-sm text-slate-500">This can take up to 10 seconds per receipt. Please don't close the page.</div>
    </div>
</div>

<script>
    // 手机抽屉菜单开关，电脑版 sidebar 固定显示所以直接跳过
    function toggleDrawer(open) {
        if (window.matchMedia('(min-width: 1024px)').matches) return;
        document.getElementById('sidebar').classList.toggle('-translate-x-full', !open);
        document.getElementById('drawer-backdrop').classList.toggle('hidden', !open);
    }
    document.querySelectorAll('form[data-loading]').forEach((form) => {
        form.addEventListener('submit', () => {
            document.getElementById('loading-text').textContent = form.dataset.loading || 'Please wait…';
            document.getElementById('loading-overlay').classList.replace('hidden', 'grid');
        });
    });
    // 成功提示 6 秒后自动消失
    document.querySelectorAll('[data-autohide]').forEach((el) => setTimeout(() => el.remove(), 6000));
    // 点击其他地方时关掉已展开的下拉菜单（<details data-dropdown>）
    document.addEventListener('click', (e) => {
        document.querySelectorAll('details[data-dropdown][open]').forEach((d) => { if (!d.contains(e.target)) d.open = false; });
    });
</script>
@stack('scripts')
</body>
</html>
