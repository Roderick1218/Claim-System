<?php

namespace App\Services;

use App\Models\Claim;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

// 列表、报表和 CSV 导出共用的查询条件。一定从 Claim::visibleTo() 开始，
// 筛选只能在用户本来看得到的范围里再缩小，不可能靠改 URL 参数看到别人的单
class ClaimFilter
{
    // 白名单，用户传什么 sort 都只能映射到这几个字段，不会拼进 SQL
    public const SORTABLE = [
        'claim_number' => 'claims.claim_number',
        'expense_date' => 'claims.expense_date',
        'merchant_name' => 'claims.merchant_name',
        'total_amount' => 'claims.total_amount',
        'created_at' => 'claims.created_at',
    ];

    public const KEYS = [
        'q', 'date_from', 'date_to', 'category_id', 'user_id', 'department_id', 'project_id',
        'merchant', 'amount_min', 'amount_max', 'status', 'sort', 'dir',
    ];

    public function query(User $user, array $filters): Builder
    {
        $query = Claim::query()->visibleTo($user)->with(['user.department', 'category', 'project', 'reviewer']);

        if ($q = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function (Builder $w) use ($q) {
                // 转义 % 和 _，用户输入的这两个字符按字面搜索
                $like = '%'.addcslashes($q, '%_\\').'%';
                $w->where('claims.claim_number', 'like', $like)
                    ->orWhere('claims.merchant_name', 'like', $like)
                    ->orWhere('claims.business_purpose', 'like', $like)
                    ->orWhere('claims.notes', 'like', $like)
                    ->orWhere('claims.location', 'like', $like);
            });
        }

        if ($this->isDate($filters['date_from'] ?? null)) {
            $query->whereDate('claims.expense_date', '>=', $filters['date_from']);
        }
        if ($this->isDate($filters['date_to'] ?? null)) {
            $query->whereDate('claims.expense_date', '<=', $filters['date_to']);
        }

        foreach (['category_id', 'user_id', 'project_id'] as $key) {
            if (ctype_digit((string) ($filters[$key] ?? ''))) {
                $query->where("claims.{$key}", (int) $filters[$key]);
            }
        }

        if (ctype_digit((string) ($filters['department_id'] ?? ''))) {
            $query->whereIn('claims.user_id', User::query()->select('id')->where('department_id', (int) $filters['department_id']));
        }

        if ($merchant = trim((string) ($filters['merchant'] ?? ''))) {
            $query->where('claims.merchant_name', 'like', '%'.addcslashes($merchant, '%_\\').'%');
        }

        if (is_numeric($filters['amount_min'] ?? null)) {
            $query->where('claims.total_amount', '>=', $filters['amount_min']);
        }
        if (is_numeric($filters['amount_max'] ?? null)) {
            $query->where('claims.total_amount', '<=', $filters['amount_max']);
        }

        if (array_key_exists($filters['status'] ?? '', Claim::STATUSES)) {
            $query->where('claims.status', $filters['status']);
        }

        // 默认按创建时间倒序；最后再按 id 排一次，值相同的单分页时顺序才稳定
        $sort = self::SORTABLE[$filters['sort'] ?? ''] ?? 'claims.created_at';
        $dir = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $dir)->orderBy('claims.id', $dir);
    }

    public function fromRequest(Request $request): array
    {
        return array_filter($request->only(self::KEYS), fn ($v) => $v !== null && $v !== '');
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
