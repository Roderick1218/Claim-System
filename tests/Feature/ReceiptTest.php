<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\Receipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function uploadNewClaim($user, array $files): Claim
    {
        $this->actingAs($user)->post(route('claims.store'), ['receipts' => $files])->assertRedirect();

        return Claim::latest('id')->firstOrFail();
    }

    public function test_valid_image_is_stored_privately_and_ocr_text_saved(): void
    {
        $user = $this->makeUser();
        $claim = $this->uploadNewClaim($user, [UploadedFile::fake()->image('my receipt.jpg', 600, 900)]);

        $receipt = $claim->receipts->first();
        $this->assertSame('my receipt.jpg', $receipt->original_filename);
        $this->assertSame('image/jpeg', $receipt->mime_type);
        $this->assertSame(64, strlen($receipt->file_hash));
        $this->assertStringStartsWith('receipts/', $receipt->file_path);
        $this->assertStringNotContainsString('my receipt', $receipt->file_path); // random internal name
        Storage::disk('local')->assertExists($receipt->file_path);
        $this->assertSame('completed', $receipt->ocr_status);
        $this->assertStringContainsString('TOTAL 4.00', $receipt->ocr_text);
        $this->assertDatabaseHas('audit_logs', ['action' => 'receipt.uploaded', 'entity_id' => $receipt->id]);
    }

    public function test_edit_page_prefills_ocr_values_highlighted_but_does_not_save_them(): void
    {
        $user = $this->makeUser();
        $claim = $this->uploadNewClaim($user, [UploadedFile::fake()->image('r.png', 400, 400)]);

        $this->get(route('claims.edit', $claim))
            ->assertOk()
            ->assertSee('Auto-filled from the receipt (OCR)')
            ->assertSee('value="ABC TRADING" class="input ocr-filled"', false)
            ->assertSee('value="2026-10-05" class="input ocr-filled"', false)
            ->assertSee('value="14:30" class="input ocr-filled"', false)
            ->assertSee('value="4.00" class="input ocr-filled"', false)
            ->assertViewHas('prefill', fn ($p) => count($p['items']) === 1
                && $p['items'][0]['item_name'] === 'Mineral Water'
                && $p['items'][0]['quantity'] === '2'
                && $p['items'][0]['unit_price'] === '2.00'
                && $p['items'][0]['receipt_id'] === $claim->receipts->first()->id);

        // Only shown in the form — the database stays empty until the user saves.
        $claim->refresh();
        $this->assertNull($claim->merchant_name);
        $this->assertNull($claim->expense_date);
        $this->assertNull($claim->receipt_total_amount);
        $this->assertCount(0, $claim->items);
    }

    public function test_edit_page_prefills_location_from_the_receipt_address(): void
    {
        $this->ocr->text = "KEDAI ABC SDN BHD\nLOT LG-23, AOEN BIG SUBANG JAYA\nNO 3 JALAN SS16/1, 47500 SUBANG JAYA, SELANGOR\nDATE: 05/10/2026 14:30\nTOTAL 4.00";
        $user = $this->makeUser();
        $claim = $this->uploadNewClaim($user, [UploadedFile::fake()->image('r.png', 400, 400)]);

        $this->get(route('claims.edit', $claim))
            ->assertOk()
            ->assertSee('value="LOT LG-23, AOEN BIG SUBANG JAYA, NO 3 JALAN SS16/1, 47500 SUBANG JAYA, SELANGOR" class="input ocr-filled"', false);
        $this->assertNull($claim->fresh()->location); // not saved until the user saves

        $claim->forceFill(['location' => 'Office'])->save();
        $this->get(route('claims.edit', $claim))
            ->assertSee('value="Office" class="input "', false);
    }

    public function test_prefill_never_overwrites_values_the_user_already_saved(): void
    {
        $user = $this->makeUser();
        $claim = $this->uploadNewClaim($user, [UploadedFile::fake()->image('r.png', 400, 400)]);
        $claim->forceFill(['merchant_name' => 'My Own Name', 'expense_date' => '2026-09-30'])->save();

        $this->get(route('claims.edit', $claim))
            ->assertSee('value="My Own Name" class="input "', false)
            ->assertSee('value="2026-09-30" class="input "', false)
            ->assertDontSee('ABC TRADING" class', false);
    }

    public function test_refill_from_ocr_replaces_saved_draft_values_in_the_form_only(): void
    {
        $user = $this->makeUser();
        $claim = $this->uploadNewClaim($user, [UploadedFile::fake()->image('r.png', 400, 400)]);
        $claim->forceFill(['merchant_name' => 'Old Name'])->save();
        $claim->items()->create(['item_name' => 'Old item', 'quantity' => 1, 'unit_price' => 1, 'line_total' => 1]);

        $this->get(route('claims.edit', $claim))->assertSee('value="Old Name" class="input "', false);

        $this->get(route('claims.edit', ['claim' => $claim, 'refill' => 1]))
            ->assertSee('value="ABC TRADING" class="input ocr-filled"', false)
            ->assertViewHas('prefill', fn ($p) => $p['items'][0]['item_name'] === 'Mineral Water');

        // Nothing changed in the database.
        $this->assertSame('Old Name', $claim->fresh()->merchant_name);
        $this->assertSame(['Old item'], $claim->fresh()->items->pluck('item_name')->all());
    }

    public function test_retry_ocr_on_a_draft_shows_the_new_reading_in_the_form(): void
    {
        $this->ocr->fail = true;
        $user = $this->makeUser();
        $claim = $this->uploadNewClaim($user, [UploadedFile::fake()->image('r.png', 400, 400)]);
        $claim->forceFill(['merchant_name' => 'Typed by hand'])->save();

        $this->ocr->fail = false;
        $this->post(route('receipts.ocr.retry', $claim->receipts->first()))
            ->assertRedirect(route('claims.edit', ['claim' => $claim, 'refill' => 1]));

        $this->assertSame('Typed by hand', $claim->fresh()->merchant_name); // still only in the form
    }

    public function test_saved_items_that_do_not_add_up_trigger_a_refill_suggestion(): void
    {
        $user = $this->makeUser();
        $claim = $this->uploadNewClaim($user, [UploadedFile::fake()->image('r.png', 400, 400)]);
        $claim->items()->create(['item_name' => 'Wrong', 'quantity' => 1, 'unit_price' => 3, 'line_total' => 3]);
        $claim->forceFill(['total_amount' => '3.00'])->save(); // OCR reading adds up to 4.00

        $this->get(route('claims.edit', $claim))
            ->assertViewHas('ocrSuggestsRefill', true)
            ->assertSee("The receipt reading adds up, your saved items don't.", false);

        $claim->items()->delete();
        $claim->items()->create(['item_name' => 'Mineral Water', 'quantity' => 2, 'unit_price' => 2, 'line_total' => 4]);
        $claim->forceFill(['total_amount' => '4.00'])->save();
        $this->get(route('claims.edit', $claim))->assertViewHas('ocrSuggestsRefill', false);
    }

    public function test_refill_is_ignored_once_a_claim_is_no_longer_a_draft(): void
    {
        $user = $this->makeUser();
        $claim = $this->uploadNewClaim($user, [UploadedFile::fake()->image('r.png', 400, 400)]);
        $claim->forceFill(['status' => 'rejected', 'merchant_name' => 'Final'])->save();

        $this->get(route('claims.edit', ['claim' => $claim, 'refill' => 1]))
            ->assertSee('value="Final" class="input "', false)
            ->assertDontSee('Re-fill from OCR');
    }

    public function test_unreadable_date_is_flagged_for_the_user(): void
    {
        $this->ocr->text = "ABC TRADING\nMineral Water 2 x 2.00 4.00\nTOTAL 4.00"; // no date on this reading
        $user = $this->makeUser();
        $claim = $this->uploadNewClaim($user, [UploadedFile::fake()->image('r.png', 400, 400)]);

        $this->get(route('claims.edit', $claim))->assertSee("Couldn't read the date from the receipt", false);

        $this->ocr->text = "ABC TRADING\nDATE: 05/10/2026\nTOTAL 4.00";
        $this->post(route('receipts.ocr.retry', $claim->receipts->first()));
        $this->get(route('claims.edit', $claim))->assertDontSee("Couldn't read the date from the receipt", false);
    }

    public function test_failed_ocr_prefills_nothing(): void
    {
        $this->ocr->fail = true;
        $user = $this->makeUser();
        $claim = $this->uploadNewClaim($user, [UploadedFile::fake()->image('r.png', 400, 400)]);

        $this->get(route('claims.edit', $claim))
            ->assertDontSee('Auto-filled from the receipt')
            ->assertViewHas('prefill', []);
    }

    public function test_valid_pdf_is_accepted(): void
    {
        $user = $this->makeUser();
        $pdf = UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $claim = $this->uploadNewClaim($user, [$pdf]);

        $this->assertSame('application/pdf', $claim->receipts->first()->mime_type);
    }

    public function test_disallowed_file_types_are_rejected(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->post(route('claims.store'), ['receipts' => [UploadedFile::fake()->createWithContent('evil.php', '<?php echo 1;')]])
            ->assertSessionHasErrors('receipts.0');
        $this->post(route('claims.store'), ['receipts' => [UploadedFile::fake()->createWithContent('fake.jpg', 'not really an image')]])
            ->assertSessionHasErrors('receipts.0');
        $this->assertSame(0, Receipt::count());
        $this->assertSame(0, Claim::count()); // no orphan draft is created
    }

    public function test_oversized_file_is_rejected(): void
    {
        $user = $this->makeUser();
        $big = UploadedFile::fake()->image('big.jpg')->size(config('claims.upload.max_kb') + 1);

        $this->actingAs($user)->post(route('claims.store'), ['receipts' => [$big]])->assertSessionHasErrors('receipts.0');
        $this->assertSame(0, Receipt::count());
    }

    public function test_ocr_failure_keeps_file_and_can_be_retried(): void
    {
        $this->ocr->fail = true;
        $user = $this->makeUser();
        $claim = $this->uploadNewClaim($user, [UploadedFile::fake()->image('r.jpg')]);
        $receipt = $claim->receipts->first();

        $this->assertSame('failed', $receipt->ocr_status);
        $this->assertNotEmpty($receipt->ocr_error);
        $this->assertStringNotContainsString(storage_path(), $receipt->ocr_error);
        Storage::disk('local')->assertExists($receipt->file_path);

        $this->ocr->fail = false;
        $this->post(route('receipts.ocr.retry', $receipt))->assertRedirect();

        $receipt->refresh();
        $this->assertSame('completed', $receipt->ocr_status);
        $this->assertNull($receipt->ocr_error);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ocr.retried', 'entity_id' => $receipt->id]);
    }

    public function test_owner_can_preview_and_download(): void
    {
        $user = $this->makeUser();
        $receipt = $this->uploadNewClaim($user, [UploadedFile::fake()->image('r.jpg')])->receipts->first();

        $this->get(route('receipts.preview', $receipt))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(route('receipts.download', $receipt))->assertOk()->assertDownload();
    }

    public function test_other_users_cannot_preview_download_retry_or_delete_receipt(): void
    {
        $owner = $this->makeUser();
        $receipt = $this->uploadNewClaim($owner, [UploadedFile::fake()->image('r.jpg')])->receipts->first();

        $this->actingAs($this->makeUser());
        $this->get(route('receipts.preview', $receipt))->assertForbidden();
        $this->get(route('receipts.download', $receipt))->assertForbidden();
        $this->post(route('receipts.ocr.retry', $receipt))->assertForbidden();
        $this->delete(route('receipts.destroy', $receipt))->assertForbidden();
        $this->post(route('claims.receipts.store', $receipt->claim), ['receipts' => [UploadedFile::fake()->image('x.jpg')]])->assertForbidden();
        $this->assertDatabaseHas('receipts', ['id' => $receipt->id]);
    }

    public function test_duplicate_file_produces_a_warning_but_is_not_rejected(): void
    {
        $user = $this->makeUser();
        $file = UploadedFile::fake()->image('r.jpg', 300, 300);
        $content = file_get_contents($file->getRealPath());

        $first = $this->uploadNewClaim($user, [UploadedFile::fake()->createWithContent('a.jpg', $content)]);
        $this->post(route('claims.store'), ['receipts' => [UploadedFile::fake()->createWithContent('b.jpg', $content)]])
            ->assertSessionHas('warnings', fn ($w) => str_contains($w[0], $first->claim_number));

        $this->assertSame(2, Receipt::count());
    }

    public function test_tampered_file_path_cannot_read_other_server_files(): void
    {
        $user = $this->makeUser();
        $receipt = $this->uploadNewClaim($user, [UploadedFile::fake()->image('r.jpg')])->receipts->first();
        Storage::disk('local')->put('secret.txt', 'TOP SECRET');

        foreach (['../secret.txt', 'receipts/../secret.txt', '../../../.env'] as $path) {
            $receipt->forceFill(['file_path' => $path])->save();
            $this->get(route('receipts.preview', $receipt))->assertNotFound();
            $this->get(route('receipts.download', $receipt))->assertNotFound();
        }
    }

    public function test_receipt_can_only_be_removed_while_claim_is_draft(): void
    {
        $user = $this->makeUser();
        $claim = $this->uploadNewClaim($user, [UploadedFile::fake()->image('r.jpg')]);
        $receipt = $claim->receipts->first();

        $claim->forceFill(['status' => 'submitted'])->save();
        $this->delete(route('receipts.destroy', $receipt))->assertForbidden();

        $claim->forceFill(['status' => 'draft'])->save();
        $this->delete(route('receipts.destroy', $receipt))->assertRedirect();
        $this->assertDatabaseMissing('receipts', ['id' => $receipt->id]);
        Storage::disk('local')->assertMissing($receipt->file_path);
    }
}
