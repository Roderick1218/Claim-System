<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Claim extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted'; // waiting for approval, locked for the owner

    public const STATUS_APPROVED = 'approved';   // final, locked for everyone

    public const STATUS_REJECTED = 'rejected';   // owner may edit and resubmit

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_SUBMITTED => 'Pending approval',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_REJECTED => 'Rejected',
    ];

    // Ownership, numbering and amounts are set by the backend only — never mass-assigned from a request.
    protected $fillable = [
        'category_id', 'project_id', 'merchant_name', 'expense_date', 'expense_time',
        'location', 'business_purpose', 'receipt_total_amount', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'receipt_total_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ClaimCategory::class, 'category_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClaimItem::class)->orderBy('id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class)->orderBy('id');
    }

    /**
     * The single source of truth for "which claims may this user see".
     * Used by the list, dashboard, reports and CSV export alike.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasPermission(Permission::CLAIMS_VIEW_ALL)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('claims.user_id', $user->id);

            if ($user->department_id && $user->hasPermission(Permission::CLAIMS_VIEW_DEPARTMENT)) {
                $q->orWhereIn('claims.user_id', User::query()
                    ->select('id')
                    ->where('department_id', $user->department_id));
            }
        });
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** Draft or rejected: the owner can still change it and (re)submit. */
    public function isEditableByOwner(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true);
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    /** Receipt total minus calculated total; null when the user has not confirmed a receipt total. */
    public function getDifferenceAmountAttribute(): ?string
    {
        if ($this->receipt_total_amount === null) {
            return null;
        }

        $cents = (int) round(((float) $this->receipt_total_amount) * 100) - (int) round(((float) $this->total_amount) * 100);

        return number_format($cents / 100, 2, '.', '');
    }
}
