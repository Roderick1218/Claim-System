<?php

namespace Tests\Feature;

use App\Models\Claim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function submittedClaimBy($user): Claim
    {
        $claim = $this->makeClaim($user, ['status' => 'draft', 'category_id' => $this->category('Other')->id]);
        $this->actingAs($user)->put(route('claims.update', $claim), [
            'action' => 'submit',
            'category_id' => $this->category('Other')->id,
            'expense_date' => now()->toDateString(),
            'items' => [['item_name' => 'Lunch', 'quantity' => '1', 'unit_price' => '12.00']],
        ])->assertRedirect(route('claims.show', $claim));

        return $claim->fresh();
    }

    public function test_staff_submits_and_the_claim_is_then_locked_for_them(): void
    {
        $staff = $this->makeUser();
        $claim = $this->submittedClaimBy($staff);

        $this->assertSame('submitted', $claim->status);
        $this->assertNotNull($claim->submitted_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'claim.submitted', 'entity_id' => $claim->id]);

        $this->actingAs($staff);
        // Opening the edit page (e.g. browser Back after submitting) shows the claim instead of a 403 ...
        $this->get(route('claims.edit', $claim))->assertRedirect(route('claims.show', $claim))->assertSessionHas('success', fn ($m) => str_contains($m, 'locked'));
        // ... but changing it is still refused.
        $this->put(route('claims.update', $claim), ['action' => 'draft'])->assertForbidden();
        $this->delete(route('claims.destroy', $claim))->assertForbidden();
        $this->get(route('claims.show', $claim))->assertOk()->assertSee('waiting for approval');
    }

    public function test_admin_sees_it_in_approvals_and_approves_it(): void
    {
        $staff = $this->makeUser();
        $claim = $this->submittedClaimBy($staff);
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->get(route('approvals.index'))->assertOk()->assertSee($claim->claim_number);
        $this->get(route('claims.show', $claim))->assertSee('Review this claim');

        $this->post(route('claims.approve', $claim), ['review_note' => 'OK for October'])->assertRedirect();

        $claim->refresh();
        $this->assertSame('approved', $claim->status);
        $this->assertSame($admin->id, $claim->reviewed_by);
        $this->assertSame('OK for October', $claim->review_note);
        $this->assertDatabaseHas('audit_logs', ['action' => 'claim.approved', 'entity_id' => $claim->id, 'user_id' => $admin->id]);
        $this->get(route('approvals.index'))->assertViewHas('pending', fn ($p) => ! $p->contains('id', $claim->id));
    }

    public function test_approved_claims_are_final_for_everyone(): void
    {
        $staff = $this->makeUser();
        $claim = $this->makeClaim($staff, ['status' => 'approved']);
        $admin = $this->makeUser('admin');

        foreach ([$staff, $admin] as $user) {
            $this->actingAs($user);
            $this->get(route('claims.edit', $claim))->assertRedirect(route('claims.show', $claim));
            $this->put(route('claims.update', $claim), ['action' => 'submit'])->assertForbidden();
            $this->delete(route('claims.destroy', $claim))->assertForbidden();
        }
        $this->post(route('claims.approve', $claim))->assertForbidden(); // already approved
    }

    public function test_reject_needs_a_reason_then_staff_fixes_and_resubmits(): void
    {
        $staff = $this->makeUser();
        $claim = $this->submittedClaimBy($staff);
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->post(route('claims.reject', $claim), ['review_note' => ''])->assertSessionHasErrors('review_note');
        $this->assertSame('submitted', $claim->fresh()->status);

        $this->post(route('claims.reject', $claim), ['review_note' => 'Receipt date is unreadable, please re-upload'])->assertRedirect();
        $claim->refresh();
        $this->assertSame('rejected', $claim->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'claim.rejected', 'entity_id' => $claim->id]);

        // The staff sees why, can edit again and resubmit.
        $this->actingAs($staff)->get(route('dashboard'))->assertSee('Receipt date is unreadable');
        $this->get(route('claims.edit', $claim))->assertOk()->assertSee('Resubmit for approval');
        $this->put(route('claims.update', $claim), [
            'action' => 'submit',
            'category_id' => $this->category('Other')->id,
            'expense_date' => now()->toDateString(),
            'items' => [['item_name' => 'Lunch', 'quantity' => '1', 'unit_price' => '11.00']],
        ])->assertRedirect(route('claims.show', $claim));

        $claim->refresh();
        $this->assertSame('submitted', $claim->status);
        $this->assertNull($claim->review_note);
        $this->assertNull($claim->reviewed_by);
        $this->assertSame('11.00', $claim->total_amount);
    }

    public function test_nobody_can_approve_their_own_claim(): void
    {
        $admin = $this->makeUser('admin');
        $claim = $this->submittedClaimBy($admin);

        $this->actingAs($admin)->post(route('claims.approve', $claim))->assertForbidden();
        $this->get(route('approvals.index'))->assertViewHas('pending', fn ($p) => ! $p->contains('id', $claim->id));
        $this->assertSame('submitted', $claim->fresh()->status);
    }

    public function test_only_users_with_the_approve_permission_can_approve(): void
    {
        $claim = $this->submittedClaimBy($this->makeUser());

        foreach (['staff', 'manager', 'owner'] as $role) {
            $this->actingAs($this->makeUser($role));
            $this->get(route('approvals.index'))->assertForbidden();
            $this->post(route('claims.approve', $claim))->assertForbidden();
            $this->post(route('claims.reject', $claim), ['review_note' => 'no'])->assertForbidden();
        }
        $this->assertSame('submitted', $claim->fresh()->status);
    }

    public function test_back_link_on_a_claim_never_points_to_its_locked_edit_page(): void
    {
        $staff = $this->makeUser();
        $claim = $this->submittedClaimBy($staff); // last page visited before show = the edit/update flow

        $this->actingAs($staff)
            ->from(route('claims.edit', $claim))
            ->get(route('claims.show', $claim))
            ->assertDontSee('href="'.route('claims.edit', $claim).'" class="mb-4', false)
            ->assertSee('href="'.route('claims.index').'" class="mb-4', false);

        $this->from(route('claims.index', ['status' => 'submitted']))
            ->get(route('claims.show', $claim))
            ->assertSee('href="'.e(route('claims.index', ['status' => 'submitted'])).'" class="mb-4', false);
    }

    public function test_drafts_cannot_be_approved(): void
    {
        $claim = $this->makeClaim($this->makeUser(), ['status' => 'draft']);

        $this->actingAs($this->makeUser('admin'))->post(route('claims.approve', $claim))->assertForbidden();
    }

    public function test_receipts_cannot_be_removed_after_submitting(): void
    {
        $staff = $this->makeUser();
        $this->actingAs($staff)->post(route('claims.store'), ['receipts' => [UploadedFile::fake()->image('r.png', 300, 300)]]);
        $claim = Claim::latest('id')->firstOrFail();
        $claim->forceFill(['status' => 'submitted'])->save();

        $this->delete(route('receipts.destroy', $claim->receipts->first()))->assertForbidden();
    }

    public function test_dashboard_counts_approved_spending_and_pending_separately(): void
    {
        $staff = $this->makeUser();
        $this->makeClaim($staff, ['status' => 'approved', 'total_amount' => '30.00']);
        $this->makeClaim($staff, ['status' => 'submitted', 'total_amount' => '20.00']);

        $this->actingAs($this->makeUser('admin'))->get(route('dashboard'))
            ->assertViewHas('monthTotal', 30.0)
            ->assertViewHas('pendingCount', 1)
            ->assertViewHas('pendingTotal', 20.0)
            ->assertViewHas('toApproveCount', 1)
            ->assertSee('1</strong> claim(s) waiting for your approval', false);
    }
}
