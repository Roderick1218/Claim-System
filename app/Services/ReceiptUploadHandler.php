<?php

namespace App\Services;

use App\Models\Claim;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/** Stores each uploaded receipt, runs OCR on it synchronously and collects duplicate-file warnings. */
class ReceiptUploadHandler
{
    public function __construct(
        private ReceiptStorageService $storage,
        private OcrService $ocr,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, string> warnings to flash to the user
     */
    public function handle(Claim $claim, array $files, User $uploader): array
    {
        $warnings = [];
        @set_time_limit(max(60, count($files) * ((int) config('claims.ocr.timeout', 60) + 10)));

        foreach ($files as $file) {
            $receipt = $this->storage->store($file, $claim, $uploader);

            $duplicates = $this->storage->duplicatesOf($receipt);
            if ($duplicates->isNotEmpty()) {
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
