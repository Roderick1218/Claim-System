<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\Permission;
use App\Models\Receipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /** Every figure here is computed from Claim::visibleTo(), i.e. the current user's permitted scope only. */
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $visible = fn () => Claim::query()->visibleTo($user);
        // Spending figures count APPROVED claims only; pending ones are shown separately.
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

        // Claim summary chart: approved amount per month — this year (default), or the last 6 / 12 months.
        // Built in PHP so it works on both MySQL and SQLite (tests).
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

        // Stat cards: this month against last month.
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
            // The user's own claims that need action: drafts not yet submitted, rejected ones to fix.
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
