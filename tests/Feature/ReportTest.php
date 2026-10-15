<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_date_category_employee_and_project_filters(): void
    {
        $admin = $this->makeUser('admin');
        $alice = $this->makeUser('staff', null, ['name' => 'Alice']);
        $bob = $this->makeUser('staff', null, ['name' => 'Bob']);
        $project = Project::create(['name' => 'Big Job']);

        $old = $this->makeClaim($alice, ['expense_date' => '2026-01-15']);
        $toll = $this->makeClaim($alice, ['expense_date' => '2026-10-01', 'category_id' => $this->category('Toll')->id]);
        $parking = $this->makeClaim($bob, ['expense_date' => '2026-10-02', 'category_id' => $this->category('Parking')->id, 'project_id' => $project->id]);

        $this->actingAs($admin);

        $this->get(route('reports.index', ['date_from' => '2026-10-01', 'date_to' => '2026-10-31']))
            ->assertSee($toll->claim_number)->assertSee($parking->claim_number)->assertDontSee($old->claim_number);

        $this->get(route('reports.index', ['category_id' => $this->category('Parking')->id]))
            ->assertSee($parking->claim_number)->assertDontSee($toll->claim_number);

        $this->get(route('reports.index', ['user_id' => $alice->id]))
            ->assertSee($toll->claim_number)->assertDontSee($parking->claim_number);

        $this->get(route('reports.index', ['project_id' => $project->id]))
            ->assertSee($parking->claim_number)->assertDontSee($toll->claim_number);
    }

    public function test_export_respects_permission_scope_even_with_filters(): void
    {
        $sales = Department::create(['name' => 'Sales']);
        $ops = Department::create(['name' => 'Ops']);
        $this->grant('manager', Permission::CLAIMS_VIEW_DEPARTMENT, Permission::REPORTS_VIEW);
        $manager = $this->makeUser('manager', $sales);
        $salesClaim = $this->makeClaim($this->makeUser('staff', $sales));
        $opsUser = $this->makeUser('staff', $ops);
        $opsClaim = $this->makeClaim($opsUser);

        // 就算在 URL 里直接指定别部门的员工，也导不出任何东西
        $csv = $this->actingAs($manager)->get(route('reports.export', ['user_id' => $opsUser->id]))->assertOk()->streamedContent();
        $this->assertStringNotContainsString($opsClaim->claim_number, $csv);

        $csv = $this->get(route('reports.export'))->streamedContent();
        $this->assertStringContainsString($salesClaim->claim_number, $csv);
        $this->assertStringNotContainsString($opsClaim->claim_number, $csv);
    }

    public function test_export_numbers_rows_and_ends_with_grand_total(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeClaim($admin, ['total_amount' => '10.50']);
        $this->makeClaim($admin, ['total_amount' => '4.25']);

        $csv = $this->actingAs($admin)->get(route('reports.export'))->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim(substr($csv, 3))))); // 前 3 byte 是 BOM

        $this->assertSame('No', $rows[0][0]);
        $this->assertSame(['1', '2'], [$rows[1][0], $rows[2][0]]);
        $grand = end($rows);
        $this->assertSame('Grand Total', $grand[0]);
        $this->assertCount(count($rows[0]), $grand);
        $this->assertSame('14.75', $grand[array_search('Total', $rows[0])]);
    }

    public function test_csv_cells_are_protected_against_formula_injection(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeClaim($admin, ['merchant_name' => '=HYPERLINK("http://evil")']);

        $csv = $this->actingAs($admin)->get(route('reports.export'))->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_dashboard_only_counts_claims_in_users_scope(): void
    {
        $alice = $this->makeUser();
        $this->makeClaim($alice, ['total_amount' => '10.00', 'status' => 'approved']);
        $this->makeClaim($this->makeUser(), ['total_amount' => '500.00', 'status' => 'approved']);

        $this->actingAs($alice)->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('monthTotal', 10.0)
            ->assertViewHas('monthCount', 1);

        $this->actingAs($this->makeUser('admin'))->get(route('dashboard'))
            ->assertViewHas('monthTotal', 510.0);
    }
}
