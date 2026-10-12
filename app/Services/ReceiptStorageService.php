<?php

namespace App\Services;

use App\Models\Claim;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ReceiptStorageService
{
    private const EXTENSION_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    public function __construct(private AuditLogger $audit) {}

    public function store(UploadedFile $file, Claim $claim, User $uploader): Receipt
    {
        $mime = $file->getMimeType(); // detected from content, not the browser-supplied type
        $extension = self::EXTENSION_BY_MIME[$mime] ?? null;

        if ($extension === null || ! $this->contentLooksValid($file->getRealPath(), $mime)) {
            throw ValidationException::withMessages([
                'receipts' => "The file \"{$this->displayName($file)}\" is not a valid JPG, PNG or PDF.",
            ]);
        }

        // Random internal name; the original filename is only kept for display.
        $directory = config('claims.upload.directory').'/'.now()->format('Y/m');
        $path = $file->storeAs($directory, Str::uuid()->toString().'.'.$extension, config('claims.upload.disk'));

        if ($path === false) {
            throw new RuntimeException('Failed to store receipt file.');
        }

        $receipt = Receipt::create([
            'claim_id' => $claim->id,
            'original_filename' => $this->displayName($file),
            'file_path' => $path,
            'mime_type' => $mime,
            'file_size' => $file->getSize(),
            'file_hash' => hash_file('sha256', $file->getRealPath()),
            'ocr_status' => Receipt::OCR_PENDING,
            'uploaded_by' => $uploader->id,
        ]);

        $this->audit->log('receipt.uploaded', 'receipt', $receipt->id, null, [
            'claim_id' => $claim->id,
            'original_filename' => $receipt->original_filename,
            'mime_type' => $mime,
            'file_size' => $receipt->file_size,
        ]);

        return $receipt;
    }

    /**
     * Absolute path of the stored file, guaranteed to be inside the private receipts directory.
     * Throws if the stored path was tampered with (path traversal) or the file is missing.
     */
    public function absolutePath(Receipt $receipt): string
    {
        $disk = Storage::disk(config('claims.upload.disk'));
        $root = realpath($disk->path(config('claims.upload.directory')));
        $full = realpath($disk->path($receipt->file_path));

        if ($root === false || $full === false || ! str_starts_with($full, $root.DIRECTORY_SEPARATOR) || ! is_file($full)) {
            throw new RuntimeException('Receipt file is not available.');
        }

        return $full;
    }

    public function delete(Receipt $receipt): void
    {
        try {
            $path = $this->absolutePath($receipt);
            @unlink($path);
        } catch (RuntimeException) {
            // File already gone — still remove the record.
        }

        $this->audit->log('receipt.deleted', 'receipt', $receipt->id, [
            'claim_id' => $receipt->claim_id,
            'original_filename' => $receipt->original_filename,
        ], null);

        $receipt->delete();
    }

    /**
     * Other receipts with the same SHA-256 content (possible duplicate upload). Never rejects — only warns.
     *
     * @return Collection<int, Receipt>
     */
    public function duplicatesOf(Receipt $receipt)
    {
        return Receipt::query()
            ->with('claim')
            ->where('file_hash', $receipt->file_hash)
            ->whereKeyNot($receipt->id)
            ->get();
    }

    public function contentLooksValid(string $path, string $mime): bool
    {
        if ($mime === 'application/pdf') {
            $handle = fopen($path, 'rb');
            $header = $handle ? fread($handle, 5) : '';
            if ($handle) {
                fclose($handle);
            }

            return $header === '%PDF-';
        }

        $info = @getimagesize($path);

        return $info !== false && $info[0] > 0 && $info[1] > 0
            && in_array($info['mime'] ?? '', ['image/jpeg', 'image/png'], true);
    }

    private function displayName(UploadedFile $file): string
    {
        // Strip any directory parts and control characters; for display only.
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'receipt';

        return Str::limit($name, 200, '');
    }
}
