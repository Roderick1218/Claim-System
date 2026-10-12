<?php

namespace Tests;

use App\Models\Claim;
use App\Models\ClaimCategory;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OcrService;
use Database\Seeders\ClaimCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeOcrService;

abstract class TestCase extends BaseTestCase
{
    protected FakeOcrService $ocr;

    protected function setUp(): void
    {
        parent::setUp();

        if (in_array(RefreshDatabase::class, class_uses_recursive($this))) {
            $this->seed([RolePermissionSeeder::class, ClaimCategorySeeder::class]);
        }

        Storage::fake('local');
        $this->ocr = $this->app->make(FakeOcrService::class);
        $this->app->instance(OcrService::class, $this->ocr);
    }

    protected function makeUser(string $role = 'staff', ?Department $department = null, array $attributes = []): User
    {
        return User::factory()->withRole($role)->create(['department_id' => $department?->id] + $attributes);
    }

    /** Give a role extra permissions, as an admin would in Settings → Roles & Permissions. */
    protected function grant(string $role, string ...$permissions): void
    {
        Role::where('name', $role)->firstOrFail()->permissions()
            ->syncWithoutDetaching(Permission::whereIn('name', $permissions)->pluck('id'));
    }

    protected function category(string $name = 'Toll'): ClaimCategory
    {
        return ClaimCategory::where('name', $name)->firstOrFail();
    }

    /** A submitted (waiting-for-approval) claim created directly in the DB. */
    protected function makeClaim(User $owner, array $attributes = []): Claim
    {
        static $n = 0;
        $n++;

        $claim = new Claim(array_intersect_key($attributes, array_flip((new Claim)->getFillable())) + [
            'category_id' => $this->category()->id,
            'merchant_name' => 'Merchant '.$n,
            'expense_date' => now()->toDateString(),
        ]);
        $claim->user_id = $owner->id;
        $claim->claim_number = sprintf('CLM-TEST-%05d', $n);
        $claim->status = $attributes['status'] ?? Claim::STATUS_SUBMITTED;
        $claim->total_amount = $attributes['total_amount'] ?? '10.00';
        $claim->subtotal_amount = $claim->total_amount;
        $claim->save();

        return $claim;
    }
}
