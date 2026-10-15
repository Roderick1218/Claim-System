<?php

namespace App\Services;

use App\Models\Claim;
use App\Models\User;
use Illuminate\Http\UploadedFile;

// 逐个保存上传的收据并同步跑 OCR，顺便收集重复文件的提醒。
// 同步处理的好处是用户上传完，页面一打开就能看到识别结果
class ReceiptUploadHandler
{
    public function __construct(
        private ReceiptStorageService $storage,
        private OcrService $ocr,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, string> 要 flash 给用户看的提醒
     */
    public function handle(Claim $claim, array $files, User $uploader): array
    {
        $warnings = [];
        // 每个文件按 OCR 超时再多给 10 秒，多文件上传时才不会被 PHP 默认的执行时间中断
        @set_time_limit(max(60, count($files) * ((int) config('claims.ocr.timeout', 60) + 10)));

        foreach ($files as $file) {
            $receipt = $this->storage->store($file, $claim, $uploader);

            $duplicates = $this->storage->duplicatesOf($receipt);
            if ($duplicates->isNotEmpty()) {
                // 只列出上传者自己看得到的单号，不泄露别人的单
                $visible = $duplicates
                    ->filter(fn ($d) => $d->claim && $uploader->can('view', $d->claim))
                    ->map(fn ($d) => $d->claim->claim_number.($d->claim->trashed() ? ' (deleted)' : ''))
                    ->unique()->implode(', ');

                $warnings[] = "\"{$receipt->original_filename}\" looks identical to a receipt uploaded before"
                    .($visible !== '' ? " (claim {$visible})" : '').'. Please check it is not a duplicate claim.';
            }

            $this->ocr->process($receipt);
        }

        return $warnings;
    }
}
