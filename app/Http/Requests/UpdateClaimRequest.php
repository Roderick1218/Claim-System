<?php

namespace App\Http\Requests;

use App\Models\Claim;
use App\Models\ClaimCategory;
use App\Services\ClaimCalculationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

// 保存草稿时放宽验证（允许先存一半），提交审批时才要求必填项齐全
class UpdateClaimRequest extends FormRequest
{
    private const MONEY = ['nullable', 'decimal:0,2', 'min:0', 'max:9999999.99'];

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('claim'));
    }

    // 提交审批时要完整验证；有 manage_all 的人修改已 submitted 的单也一样，不能改成残缺的单
    public function isSubmitting(): bool
    {
        return $this->input('action') === 'submit' || $this->claim()->status === Claim::STATUS_SUBMITTED;
    }

    protected function prepareForValidation(): void
    {
        // 用户加了行却什么都没填的直接丢掉（quantity 有默认值、receipt_id 是下拉，不算有填）
        $items = collect($this->input('items', []))
            ->filter(fn ($row) => is_array($row) && collect($row)->except(['receipt_id', 'quantity'])->filter(fn ($v) => trim((string) $v) !== '')->isNotEmpty())
            ->values()
            ->all();

        $this->merge(['items' => $items]);
    }

    public function rules(): array
    {
        $claim = $this->claim();
        $submitting = $this->isSubmitting();

        // 已停用的类别 / 项目不能新选，但旧单原本就用着的可以保留，不然旧单会存不了
        $activeOrCurrent = fn (string $table, ?int $currentId) => Rule::exists($table, 'id')->where(
            fn ($q) => $q->where('is_active', true)->when($currentId, fn ($q) => $q->orWhere('id', $currentId))
        );

        return [
            'action' => ['required', Rule::in(['draft', 'submit'])],
            'category_id' => [$submitting ? 'required' : 'nullable', 'integer', $activeOrCurrent('claim_categories', $claim->category_id)],
            'project_id' => ['nullable', 'integer', $activeOrCurrent('projects', $claim->project_id)],
            'merchant_name' => ['nullable', 'string', 'max:255'],
            'expense_date' => [$submitting ? 'required' : 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->addDay()->format('Y-m-d')],
            'expense_time' => ['nullable', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:255'],
            'business_purpose' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'receipt_total_amount' => self::MONEY,

            'items' => [$submitting ? 'required' : 'nullable', 'array', $submitting ? 'min:1' : 'min:0', 'max:100'],
            'items.*.item_name' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'decimal:0,3', 'gt:0', 'max:999999'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_price' => self::MONEY,
            'items.*.amount' => [...self::MONEY, 'required_without:items.*.unit_price'],
            'items.*.discount_amount' => self::MONEY,
            'items.*.tax_amount' => self::MONEY,
            'items.*.receipt_id' => ['nullable', 'integer', Rule::exists('receipts', 'id')->where('claim_id', $claim->id)],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Add at least one expense item before submitting the claim.',
            'items.min' => 'Add at least one expense item before submitting the claim.',
            'items.*.amount.required_without' => 'Each item needs a unit price, or an amount if the receipt shows only a total.',
            'category_id.required' => 'Choose a claim category before submitting.',
            'expense_date.required' => 'Enter the expense date before submitting.',
        ];
    }

    public function attributes(): array
    {
        return [
            'items.*.quantity' => 'quantity',
            'items.*.unit_price' => 'unit price',
            'items.*.amount' => 'amount',
            'items.*.discount_amount' => 'discount',
            'items.*.tax_amount' => 'tax',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // 单行可以是负数（收据上常见 "Rounding Adj -0.02" 或 "Diskaun" 折扣行），
                // 但整张单的总额不能是负的。错误挂在最后一条负数行上，用户比较容易找到
                $calculator = app(ClaimCalculationService::class);
                $lines = [];
                $lastNegative = null;
                foreach ($this->input('items', []) as $i => $row) {
                    $lines[] = $line = $calculator->calculateLine($row);
                    if (str_starts_with($line['line_total'], '-')) {
                        $lastNegative = $i;
                    }
                }
                if ($lastNegative !== null && str_starts_with($calculator->calculateTotals($lines)['total_amount'], '-')) {
                    $validator->errors()->add("items.{$lastNegative}.discount_amount", 'Item '.($lastNegative + 1).': the discount makes the claim total negative.');
                }

                if ($this->isSubmitting() && $this->filled('category_id')) {
                    $category = ClaimCategory::find($this->input('category_id'));
                    if ($category?->requires_receipt && ! $this->claim()->receipts()->exists()) {
                        $validator->errors()->add('category_id', "The category \"{$category->name}\" requires a receipt. Upload one, or save as draft for now.");
                    }
                }
            },
        ];
    }

    // 请求里没带的字段补成 null，每次保存都完整覆盖这几个表头栏位
    public function claimData(): array
    {
        return $this->safe()->only([
            'category_id', 'project_id', 'merchant_name', 'expense_date', 'expense_time',
            'location', 'business_purpose', 'receipt_total_amount', 'notes',
        ]) + array_fill_keys(['category_id', 'project_id', 'merchant_name', 'expense_date', 'expense_time', 'location', 'business_purpose', 'receipt_total_amount', 'notes'], null);
    }

    public function items(): array
    {
        return $this->validated('items') ?? [];
    }

    private function claim(): Claim
    {
        return $this->route('claim');
    }
}
