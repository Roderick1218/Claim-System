<?php

namespace App\Services;

use App\Models\Claim;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ClaimService
{
    public function __construct(
        private ClaimNumberService $numbers,
        private ClaimCalculationService $calculator,
        private AuditLogger $audit,
    ) {}

    // 先开一张空的 draft，收据上传和 OCR 都要挂在一张单下面
    public function createDraft(User $owner, array $attributes = []): Claim
    {
        return DB::transaction(function () use ($owner, $attributes) {
            $claim = new Claim($attributes);
            $claim->user_id = $owner->id;
            $claim->claim_number = $this->numbers->next();
            $claim->currency = config('claims.currency');
            $claim->status = Claim::STATUS_DRAFT;
            $claim->updated_by = $owner->id;
            $claim->save();

            $this->audit->log('claim.created', 'claim', $claim->id, null, $this->snapshot($claim));

            return $claim;
        });
    }

    /**
     * 保存表头和明细。金额一律在这里重新计算，浏览器传来的合计不采用，免得前端 JS 被改了金额就跟着变。
     *
     * @param  array  $data  验证过的表头字段
     * @param  array  $items  验证过的明细行
     */
    public function save(Claim $claim, array $data, array $items, User $actor, bool $submit): Claim
    {
        return DB::transaction(function () use ($claim, $data, $items, $actor, $submit) {
            // 重新读取并锁行，防止同时有人在审批或另一个窗口也在保存
            $claim = Claim::query()->lockForUpdate()->findOrFail($claim->id);
            $before = $this->snapshot($claim->load('items'));

            $lines = [];
            foreach ($items as $row) {
                $calc = $this->calculator->calculateLine($row);
                $lines[] = $calc + [
                    'item_name' => $row['item_name'] ?? null,
                    'unit' => $row['unit'] ?? null,
                    'receipt_id' => $row['receipt_id'] ?? null,
                ];
            }

            $claim->fill($data);
            // 金额故意不在 fillable 里，只能由 calculator 的结果 forceFill 进去
            $claim->forceFill($this->calculator->calculateTotals($lines));
            $claim->updated_by = $actor->id;

            // 已 submitted 的单被 manage_all 修改时不算重新提交，状态不变
            $submitting = $submit && $claim->isEditableByOwner();
            if ($submitting) {
                // 重新提交时清掉上次的审批结果，被 reject 的记录留在 audit log 里查得到
                $claim->forceFill([
                    'status' => Claim::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_note' => null,
                ]);
            }

            $claim->save();

            // 明细整批删掉重建，比逐行对比更新简单，反正前后差异会记进 audit log
            $claim->items()->delete();
            foreach ($lines as $line) {
                unset($line['gross']); // gross 只用来算 subtotal，表里没有这个字段
                $claim->items()->create($line);
            }

            $after = $this->snapshot($claim->load('items'));
            [$old, $new] = $this->audit->diff($before, $after);
            if ($new !== []) {
                $this->audit->log('claim.updated', 'claim', $claim->id, $old, $new);
            }
            if ($submitting) {
                $this->audit->log('claim.submitted', 'claim', $claim->id, ['status' => $before['status']], ['status' => Claim::STATUS_SUBMITTED]);
            }

            return $claim;
        });
    }

    // approve 时备注可选，reject 时必须写原因（controller 那边验证）。
    // 锁行后再确认一次是 submitted，避免两个审批人同时操作，或者审批途中单被改掉
    public function review(Claim $claim, User $reviewer, bool $approve, ?string $note): Claim
    {
        return DB::transaction(function () use ($claim, $reviewer, $approve, $note) {
            $claim = Claim::query()->lockForUpdate()->findOrFail($claim->id);
            if (! $claim->isSubmitted()) {
                throw new \DomainException('This claim is no longer waiting for approval.');
            }

            $status = $approve ? Claim::STATUS_APPROVED : Claim::STATUS_REJECTED;
            $claim->forceFill([
                'status' => $status,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ])->save();

            $this->audit->log($approve ? 'claim.approved' : 'claim.rejected', 'claim', $claim->id,
                ['status' => Claim::STATUS_SUBMITTED],
                ['status' => $status, 'review_note' => $claim->review_note, 'total_amount' => $claim->total_amount]);

            return $claim;
        });
    }

    public function delete(Claim $claim): void
    {
        DB::transaction(function () use ($claim) {
            $this->audit->log('claim.deleted', 'claim', $claim->id, $this->snapshot($claim->load('items')), null);
            $claim->delete(); // 软删除，收据和明细都保留，方便日后查账
        });
    }

    // 给 audit log 用的快照，日期时间统一格式化，前后对比时不会因为格式不同被当成改动
    private function snapshot(Claim $claim): array
    {
        $data = $claim->only([
            'claim_number', 'user_id', 'category_id', 'project_id', 'merchant_name', 'location',
            'business_purpose', 'currency', 'subtotal_amount', 'discount_amount', 'tax_amount',
            'total_amount', 'receipt_total_amount', 'notes', 'status',
        ]);
        $data['expense_date'] = $claim->expense_date?->format('Y-m-d');
        $data['expense_time'] = $claim->expense_time ? substr((string) $claim->expense_time, 0, 5) : null;

        if ($claim->relationLoaded('items')) {
            $data['items'] = $claim->items->map(fn ($i) => $i->only([
                'item_name', 'quantity', 'unit', 'unit_price', 'discount_amount', 'tax_amount', 'line_total', 'receipt_id',
            ]))->all();
        }

        return $data;
    }
}
