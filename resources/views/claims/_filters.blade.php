{{-- Shared by Claims list and Reports. $action = form URL, $options = dropdown data, $filters = current values.
     The search box is always visible; the other filters fold away until needed. --}}
@php
    $advancedKeys = ['date_from', 'date_to', 'category_id', 'project_id', 'user_id', 'department_id', 'merchant', 'amount_min', 'amount_max'];
    $activeAdvanced = count(array_intersect_key($filters, array_flip($advancedKeys)));
    $statusTabs = request()->routeIs('claims.index'); // the claims page has status tabs instead of a status dropdown
@endphp
<form method="GET" action="{{ $action }}" class="{{ ($bare ?? false) ? 'p-4 sm:px-5' : 'card mb-5 p-3 sm:p-4' }}">
    <div class="flex gap-2">
        <div class="relative min-w-0 flex-1">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 h-5 w-5 -translate-y-1/2 text-slate-400" />
            <input name="q" value="{{ $filters['q'] ?? '' }}" class="input pr-11 pl-11" placeholder="Search by claim ID, merchant, purpose…">
            <button class="absolute top-1/2 right-1.5 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-brand-600" aria-label="Search"><x-icon name="arrow-right" class="h-4 w-4" /></button>
        </div>
        <button type="button" class="btn-secondary shrink-0" onclick="document.getElementById('more-filters').toggleAttribute('hidden')">
            <x-icon name="funnel" class="h-5 w-5" /> <span class="hidden sm:inline">Filter</span>
            @if ($activeAdvanced)<span class="rounded-full bg-brand-600 px-1.5 text-xs text-white">{{ $activeAdvanced }}</span>@endif
        </button>
    </div>

    <div id="more-filters" class="mt-4 border-t border-slate-100 pt-4" @if (! $activeAdvanced && ! (isset($filters['status']) && ! $statusTabs)) hidden @endif>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label class="label">Date from</label>
                <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="input">
            </div>
            <div>
                <label class="label">Date to</label>
                <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="input">
            </div>
            <div>
                <label class="label">Category</label>
                <select name="category_id" class="input">
                    <option value="">All</option>
                    @foreach ($options['categories'] as $o)
                        <option value="{{ $o->id }}" @selected(($filters['category_id'] ?? '') == $o->id)>{{ $o->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label">Project</label>
                <select name="project_id" class="input">
                    <option value="">All</option>
                    @foreach ($options['projects'] as $o)
                        <option value="{{ $o->id }}" @selected(($filters['project_id'] ?? '') == $o->id)>{{ $o->label }}</option>
                    @endforeach
                </select>
            </div>
            @if ($options['users']->count() > 1)
                <div>
                    <label class="label">Employee</label>
                    <select name="user_id" class="input">
                        <option value="">All</option>
                        @foreach ($options['users'] as $o)
                            <option value="{{ $o->id }}" @selected(($filters['user_id'] ?? '') == $o->id)>{{ $o->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @isset($options['departments'])
                <div>
                    <label class="label">Department</label>
                    <select name="department_id" class="input">
                        <option value="">All</option>
                        @foreach ($options['departments'] as $o)
                            <option value="{{ $o->id }}" @selected(($filters['department_id'] ?? '') == $o->id)>{{ $o->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endisset
            <div>
                <label class="label">Merchant</label>
                <input name="merchant" value="{{ $filters['merchant'] ?? '' }}" class="input">
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="label">Min RM</label>
                    <input type="number" step="0.01" min="0" name="amount_min" value="{{ $filters['amount_min'] ?? '' }}" class="input">
                </div>
                <div>
                    <label class="label">Max RM</label>
                    <input type="number" step="0.01" min="0" name="amount_max" value="{{ $filters['amount_max'] ?? '' }}" class="input">
                </div>
            </div>
            @unless ($statusTabs)
                <div>
                    <label class="label">Status</label>
                    <select name="status" class="input">
                        <option value="">All</option>
                        @foreach (\App\Models\Claim::STATUSES as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endunless
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <button class="btn-primary">Apply filters</button>
            <a href="{{ $action }}" class="btn-ghost">Clear all</a>
        </div>
    </div>

    @if ($statusTabs && isset($filters['status']))<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
    <input type="hidden" name="sort" value="{{ $filters['sort'] ?? '' }}">
    <input type="hidden" name="dir" value="{{ $filters['dir'] ?? '' }}">
    @isset($extraButtons)<div class="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3">{{ $extraButtons }}</div>@endisset
</form>
