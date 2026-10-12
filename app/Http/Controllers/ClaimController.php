<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateClaimRequest;
use App\Http\Requests\UploadReceiptRequest;
use App\Models\Claim;
use App\Models\ClaimCategory;
use App\Models\Project;
use App\Models\User;
use App\Services\CategoryGuesser;
use App\Services\ClaimFilter;
use App\Services\ClaimService;
use App\Services\ReceiptTextHints;
use App\Services\ReceiptUploadHandler;
use Illuminate\Http\Request;

class ClaimController extends Controller
{
    public function __construct(private ClaimService $claims) {}

    public function index(Request $request, ClaimFilter $filter)
    {
        $filters = $filter->fromRequest($request);
        $claims = $filter->query($request->user(), $filters)->paginate(20)->withQueryString();

        // Counts for the status tabs: same filters, any status.
        $statusCounts = $filter->query($request->user(), array_diff_key($filters, ['status' => 1]))
            ->reorder()->toBase()->selectRaw('claims.status, COUNT(*) as n')->groupBy('claims.status')->pluck('n', 'status');

        return view('claims.index', [
            'claims' => $claims,
            'filters' => $filters,
            'statusCounts' => $statusCounts,
            'options' => $this->filterOptions($request->user()),
        ]);
    }

    public function create()
    {
        $this->authorize('create', Claim::class);

        return view('claims.create');
    }

    /**
     * Step 1: create a draft, store any uploaded receipts and run local OCR on them,
     * then send the user to the edit page to check the OCR text and confirm the details.
     */
    public function store(UploadReceiptRequest $request, ReceiptUploadHandler $uploads)
    {
        $this->authorize('create', Claim::class);

        $claim = $this->claims->createDraft($request->user());
        $warnings = $uploads->handle($claim, $request->file('receipts', []), $request->user());

        return redirect()->route('claims.edit', $claim)
            ->with('success', "Draft {$claim->claim_number} created. Check the details below, then save.")
            ->with('warnings', $warnings);
    }

    public function show(Claim $claim)
    {
        $this->authorize('view', $claim);
        $claim->load(['user.department', 'category', 'project', 'items.receipt', 'receipts.uploader', 'updatedBy', 'reviewer']);

        return view('claims.show', compact('claim'));
    }

    public function edit(Claim $claim, ReceiptTextHints $hints)
    {
        // A claim the user may see but no longer change (submitted / approved, e.g. reached with the browser's
        // Back button after submitting): show it instead of a 403. Users who may not see it still get 403.
        $user = request()->user();
        if ($user->cannot('update', $claim) && $user->can('view', $claim)) {
            return redirect()->route('claims.show', $claim)->with('success', match (true) {
                $claim->isSubmitted() => 'This claim has been submitted and is locked while it is being reviewed.',
                $claim->isApproved() => 'This claim is approved and can no longer be changed.',
                default => 'You can view this claim, but not change it.',
            });
        }

        $this->authorize('update', $claim);
        $claim->load(['user', 'items', 'receipts', 'category', 'project']);
        $receiptHints = $claim->receipts->mapWithKeys(fn ($r) => [$r->id => $hints->find($r->ocr_text)]);

        return view('claims.edit', [
            'claim' => $claim,
            'categories' => ClaimCategory::query()->where('is_active', true)->orWhere('id', $claim->category_id)->orderBy('name')->get(),
            'projects' => Project::query()->where('is_active', true)->orWhere('id', $claim->project_id)->orderBy('name')->get(),
            'hints' => $receiptHints,
            'prefill' => $prefill = $this->prefillFromOcr($claim, $receiptHints, request()->boolean('refill')),
            'ocrSuggestsRefill' => $this->ocrSuggestsRefill($claim, $receiptHints, $prefill),
        ]);
    }

    /**
     * True when the saved draft items do NOT add up to the receipt, but the OCR reading does —
     * e.g. the draft was saved before an add-on / tax line was recognised. The form then offers "Re-fill from OCR".
     */
    private function ocrSuggestsRefill(Claim $claim, $receiptHints, array $prefill): bool
    {
        if (! $claim->isDraft() || isset($prefill['items']) || $claim->items->isEmpty()) {
            return false;
        }

        $matching = $receiptHints->first(fn ($h) => $h['items_match_total']);
        if ($matching === null) {
            return false;
        }

        return (int) round((float) $claim->total_amount * 100) !== (int) round((float) end($matching['totals']) * 100);
    }

