<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// 上传的收据文件（JPG / PNG / PDF），一张报销单可以有多张。
// OCR 状态：pending → processing → completed / failed，failed 的可以重试。
class Receipt extends Model
{
    public const OCR_PENDING = 'pending';

    public const OCR_PROCESSING = 'processing';

    public const OCR_COMPLETED = 'completed';

    public const OCR_FAILED = 'failed';

    // file_path 是服务器上的内部路径，不能出现在前端，下载一律走 controller 做权限检查
    protected $hidden = ['file_path'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    // 报销单是软删除的，带上 withTrashed，policy 才能判断单已被删，查重提示里也能显示已删除的单号
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
