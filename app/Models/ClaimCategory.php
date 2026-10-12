<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'keywords', 'requires_receipt', 'is_active', 'created_by'])]
class ClaimCategory extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['requires_receipt' => 'boolean', 'is_active' => 'boolean'];
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class, 'category_id');
    }
}
