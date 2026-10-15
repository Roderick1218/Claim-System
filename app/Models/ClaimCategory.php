<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// 报销类别（交通、餐饮等）。keywords 给 CategoryGuesser 自动建议类别用；
// requires_receipt 的类别提交时必须有收据；停用的类别不能新选，但旧单保留不动。
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
