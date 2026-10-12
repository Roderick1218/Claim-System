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

    /** Creates an empty draft owned by $owner (used before receipts are uploaded / OCR'd). */
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
     * Saves header fields + items. Amounts are always recalculated here; any totals sent by the browser are ignored.
     *
     * @param  array  $data  validated claim fields
     * @param  array  $items  validated item rows
     */
    public function save(Claim $claim, array $data, array $items, User $actor, bool $submit): Claim
    {
        return DB::transaction(function () use ($claim, $data, $items, $actor, $submit) {
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
            // Amounts are deliberately not mass-assignable; only the calculator may set them.
            $claim->forceFill($this->calculator->calculateTotals($lines));
            $claim->updated_by = $actor->id;

            $submitting = $submit && $claim->isEditableByOwner();
            if ($submitting) {
                // (Re)submission: the previous rejection stays in the audit log, not on the claim.
                $claim->forceFill([
                    'status' => Claim::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_note' => null,
                ]);
            }

            $claim->save();

            $claim->items()->delete();
            foreach ($lines as $line) {
                unset($line['gross']);
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

    /** Approve (optional remark) or reject (reason required). Only submitted claims; checked again under a row lock. */
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
            $claim->delete(); // soft delete — receipts and items are kept
        });
    }

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
