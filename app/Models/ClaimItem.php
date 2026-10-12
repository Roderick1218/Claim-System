<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimItem extends Model
{
    protected $guarded = ['id', 'claim_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }
}
