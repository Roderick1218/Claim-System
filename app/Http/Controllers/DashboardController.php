<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\Permission;
use App\Models\Receipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /** 这页所有数字都从 Claim::visibleTo() 算起，只统计当前用户权限范围内的 claim，普通员工看不到公司总数 */
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $visible = fn () => Claim::query()->visibleTo($user);
        // 花费只算 approved 的，还在审的可能被 reject，另外单独显示
        $approved = fn () => $visible()->where('claims.status', Claim::STATUS_APPROVED);
        $pending = fn () => $visible()->where('claims.status', Claim::STATUS_SUBMITTED);

        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $thisMonth = fn () => $approved()->whereBetween('claims.expense_date', [$monthStart, $monthEnd]);

        $byCategory = $thisMonth()
            ->leftJoin('claim_categories', 'claim_categories.id', '=', 'claims.category_id')
            ->groupBy('claim_categories.name')
            ->orderByDesc('total')
            ->get([DB::raw("COALESCE(claim_categories.name, 'Uncategorised') as label"), DB::raw('SUM(claims.total_amount) as total'), DB::raw('COUNT(*) as count')]);

        $byProject = $thisMonth()
            ->leftJoin('projects', 'projects.id', '=', 'claims.project_id')
            ->groupBy('projects.name')
            ->orderByDesc('total')
            ->limit(8)
            ->get([DB::raw("COALESCE(projects.name, 'No project') as label"), DB::raw('SUM(claims.total_amount) as total'), DB::raw('COUNT(*) as count')]);

        // Claim summary 图表：每月 approved 金额，默认今年，也可以选最近 6 / 12 个月。
        // 按月分组放在 PHP 做，因为 MySQL 和 SQLite（测试用）的日期函数不一样。
        $range = in_array($request->query('range'), ['6', '12'], true) ? $request->query('range') : 'year';
        $months = $range === 'year' ? max(6, now()->month) : (int) $range;
        $trendStart = now()->startOfMonth()->subMonths($months - 1);
        $trendRows = $approved()
            ->whereDate('claims.expense_date', '>=', $trendStart->toDateString())
            ->get(['claims.expense_date', 'claims.total_amount']);
        $trend = collect(range($months - 1, 0))->map(function ($ago) use ($trendRows) {
            $month = now()->startOfMonth()->subMonths($ago);
            $key = $month->format('Y-m');

            return [
                'label' => $month->format('M Y'),
                'total' => $trendRows->filter(fn ($r) => $r->expense_date?->format('Y-m') === $key)->sum(fn ($r) => (float) $r->total_amount),
            ];
        });

        // 统计卡片：本月对比上月
        $lastStart = now()->startOfMonth()->subMonth();
        $lastEnd = $lastStart->copy()->endOfMonth();
        $between = fn ($q, string $column, $from, $to) => $q->whereBetween($column, [$from, $to]);
        $submittedAll = fn () => $visible()->where('claims.status', '<>', Claim::STATUS_DRAFT);
        $rejected = fn () => $visible()->where('claims.status', Claim::STATUS_REJECTED);
        $stats = [
            'total' => [
                $between($submittedAll(), 'claims.created_at', now()->startOfMonth(), now()->endOfMonth())->count(),
                $between($submittedAll(), 'claims.created_at', $lastStart, $lastEnd)->count(),
            ],
            'approved' => [
                $thisMonth()->count(),
                $between($approved(), 'claims.expense_date', $lastStart->toDateString(), $lastEnd->toDateString())->count(),
            ],
            'rejected' => [
                $between($rejected(), 'claims.reviewed_at', now()->startOfMonth(), now()->endOfMonth())->count(),
                $between($rejected(), 'claims.reviewed_at', $lastStart, $lastEnd)->count(),
            ],
        ];

        $receiptAttention = Receipt::query()
            ->whereIn('ocr_status', [Receipt::OCR_FAILED, Receipt::OCR_PENDING, Receipt::OCR_PROCESSING])
            ->whereIn('claim_id', $visible()->select('claims.id'))
            ->with('claim')
            ->latest()
            ->limit(10)
            ->get();

        return view('dashboard.index', [
            'monthTotal' => (float) $thisMonth()->sum('claims.total_amount'),
            'monthCount' => $thisMonth()->count(),
            'pendingCount' => $pending()->count(),
            'pendingTotal' => (float) $pending()->sum('claims.total_amount'),
            // 自己要跟进的：还没 submit 的 draft，和被 reject 要改的
            'myDraftCount' => Claim::query()->where('user_id', $user->id)->where('status', Claim::STATUS_DRAFT)->count(),
            'myRejected' => Claim::query()->where('user_id', $user->id)->where('status', Claim::STATUS_REJECTED)->latest('reviewed_at')->get(),
            'toApproveCount' => $user->hasPermission(Permission::CLAIMS_APPROVE)
                ? $pending()->where('claims.user_id', '<>', $user->id)->count() : null,
            'byCategory' => $byCategory,
            'byProject' => $byProject,
            'trend' => $trend,
            'range' => $range,
            'stats' => $stats,
            'recent' => $visible()->with(['user', 'category'])->latest()->limit(6)->get(),
            'receiptAttention' => $receiptAttention,
        ]);
    }
}
