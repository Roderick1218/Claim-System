<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadReceiptRequest;
use App\Models\Claim;
use App\Models\Receipt;
use App\Services\AuditLogger;
use App\Services\OcrService;
use App\Services\ReceiptStorageService;
use App\Services\ReceiptUploadHandler;
use Illuminate\Support\Str;
use RuntimeException;

class ReceiptController extends Controller
{
    public function __construct(private ReceiptStorageService $storage) {}

    public function store(UploadReceiptRequest $request, Claim $claim, ReceiptUploadHandler $uploads)
    {
        $this->authorize('update', $claim);

        $warnings = $uploads->handle($claim, $request->file('receipts', []), $request->user());

        return redirect()->route('claims.edit', $claim)
            ->with('success', 'Receipt uploaded. Check the OCR text and confirm the details.')
            ->with('warnings', $warnings);
    }

    public function preview(Receipt $receipt)
    {
        $this->authorize('view', $receipt);

        return response()->file($this->pathOr404($receipt), [
            'Content-Type' => $receipt->mime_type,
            'Content-Disposition' => 'inline; filename="'.$this->safeName($receipt).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }

    public function download(Receipt $receipt)
    {
        $this->authorize('view', $receipt);

        return response()->download($this->pathOr404($receipt), $this->safeName($receipt), [
            'Content-Type' => $receipt->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }

    public function retryOcr(Receipt $receipt, OcrService $ocr, AuditLogger $audit)
    {
        $this->authorize('retryOcr', $receipt);

        // OCR 是同步跑的，PHP 执行时限要比 OCR 的 timeout 再宽一点
        @set_time_limit((int) config('claims.ocr.timeout', 60) + 30);
        $audit->log('ocr.retried', 'receipt', $receipt->id, ['ocr_status' => $receipt->ocr_status], null);
        $ocr->process($receipt);

        if ($receipt->ocr_status !== Receipt::OCR_COMPLETED) {
            return back()->with('error', 'OCR failed: '.$receipt->ocr_error);
        }

        // Draft 状态下重跑 OCR，通常是想拿到更准的值，所以直接带 refill 回表单显示新结果。
        // 一样是高亮，按 Save / Submit 之前不会存。
        if ($receipt->claim->isDraft()) {
            return redirect()->route('claims.edit', ['claim' => $receipt->claim, 'refill' => 1])
                ->with('success', 'OCR completed — the form below now shows the new reading. Check it, then Save Draft / Submit for approval.');
        }

        return back()->with('success', 'OCR completed.');
    }

    public function destroy(Receipt $receipt)
    {
        $this->authorize('delete', $receipt);
        $claim = $receipt->claim;
        $this->storage->delete($receipt);

        return redirect()->route('claims.edit', $claim)->with('success', 'Receipt removed.');
    }

    private function pathOr404(Receipt $receipt): string
    {
        try {
            return $this->storage->absolutePath($receipt);
        } catch (RuntimeException) {
            abort(404, 'Receipt file not found.');
        }
    }

    /** 下载文件名用 claim 编号加上 slug 过的原文件名，原文件名是用户上传的，不能直接放进 header */
    private function safeName(Receipt $receipt): string
    {
        $ext = pathinfo($receipt->file_path, PATHINFO_EXTENSION);
        $base = Str::slug(pathinfo($receipt->original_filename, PATHINFO_FILENAME)) ?: 'receipt';

        return $receipt->claim->claim_number.'-'.$base.'.'.$ext;
    }
}
