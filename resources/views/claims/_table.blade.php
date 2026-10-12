{{-- Claims as a table on wide screens, as tappable rows on phones. $bare = already inside a card. --}}
@php $showOwnerCol = $claims->contains(fn ($c) => $c->user_id !== auth()->id()); @endphp
<div class="{{ ($bare ?? false) ? 'border-t border-slate-100' : 'card' }}">
    @if ($claims->isEmpty())
        <div class="px-5 py-14 text-center">
            <div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-slate-100 text-slate-400"><x-icon name="inbox" class="h-7 w-7" /></div>
            <p class="mt-3 font-semibold text-slate-900">No claims found</p>
            <p class="text-sm text-slate-500">Try another filter, or create a new claim.</p>
        </div>
    @else
        {{-- Phones --}}
        <div class="divide-y divide-slate-100 md:hidden">
            @foreach ($claims as $c)
                @include('claims._row', ['c' => $c, 'showOwner' => $c->user_id !== auth()->id()])
            @endforeach
        </div>

        {{-- Tablets / desktop --}}
        <div class="hidden md:block">
            <table class="table">
                <thead>
                <tr>
                    <th class="pl-5">@include('claims._sort', ['column' => 'claim_number', 'label' => 'Claim ID'])</th>
                    <th>@include('claims._sort', ['column' => 'expense_date', 'label' => 'Date'])</th>
                    <th>@include('claims._sort', ['column' => 'merchant_name', 'label' => 'Merchant'])</th>
                    @if ($showOwnerCol)<th>Submitted by</th>@endif
                    <th class="text-right">@include('claims._sort', ['column' => 'total_amount', 'label' => 'Amount'])</th>
                    <th>Status</th>
                    <th class="pr-5 text-right">Action</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($claims as $c)
                    <tr class="cursor-pointer hover:bg-slate-50/80" onclick="if (!event.target.closest('a,details,button')) location.href='{{ route('claims.show', $c) }}'">
                        <td class="pl-5 font-medium whitespace-nowrap text-slate-700"><a href="{{ route('claims.show', $c) }}" class="hover:text-brand-700">{{ $c->claim_number }}</a></td>
                        <td class="whitespace-nowrap text-slate-600">{{ $c->expense_date?->format('d M Y') ?? '—' }}</td>
                        <td class="max-w-64">
                            <div class="truncate font-medium text-slate-900">{{ $c->merchant_name ?? '—' }}</div>
                            <div class="truncate text-xs text-slate-400">{{ collect([$c->category?->name, $c->project?->name])->filter()->implode(' · ') }}</div>
                        </td>
                        @if ($showOwnerCol)<td class="text-slate-700">{{ $c->user?->name }}<div class="text-xs text-slate-400">{{ $c->user?->department?->name }}</div></td>@endif
                        <td class="num text-right font-semibold whitespace-nowrap text-slate-900">RM {{ number_format((float) $c->total_amount, 2) }}</td>
                        <td>@include('claims._status', ['status' => $c->status])</td>
                        <td class="pr-5 text-right">
                            <details class="relative inline-block text-left" data-dropdown>
                                <summary class="grid h-8 w-8 cursor-pointer list-none place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Actions"><x-icon name="dots" class="h-5 w-5" /></summary>
                                <div class="absolute right-0 z-20 mt-1 w-40 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 text-sm shadow-lg">
                                    <a href="{{ route('claims.show', $c) }}" class="flex items-center gap-2 px-3 py-2 text-slate-700 hover:bg-slate-50"><x-icon name="eye" class="h-4 w-4 text-slate-400" /> View</a>
                                    @can('update', $c)
                                        <a href="{{ route('claims.edit', $c) }}" class="flex items-center gap-2 px-3 py-2 text-slate-700 hover:bg-slate-50"><x-icon name="pencil" class="h-4 w-4 text-slate-400" /> Edit</a>
                                    @endcan
                                    @can('review', $c)
                                        <a href="{{ route('claims.show', $c) }}" class="flex items-center gap-2 px-3 py-2 text-brand-700 hover:bg-brand-50"><x-icon name="check-badge" class="h-4 w-4" /> Review</a>
                                    @endcan
                                </div>
                            </details>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($claims->hasPages())
        <div class="border-t border-slate-100 px-4 py-3">{{ $claims->links() }}</div>
    @endif
</div>
