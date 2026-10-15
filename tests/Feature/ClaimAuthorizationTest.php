<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaimAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Department $sales;

    private Department $ops;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sales = Department::create(['name' => 'Sales']);
        $this->ops = Department::create(['name' => 'Operations']);
    }

    public function test_staff_cannot_view_or_change_another_users_claim_by_changing_the_id(): void
    {
        $alice = $this->makeUser('staff', $this->sales);
        $bob = $this->makeUser('staff', $this->sales);
        $claim = $this->makeClaim($bob);

        $this->actingAs($alice);
        $this->get(route('claims.show', $claim))->assertForbidden();
        $this->get(route('claims.edit', $claim))->assertForbidden();
        $this->put(route('claims.update', $claim), ['action' => 'draft'])->assertForbidden();
        $this->delete(route('claims.destroy', $claim))->assertForbidden();
        $this->assertDatabaseHas('claims', ['id' => $claim->id, 'deleted_at' => null]);
    }

    public function test_staff_list_only_shows_own_claims(): void
    {
        $alice = $this->makeUser('staff');
        $bob = $this->makeUser('staff');
        $mine = $this->makeClaim($alice);
        $theirs = $this->makeClaim($bob);

        $this->actingAs($alice)->get(route('claims.index'))
            ->assertSee($mine->claim_number)
            ->assertDontSee($theirs->claim_number);
    }

    public function test_by_default_only_admin_sees_other_peoples_claims(): void
    {
        $claim = $this->makeClaim($this->makeUser('staff', $this->sales));

        foreach (['staff', 'manager', 'owner'] as $role) {
            $this->actingAs($this->makeUser($role, $this->sales));
            $this->get(route('claims.show', $claim))->assertForbidden();
            $this->get(route('claims.index'))->assertDontSee($claim->claim_number);
            $this->get(route('reports.index'))->assertForbidden();
        }

        $this->actingAs($this->makeUser('admin', $this->ops));
        $this->get(route('claims.show', $claim))->assertOk();
        $this->get(route('claims.index'))->assertSee($claim->claim_number);
    }

    public function test_department_permission_if_granted_shows_own_department_only(): void
    {
        $this->grant('manager', Permission::CLAIMS_VIEW_DEPARTMENT);
        $manager = $this->makeUser('manager', $this->sales);
        $salesClaim = $this->makeClaim($this->makeUser('staff', $this->sales));
        $opsClaim = $this->makeClaim($this->makeUser('staff', $this->ops));

        $this->actingAs($manager);
        $this->get(route('claims.show', $salesClaim))->assertOk();
        $this->get(route('claims.show', $opsClaim))->assertForbidden();
        // 看得到不代表能改：edit 页转去详情页，硬要保存会被拒
        $this->get(route('claims.edit', $salesClaim))->assertRedirect(route('claims.show', $salesClaim));
        $this->put(route('claims.update', $salesClaim), ['action' => 'submit'])->assertForbidden();
        // 连看都没权限的 claim，edit 页也照样 403，不会转去详情页
        $this->get(route('claims.edit', $opsClaim))->assertForbidden();
    }

    public function test_admin_with_manage_permission_can_edit_others(): void
    {
        $admin = $this->makeUser('admin', $this->ops);
        $claim = $this->makeClaim($this->makeUser('staff', $this->sales));

        $this->actingAs($admin)->get(route('claims.edit', $claim))->assertOk();
    }

    public function test_view_all_permission_if_granted_does_not_allow_editing_others(): void
    {
        $this->grant('owner', Permission::CLAIMS_VIEW_ALL);
        $owner = $this->makeUser('owner');
        $claim = $this->makeClaim($this->makeUser('staff', $this->sales));

        $this->actingAs($owner);
        $this->get(route('claims.show', $claim))->assertOk();
        $this->get(route('claims.edit', $claim))->assertRedirect(route('claims.show', $claim));
        $this->put(route('claims.update', $claim), ['action' => 'submit'])->assertForbidden();
    }

    public function test_staff_cannot_open_settings_or_reports(): void
    {
        $this->actingAs($this->makeUser('staff'));

        foreach (['settings.users.index', 'settings.departments.index', 'settings.roles.index', 'settings.categories.index', 'settings.projects.index', 'settings.audit-logs.index', 'reports.index', 'reports.export'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->post(route('settings.categories.store'), ['name' => 'Hacked'])->assertForbidden();
        $this->assertDatabaseMissing('claim_categories', ['name' => 'Hacked']);
    }

    public function test_manager_cannot_manage_settings(): void
    {
        $this->actingAs($this->makeUser('manager', $this->sales));
        $this->get(route('settings.users.index'))->assertForbidden();
    }

    // 防 mass assignment：在表单里偷塞 user_id / claim_number 不应该有作用
    public function test_claim_owner_cannot_be_changed_through_the_request(): void
    {
        $alice = $this->makeUser('staff');
        $bob = $this->makeUser('staff');
        $claim = $this->makeClaim($alice, ['status' => 'draft']);

        $this->actingAs($alice)->put(route('claims.update', $claim), [
            'action' => 'draft',
            'user_id' => $bob->id,
            'claim_number' => 'CLM-HACK',
            'items' => [],
        ])->assertRedirect();

        $this->assertDatabaseHas('claims', ['id' => $claim->id, 'user_id' => $alice->id]);
        $this->assertDatabaseMissing('claims', ['claim_number' => 'CLM-HACK']);
    }
}
