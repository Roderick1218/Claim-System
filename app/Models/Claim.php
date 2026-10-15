<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// 报销单主表，一张单对应多条明细（claim_items）和多张收据（receipts）。
// 状态流转：draft → submitted → approved / rejected，rejected 之后可以改了再提交。
class Claim extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted'; // 等待审批，本人不能再改

    public const STATUS_APPROVED = 'approved';   // 最终状态，谁都不能改

    public const STATUS_REJECTED = 'rejected';   // 本人可以修改后重新提交

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_SUBMITTED => 'Pending approval',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_REJECTED => 'Rejected',
    ];

    // user_id、单号、状态和各种金额只能由后端写入，不放进 fillable，
    // 避免有人在表单里多塞一个字段就改了归属人或金额。金额统一由 ClaimCalculationService 算。
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

    // “这个用户能看哪些单”只在这里定义一次，列表、dashboard、报表和 CSV 导出都走这个 scope，
    // 以后改权限规则不会漏掉某个入口。公司规定默认只看自己的，只有 admin（claims.view_all）看全部。
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

    // draft 或 rejected 时本人还能改、能（重新）提交
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

    // 收据总额减去系统算出的总额，用来提醒对不上账；用户没填收据总额时返回 null。
    // 先换成整数 cent 再相减，避免浮点误差。
    public function getDifferenceAmountAttribute(): ?string
    {
        if ($this->receipt_total_amount === null) {
            return null;
        }

        $cents = (int) round(((float) $this->receipt_total_amount) * 100) - (int) round(((float) $this->total_amount) * 100);

        return number_format($cents / 100, 2, '.', '');
    }
}
