<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends Model
{
    public const OCR_PENDING = 'pending';

    public const OCR_PROCESSING = 'processing';

    public const OCR_COMPLETED = 'completed';

    public const OCR_FAILED = 'failed';

    // file_path is internal: never expose it to the browser.
    protected $hidden = ['file_path'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class)->withTrashed();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function getFileSizeLabelAttribute(): string
    {
        return $this->file_size >= 1048576
            ? number_format($this->file_size / 1048576, 1).' MB'
            : number_format($this->file_size / 1024, 0).' KB';
    }
}
