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

        // 状态 tab 上的数字：沿用同一组筛选条件，只是不限 status，否则切 tab 时其他 tab 的数字会变 0
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
     * 第一步：先开一张 draft，存好上传的收据并跑本地 OCR，
     * 再带用户去 edit 页面核对 OCR 结果、补齐资料。
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
        // 常见情况：submit 之后按浏览器 Back 又回到 edit 页。看得到但已经不能改的 claim（submitted / approved）
        // 直接转去详情页，比丢一个 403 友好。本来就没权限看的人照样 403。
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
     * 已存的 draft item 加起来对不上收据总额，但 OCR 读出来的对得上时返回 true。
     * 一般是 draft 存得比较早，那时 add-on / 税那几行还没认出来。表单会据此显示 "Re-fill from OCR"。
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
     * 从 OCR 文字里抽出来的值，默认只填还空着的栏位；
     * $refill 为 true（按了 "Re-fill from OCR"）时，OCR 有读到的栏位全部覆盖掉已存的值。
     * 这些值在表单里会高亮，用户按 Save / Record 之前都不会写进数据库。
     */
    private function prefillFromOcr(Claim $claim, $receiptHints, bool $refill = false): array
    {
        if (! $claim->isDraft()) {
            return [];
        }

        $first = fn (string $key) => $receiptHints->pluck($key)->flatten(1)->first();
        $date = $first('dates');
        $total = $receiptHints->pluck('totals')->map(fn ($t) => end($t) ?: null)->filter()->first(); // 收据上可能有几行 TOTAL，最后一行才是实付
        $empty = fn ($value) => $refill || $value === null;

        $prefill = array_filter([
            'merchant_name' => $empty($claim->merchant_name) ? $first('merchants') : null,
            'location' => $empty($claim->location) ? $first('locations') : null,
            'expense_date' => $empty($claim->expense_date) ? ($date['iso'] ?? null) : null,
            'expense_time' => $empty($claim->expense_time) ? $first('times') : null,
            'receipt_total_amount' => $empty($claim->receipt_total_amount) ? $total : null,
        ]);

        // Category 先看同一商家以前报过什么，没有的话再用收据上的关键词猜（关键词在 Settings → Claim Categories 维护）
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

        // 每张收据读到的 item 各自挂上 receipt_id，之后才知道哪一行来自哪张收据
        $items = $receiptHints->flatMap(fn ($h, $receiptId) => collect($h['items'])->map(fn ($i) => $i + [
            'unit' => null,
            'discount_amount' => '',
            'receipt_id' => $receiptId,
            '_ocr' => true,
        ]))->values()->all();

        if ($items !== []) {
            $prefill['items'] = $items;
        } elseif ($total !== null) {
            // 读不出明细就先放一行总额，至少 claim 金额跟收据对得上，用户要细分再自己拆
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

    /** 筛选下拉的员工名单只列出看得到的人，免得从下拉里看到别部门的同事 */
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