    /**
     * Values found in the OCR text, used to pre-fill only the fields that are still empty —
     * or, with $refill ("Re-fill from OCR" button), every field OCR found a value for, replacing what was saved.
     * They are shown highlighted in the form and are NOT saved until the user clicks Save / Record.
     */
    private function prefillFromOcr(Claim $claim, $receiptHints, bool $refill = false): array
    {
        if (! $claim->isDraft()) {
            return [];
        }

        $first = fn (string $key) => $receiptHints->pluck($key)->flatten(1)->first();
        $date = $first('dates');
        $total = $receiptHints->pluck('totals')->map(fn ($t) => end($t) ?: null)->filter()->first(); // last TOTAL line on a receipt is the final one
        $empty = fn ($value) => $refill || $value === null;

        $prefill = array_filter([
            'merchant_name' => $empty($claim->merchant_name) ? $first('merchants') : null,
            'location' => $empty($claim->location) ? $first('locations') : null,
            'expense_date' => $empty($claim->expense_date) ? ($date['iso'] ?? null) : null,
            'expense_time' => $empty($claim->expense_time) ? $first('times') : null,
            'receipt_total_amount' => $empty($claim->receipt_total_amount) ? $total : null,
        ]);

        // Category: same merchant's past category, else keywords on the receipt (Settings → Claim Categories).
        if ($empty($claim->category_id) && $claim->receipts->contains('ocr_status', 'completed')) {
            $guess = app(CategoryGuesser::class)->guess(
                $prefill['merchant_name'] ?? $claim->merchant_name,
                $claim->receipts->pluck('ocr_text')->filter()->implode("\n"),
            );
            if ($guess) {
                $prefill['category_id'] = $guess['category_id'];
                $prefill['category_reason'] = $guess['reason'];
            }
        }

        if (! $refill && $claim->items->isNotEmpty()) {
            return $prefill;
        }

        // Items read from the receipt lines (only those receipts' own items, tagged with their receipt).
        $items = $receiptHints->flatMap(fn ($h, $receiptId) => collect($h['items'])->map(fn ($i) => $i + [
            'unit' => null,
            'discount_amount' => '',
            'receipt_id' => $receiptId,
            '_ocr' => true,
        ]))->values()->all();

        if ($items !== []) {
            $prefill['items'] = $items;
        } elseif ($total !== null) {
            // Items not readable: one "total only" line so the claim total matches the receipt; the user can split it.
            $receiptId = $receiptHints->search(fn ($h) => in_array($total, $h['totals'], true));
            $prefill['items'] = [[
                'item_name' => $first('merchants') ? 'Receipt total — '.$first('merchants') : 'Receipt total',
                'quantity' => '1',
                'unit' => null,
                'unit_price' => null,
                'amount' => $total,
                'discount_amount' => '',
                'tax_amount' => '',
                'receipt_id' => $receiptId ?: null,
                '_ocr' => true,
            ]];
        }

        return $prefill;
    }

    public function update(UpdateClaimRequest $request, Claim $claim)
    {
        $wasEditable = $claim->isEditableByOwner();
        $claim = $this->claims->save($claim, $request->claimData(), $request->items(), $request->user(), $request->isSubmitting());

        if ($request->input('action') === 'draft' && $claim->isEditableByOwner()) {
            return redirect()->route('claims.edit', $claim)->with('success', 'Saved. Click "Submit for approval" when it is ready.');
        }

        return redirect()->route('claims.show', $claim)
            ->with('success', $wasEditable ? "Claim {$claim->claim_number} submitted for approval." : 'Claim updated.');
    }

    public function destroy(Claim $claim)
    {
        $this->authorize('delete', $claim);
        $this->claims->delete($claim);

        return redirect()->route('claims.index')->with('success', "Claim {$claim->claim_number} deleted.");
    }

    /** Dropdown options limited to what the user is allowed to see. */
    private function filterOptions(User $user): array
    {
        $visibleUserIds = Claim::query()->visibleTo($user)->select('user_id')->distinct();

        return [
            'categories' => ClaimCategory::orderBy('name')->get(['id', 'name', 'is_active']),
            'projects' => Project::orderBy('name')->get(['id', 'name', 'project_code']),
            'users' => User::whereIn('id', $visibleUserIds)->orWhere('id', $user->id)->orderBy('name')->get(['id', 'name']),
        ];
    }
}
