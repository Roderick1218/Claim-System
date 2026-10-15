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

// 收据文件的存取。文件放在私有 disk，不在 public 目录，只能经过权限检查后由 controller 读出来
class ReceiptStorageService
{
    // 扩展名由检测到的 MIME 决定，不用用户文件名上的，防止把 .php 之类的东西存进来
    private const EXTENSION_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    public function __construct(private AuditLogger $audit) {}

    public function store(UploadedFile $file, Claim $claim, User $uploader): Receipt
    {
        $mime = $file->getMimeType(); // 按文件内容检测，不信浏览器传上来的 type
        $extension = self::EXTENSION_BY_MIME[$mime] ?? null;

        if ($extension === null || ! $this->contentLooksValid($file->getRealPath(), $mime)) {
            throw ValidationException::withMessages([
                'receipts' => "The file \"{$this->displayName($file)}\" is not a valid JPG, PNG or PDF.",
            ]);
        }

        // 存盘用随机 UUID 文件名，原文件名只存数据库作显示用，避免重名和路径注入
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
            'file_hash' => hash_file('sha256', $file->getRealPath()), // 查重用
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

    // 返回文件的绝对路径，并确认它真的在收据目录里面。
    // 数据库里的 file_path 万一被改成 ../ 之类（path traversal），或者文件不见了，直接抛异常
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
            // 文件已经不在了，记录照样删
        }

        $this->audit->log('receipt.deleted', 'receipt', $receipt->id, [
            'claim_id' => $receipt->claim_id,
            'original_filename' => $receipt->original_filename,
        ], null);

        $receipt->delete();
    }

    /**
     * 找出 SHA-256 一样的其他收据，可能是重复报销。只提醒不拦截，由用户和审批人自己判断。
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

    // 检查文件内容是不是真的 JPG / PNG / PDF，防止改了扩展名的其他文件混进来
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
        // 去掉目录部分和控制字符，只用来显示
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'receipt';

        return Str::limit($name, 200, '');
    }
}
