<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\ClaimCategory;
use App\Services\CategoryGuesser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CategoryGuesserTest extends TestCase
{
    use RefreshDatabase;

    private function guess(?string $merchant, string $text = ''): ?string
    {
        return app(CategoryGuesser::class)->guess($merchant, $text)['name'] ?? null;
    }

    public function test_keywords_pick_the_obvious_category(): void
    {
        $this->assertSame('Food & Beverage', $this->guess('KFC RESTAURANTS (MALAYSIA) SDN BHD', "1 PEPSI (L) 3.90\nTOTAL RM 40.49"));
        $this->assertSame('Food & Beverage', $this->guess('Tronwil Sdn Bhd', "Dine In\n1 Nasi Lemak 16.90\n1 Coke 3.90\nNet Total 94.75"));
        $this->assertSame('Petrol / Fuel', $this->guess('PETRONAS SS2', "RON95 30.000 L 2.05\nTOTAL 61.50"));
        $this->assertSame('Toll', $this->guess('PLUS MALAYSIA BERHAD', 'Plaza Tol Sungai Besi 2.90'));
        $this->assertSame('Parking', $this->guess('Secure Parking', "Entry time 09:00\nExit time 11:00\nDuration 2h"));
        $this->assertSame('Materials', $this->guess('Systematic Electrical Engineering Sdn. Bhd', 'To supply & install with LED flood light'));
        $this->assertSame('Accommodation', $this->guess('Hotel Seri Malaysia', "Room 1 night\nTourism tax 10.00"));
        $this->assertSame('Office Supplies', $this->guess('POPULAR BOOK CO', 'A4 paper 2 ream 25.00'));
    }

    public function test_nothing_is_suggested_when_unclear(): void
    {
        $this->assertNull($this->guess('ABC SDN BHD', "ITEM 1 10.00\nTOTAL 10.00"));
        $this->assertNull($this->guess(null, ''));
    }

    public function test_history_of_the_same_merchant_wins_over_keywords(): void
    {
        $user = $this->makeUser();
        // The company records this "Coffee" supplier as Office Supplies (e.g. pantry stock).
        $this->makeClaim($user, ['merchant_name' => 'ZUS COFFEE (M) SDN BHD', 'category_id' => $this->category('Office Supplies')->id]);

        $result = app(CategoryGuesser::class)->guess('ZUS Coffee (M) Sdn. Bhd.', 'SPANISH LATTE 15.90');

        $this->assertSame('Office Supplies', $result['name']);
        $this->assertSame('history', $result['source']);
    }

    public function test_inactive_categories_and_admin_keywords(): void
    {
        $this->category('Food & Beverage')->update(['is_active' => false]);
        $this->assertNotSame('Food & Beverage', $this->guess('KFC', 'Dine In'));

        ClaimCategory::create(['name' => 'Client Entertainment', 'keywords' => "wine\nkaraoke", 'requires_receipt' => true, 'is_active' => true]);
        $this->assertSame('Client Entertainment', $this->guess('Red Box Karaoke', 'Room 3 hours, wine 2 bottles'));
    }

    public function test_edit_page_auto_selects_category_highlighted_with_reason_but_does_not_save_it(): void
    {
        $this->ocr->text = "KFC RESTAURANTS (MALAYSIA) SDN BHD\nDATE: 05/10/2026 14:30\n1 PEPSI (L) 3.90\nTOTAL 3.90";
        $user = $this->makeUser();
        $this->actingAs($user)->post(route('claims.store'), ['receipts' => [UploadedFile::fake()->image('r.png', 400, 400)]]);
        $claim = Claim::latest('id')->firstOrFail();
        $food = $this->category('Food & Beverage');

        $this->get(route('claims.edit', $claim))
            ->assertSee('<select name="category_id" class="input ocr-filled">', false)
            ->assertSee('<option value="'.$food->id.'" selected', false)
            ->assertSee('Auto-selected — receipt mentions kfc');

        $this->assertNull($claim->fresh()->category_id);
    }

    public function test_category_already_chosen_is_not_overwritten(): void
    {
        $this->ocr->text = "KFC RESTAURANTS\nTOTAL 3.90";
        $user = $this->makeUser();
        $this->actingAs($user)->post(route('claims.store'), ['receipts' => [UploadedFile::fake()->image('r.png', 400, 400)]]);
        $claim = Claim::latest('id')->firstOrFail();
        $claim->forceFill(['category_id' => $this->category('Transport')->id])->save();

        $this->get(route('claims.edit', $claim))
            ->assertSee('<select name="category_id" class="input ">', false)
            ->assertDontSee('Auto-selected');
    }
}
