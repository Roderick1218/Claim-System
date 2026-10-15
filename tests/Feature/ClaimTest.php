<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\ClaimCategory;
use App\Models\Project;
use App\Services\ClaimNumberService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ClaimTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'action' => 'submit',
            'category_id' => $this->category('Other')->id, // "Other" 不需要收据
            'merchant_name' => 'Kedai ABC',
            'expense_date' => now()->toDateString(),
            'items' => [
                ['item_name' => 'Mineral Water', 'quantity' => '2', 'unit_price' => '2.00'],
                ['item_name' => 'Lunch', 'quantity' => '1', 'unit_price' => '12.00'],
                ['item_name' => 'Petrol', 'quantity' => '30', 'unit_price' => '2.05'],
            ],
        ], $overrides);
    }

    public function test_manual_claim_can_be_created_and_submitted_without_receipt_or_ocr(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->post(route('claims.store'))->assertRedirect();
        $claim = Claim::firstOrFail();
        $this->assertSame('draft', $claim->status);
        $this->assertSame($user->id, $claim->user_id);
        $this->assertMatchesRegularExpression('/^CLM-\d{6}-00001$/', $claim->claim_number);

        $this->put(route('claims.update', $claim), $this->validPayload())->assertRedirect(route('claims.show', $claim));

        $claim->refresh();
        $this->assertSame('submitted', $claim->status);
        $this->assertSame('77.50', $claim->total_amount);
        $this->assertCount(3, $claim->items);
        $this->assertSame(0, $this->ocr->calls);
        $this->assertDatabaseHas('audit_logs', ['action' => 'claim.updated', 'entity_id' => $claim->id]);
    }

    public function test_draft_can_be_saved_incomplete(): void
    {
        $user = $this->makeUser();
        $claim = $this->makeClaim($user, ['status' => 'draft']);

        $this->actingAs($user)->put(route('claims.update', $claim), ['action' => 'draft', 'merchant_name' => 'Half done'])
            ->assertRedirect(route('claims.edit', $claim));

        $this->assertSame('draft', $claim->fresh()->status);
    }

    public function test_submitting_requires_category_date_and_items(): void
    {
        $user = $this->makeUser();
        $claim = $this->makeClaim($user, ['status' => 'draft']);

        $this->actingAs($user)->put(route('claims.update', $claim), ['action' => 'submit', 'category_id' => '', 'expense_date' => '', 'items' => []])
            ->assertSessionHasErrors(['category_id', 'expense_date', 'items']);
        $this->assertSame('draft', $claim->fresh()->status);
    }

    // 12.5 x 2.05 = 25.625 → 25.63，扣 0.63 折扣后是 25.00；浏览器传来的 total / line_total 一律不理
    public function test_backend_recalculates_amounts_and_ignores_browser_totals(): void
    {
        $user = $this->makeUser();
        $claim = $this->makeClaim($user, ['status' => 'draft']);

        $payload = $this->validPayload([
            'total_amount' => '9999.99',
            'subtotal_amount' => '9999.99',
            'items' => [['item_name' => 'Petrol', 'quantity' => '12.5', 'unit_price' => '2.05', 'discount_amount' => '0.63', 'line_total' => '5000']],
        ]);
        $this->actingAs($user)->put(route('claims.update', $claim), $payload);

        $claim->refresh();
        $this->assertSame('25.00', $claim->total_amount);
        $this->assertSame('25.63', $claim->subtotal_amount);
        $this->assertSame('25.00', $claim->items->first()->line_total);
    }

    public function test_receipt_total_difference_is_reported_not_overwritten(): void
    {
        $user = $this->makeUser();
        $claim = $this->makeClaim($user, ['status' => 'draft']);

        $this->actingAs($user)->put(route('claims.update', $claim), $this->validPayload(['receipt_total_amount' => '80.00']));

        $claim->refresh();
        $this->assertSame('77.50', $claim->total_amount);
        $this->assertSame('80.00', $claim->receipt_total_amount);
        $this->assertSame('2.50', $claim->difference_amount);
        $this->actingAs($user)->get(route('claims.show', $claim))->assertSee('differs from the receipt total by 2.50');
    }

    public function test_invalid_category_and_project_ids_are_rejected(): void
    {
        $user = $this->makeUser();
        $claim = $this->makeClaim($user, ['status' => 'draft']);

        $this->actingAs($user)->put(route('claims.update', $claim), $this->validPayload(['category_id' => 99999, 'project_id' => 99999]))
            ->assertSessionHasErrors(['category_id', 'project_id']);
    }

    public function test_inactive_category_cannot_be_newly_chosen_but_stays_on_existing_claim(): void
    {
        $user = $this->makeUser();
        $inactive = ClaimCategory::create(['name' => 'Old Category', 'requires_receipt' => false, 'is_active' => false]);

        $newClaim = $this->makeClaim($user, ['status' => 'draft']);
        $this->actingAs($user)->put(route('claims.update', $newClaim), $this->validPayload(['category_id' => $inactive->id]))
            ->assertSessionHasErrors('category_id');

        $oldClaim = $this->makeClaim($user, ['category_id' => $inactive->id, 'status' => 'rejected']); // 被 reject 的 claim 本人还能改
        $this->actingAs($user)->put(route('claims.update', $oldClaim), $this->validPayload(['category_id' => $inactive->id]))
            ->assertSessionHasNoErrors();
        $this->get(route('claims.show', $oldClaim))->assertSee('Old Category (inactive)');
    }

    public function test_category_requiring_receipt_blocks_submitting_without_receipt(): void
    {
        $user = $this->makeUser();
        $claim = $this->makeClaim($user, ['status' => 'draft']);

        $this->actingAs($user)->put(route('claims.update', $claim), $this->validPayload(['category_id' => $this->category('Toll')->id]))
            ->assertSessionHasErrors('category_id');
    }

    public function test_discount_larger_than_amount_is_rejected(): void
    {
        $user = $this->makeUser();
        $claim = $this->makeClaim($user, ['status' => 'draft']);

        $this->actingAs($user)->put(route('claims.update', $claim), $this->validPayload([
            'items' => [['item_name' => 'X', 'quantity' => '1', 'unit_price' => '5', 'discount_amount' => '6']],
        ]))->assertSessionHasErrors('items.0.discount_amount');
    }

    public function test_claim_numbers_are_unique_and_sequential(): void
    {
        $service = app(ClaimNumberService::class);
        $numbers = collect(range(1, 5))->map(fn () => \DB::transaction(fn () => $service->next()));

        $this->assertCount(5, $numbers->unique());
        $this->assertSame('CLM-'.now()->format('Ym').'-00005', $numbers->last());
    }

    public function test_history_is_not_lost_when_deleting_category_project_or_user(): void
    {
        $user = $this->makeUser();
        $project = Project::create(['name' => 'P1']);
        $claim = $this->makeClaim($user, ['project_id' => $project->id]);

        foreach ([fn () => $this->category()->delete(), fn () => $project->delete(), fn () => $user->delete()] as $delete) {
            try {
                $delete();
                $this->fail('Delete should have been blocked by a foreign key.');
            } catch (QueryException) {
                // 预期之内，restrictOnDelete 挡住了
            }
        }

        $this->assertDatabaseHas('claims', ['id' => $claim->id, 'project_id' => $project->id, 'user_id' => $user->id]);
    }

    public function test_deleting_a_claim_is_a_soft_delete_with_audit_log(): void
    {
        $user = $this->makeUser();
        $claim = $this->makeClaim($user, ['status' => 'draft']);

        $this->actingAs($user)->delete(route('claims.destroy', $claim))->assertRedirect(route('claims.index'));

        $this->assertSoftDeleted('claims', ['id' => $claim->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'claim.deleted', 'entity_id' => $claim->id]);
        $this->get(route('claims.show', $claim))->assertNotFound();
    }

    public function test_claim_can_still_be_saved_manually_when_ocr_fails(): void
    {
        $this->ocr->fail = true;
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->post(route('claims.store'), ['receipts' => [UploadedFile::fake()->image('r.jpg', 400, 600)]])->assertRedirect();
        $claim = Claim::firstOrFail();
        $this->assertSame('failed', $claim->receipts->first()->ocr_status);

        $this->put(route('claims.update', $claim), $this->validPayload(['category_id' => $this->category('Toll')->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame('submitted', $claim->fresh()->status);
    }
}
