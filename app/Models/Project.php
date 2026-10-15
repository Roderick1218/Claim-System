<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// 项目 / 工程，报销单可以选填挂到某个项目下，方便按项目统计开销
#[Fillable(['project_code', 'name', 'customer_name', 'description', 'is_active'])]
class Project extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function getLabelAttribute(): string
    {
        return $this->project_code ? "{$this->project_code} — {$this->name}" : $this->name;
    }
}
