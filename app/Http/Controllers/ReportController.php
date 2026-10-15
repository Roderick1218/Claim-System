<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\ClaimCategory;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ClaimFilter;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index(Request $request, ClaimFilter $filter)
    {
        $filters = $filter->fromRequest($request);
        $query = $filter->query($request->user(), $filters);

        $summary = (clone $query)->reorder()->selectRaw('COUNT(*) as count, COALESCE(SUM(claims.total_amount), 0) as total')->first();
        $byCategory = (clone $query)->reorder()->getQuery()
            ->leftJoin('claim_categories', 'claim_categories.id', '=', 'claims.category_id')
            ->groupBy('claim_categories.name')
            ->selectRaw("COALESCE(claim_categories.name, 'Uncategorised') as label, COUNT(*) as count, SUM(claims.total_amount) as total")
            ->orderByDesc('total')
            ->get();
        $byUser = (clone $query)->reorder()->getQuery()
            ->join('users', 'users.id', '=', 'claims.user_id')
            ->groupBy('users.name')
            ->selectRaw('users.name as label, COUNT(*) as count, SUM(claims.total_amount) as total')
            ->orderByDesc('total')
            ->get();

        $visibleUserIds = Claim::query()->visibleTo($request->user())->select('user_id')->distinct();

        return view('reports.index', [
            'filters' => $filters,
            'claims' => $query->paginate(25)->withQueryString(),
            'summary' => $summary,
            'byCategory' => $byCategory,
            'byUser' => $byUser,
            'options' => [
                'categories' => ClaimCategory::orderBy('name')->get(['id', 'name']),
                'projects' => Project::orderBy('name')->get(['id', 'name', 'project_code']),
                'users' => User::whereIn('id', $visibleUserIds)->orWhere('id', $request->user()->id)->orderBy('name')->get(['id', 'name']),
                'departments' => Department::orderBy('name')->get(['id', 'name']),
            ],
        ]);
    }

    /**
     * 导出的 CSV 跟画面上的报表用同一套筛选和权限范围。
     * 不然改一下 URL 参数就能把看不到的 claim 一起导出来，等于绕过权限。
     */
    public function export(Request $request, ClaimFilter $filter, AuditLogger $audit)
    {
        $filters = $filter->fromRequest($request);
        $query = $filter->query($request->user(), $filters);
        $audit->log('report.exported', 'claim', null, null, ['filters' => $filters]);

        $filename = 'claims-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // 加 UTF-8 BOM，否则 Excel 双击打开会当成本地编码，中文变乱码
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'No', 'Claim Number', 'Expense Date', 'Expense Time', 'Merchant', 'Category', 'Project', 'Employee',
                'Employee Code', 'Department', 'Business Purpose', 'Location', 'Currency', 'Subtotal',
                'Discount', 'Tax', 'Total', 'Receipt Total', 'Status', 'Created At',
                'Submitted At', 'Reviewed By', 'Reviewed At', 'Review Note',
            ]);

            $no = 0;
            // 用 bcadd 累加字符串金额，避免 float 误差让 Grand Total 差几分钱
            $totals = ['subtotal_amount' => '0', 'discount_amount' => '0', 'tax_amount' => '0', 'total_amount' => '0', 'receipt_total_amount' => '0'];

            $query->chunk(500, function ($claims) use ($out, &$no, &$totals) {
                foreach ($claims as $c) {
                    foreach ($totals as $column => $sum) {
                        $totals[$column] = bcadd($sum, (string) ($c->{$column} ?? 0), 2);
                    }

                    fputcsv($out, array_map([$this, 'csvSafe'], [
                        ++$no,
                        $c->claim_number,
                        $c->expense_date?->format('Y-m-d'),
                        $c->expense_time ? substr($c->expense_time, 0, 5) : '',
                        $c->merchant_name,
                        $c->category?->name,
                        $c->project?->name,
                        $c->user?->name,
                        $c->user?->employee_code,
                        $c->user?->department?->name,
                        $c->business_purpose,
                        $c->location,
                        $c->currency,
                        $c->subtotal_amount,
                        $c->discount_amount,
                        $c->tax_amount,
                        $c->total_amount,
                        $c->receipt_total_amount,
                        $c->status_label,
                        $c->created_at?->format('Y-m-d H:i'),
                        $c->submitted_at?->format('Y-m-d H:i'),
                        $c->reviewer?->name,
                        $c->reviewed_at?->format('Y-m-d H:i'),
                        $c->review_note,
                    ]));
                }
            });

            // 合计行同样 24 栏，跟表头对齐：标签放在 No 那栏，各金额栏放总和
            fputcsv($out, [
                'Grand Total', '', '', '', '', '', '', '', '', '', '', '', '',
                $totals['subtotal_amount'], $totals['discount_amount'], $totals['tax_amount'],
                $totals['total_amount'], $totals['receipt_total_amount'],
                '', '', '', '', '', '',
            ]);

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * 防 CSV 公式注入：文字以 = + - @ 开头时 Excel 会当公式执行，前面补一个单引号。
     * 纯数字（例如负数金额）不处理。
     */
    public function csvSafe(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) && ! is_numeric($value) ? "'".$value : $value;
    }
}
