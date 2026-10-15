<?php

namespace App\Services;

use App\Models\Receipt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * 只用本机 OCR（Tesseract，PDF 另外可用 Poppler），不调用任何云端 / AI 服务。
 * 出错只会把收据标成 failed 并给一句可以让用户看的提示，claim 本身不受影响。
 */
class OcrService
{
    public function __construct(
        private ReceiptStorageService $storage,
        private ReceiptImagePreparer $preparer,
    ) {}

    public function process(Receipt $receipt): Receipt
    {
        $receipt->forceFill(['ocr_status' => Receipt::OCR_PROCESSING, 'ocr_error' => null])->save();

        try {
            $path = $this->storage->absolutePath($receipt);
            $text = $this->normalise($this->extractText($path, $receipt->mime_type));

            if ($text === '') {
                throw new OcrFailedException('No readable text was found on this receipt.');
            }

            $receipt->forceFill([
                'ocr_text' => $text,
                'ocr_status' => Receipt::OCR_COMPLETED,
                'ocr_error' => null,
                'processed_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            // 详细错误只写 server log，用户那边只给概括的提示
            Log::warning('Receipt OCR failed', ['receipt_id' => $receipt->id, 'error' => $e->getMessage()]);

            $receipt->forceFill([
                'ocr_status' => Receipt::OCR_FAILED,
                'ocr_error' => $e instanceof OcrFailedException
                    ? Str::limit($e->getMessage(), 250)
                    : 'OCR could not process this file. You can still enter the details manually.',
                'processed_at' => now(),
            ])->save();
        }

        return $receipt;
    }

    // 保持 protected，测试里会 override 掉，不用真的跑 Tesseract
    protected function extractText(string $absolutePath, string $mime): string
    {
        if ($mime === 'application/pdf') {
            return $this->extractFromPdf($absolutePath);
        }

        return $this->ocrImage($absolutePath);
    }

    // 原图加几个处理过的版本（转正、裁到纸张、放大）都跑一次 OCR，取分数最高的。
    // 没有哪个版本永远最好，所以全部都试
    private function ocrImage(string $imagePath): string
    {
        $modes = (array) config('claims.ocr.psm', [4]);

        // 第一次放在 try 外面：Tesseract 没装或坏了要直接报错，不能被吞掉
        $best = $this->tesseract($imagePath, $modes[0]);
        $bestScore = $this->score($best);
        // 不要在第一个加得到总额的结果就提早停。2026-10-12 试过：金额是对了，但表头反而更差
        // （ZUS 读成 "US COFFEE (¥)"，2026 年读成 2025），所以每个结果都要比

        $tmpDir = storage_path('app/private/ocr-tmp');
        File::ensureDirectoryExists($tmpDir);
        $variants = [];

        try {
            $variants = $this->preparer->variants($imagePath, $tmpDir);
            foreach ([$imagePath, ...$variants] as $n => $image) {
                foreach ($modes as $m => $psm) {
                    if ($n === 0 && $m === 0) {
                        continue; // 上面已经跑过
                    }
                    $text = $this->tesseract($image, $psm);
                    $score = $this->score($text);
                    if ($score > $bestScore) {
                        [$best, $bestScore] = [$text, $score];
                    }
                }
            }
        } catch (OcrFailedException $e) {
            // 例如某个版本 timeout，用目前最好的结果就行
            Log::info('Receipt OCR variant skipped', ['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::info('Receipt image preparation skipped', ['error' => $e->getMessage()]);
        } finally {
            foreach ($variants as $variant) {
                @unlink($variant);
            }
        }

        return $best;
    }

    /**
     * OCR 结果的可读性分数。最强的信号是算术：项目刚好加到 TOTAL，基本上就是读对了。
     * 其次是金额、项目数和正常单词加分，杂讯行扣分。
     */
    public function score(string $text): int
    {
        $hints = app(ReceiptTextHints::class)->find($text);
        $amounts = preg_match_all('/\b\d{1,6}\.\d{2}\b/', $text);
        $words = preg_match_all('/\b[a-z]{3,}\b/i', $text);
        $noise = 0;
        foreach (preg_split('/\n/', $text) as $line) {
            $line = trim($line);
            if ($line !== '' && preg_match_all('/[a-z0-9]/i', $line) < mb_strlen($line) * 0.5) {
                $noise++;
            }
        }

        return ($hints['items_match_total'] ? 100 : 0)
            + ($hints['totals'] !== [] ? 10 : 0)
            + count($hints['items']) * 4
            + $amounts * 3
            + $words
            - $noise * 2;
    }

    private function tesseract(string $imagePath, ?int $psm = null): string
    {
        $binary = (string) config('claims.ocr.tesseract_path');
        $languages = (string) config('claims.ocr.languages', 'eng');

        if (! preg_match('/^[a-z_]+(\+[a-z_]+)*$/i', $languages)) {
            throw new OcrFailedException('OCR language setting is invalid. Please contact the administrator.');
        }

        $psm ??= ((array) config('claims.ocr.psm', [4]))[0];
        if ($psm < 0 || $psm > 13) {
            $psm = 4;
        }

        // 参数逐个传给 Process，不拼 shell 字符串，路径里有空格或特殊字符也不怕被注入
        return $this->run([$binary, $imagePath, 'stdout', '-l', $languages, '--psm', (string) $psm], 'OCR engine');
    }

    private function extractFromPdf(string $pdfPath): string
    {
        $pdftotext = (string) config('claims.ocr.pdftotext_path');
        $pdftoppm = (string) config('claims.ocr.pdftoppm_path');
        $maxPages = max(1, (int) config('claims.ocr.pdf_max_pages', 3));

        if ($pdftotext === '' && $pdftoppm === '') {
            throw new OcrFailedException('PDF reading is not set up on this server yet. Please enter the details manually, or upload a JPG/PNG photo of the receipt.');
        }

        // 先试电子 PDF 的文字层
        if ($pdftotext !== '') {
            $text = $this->run([$pdftotext, '-layout', '-f', '1', '-l', (string) $maxPages, $pdfPath, '-'], 'PDF text reader');
            // 纯图片的页面 pdftotext 只会输出换页符 \f，光靠 trim() 去不掉
            if (preg_replace('/\s+/u', '', $text) !== '') {
                return $text;
            }
        }

        // 扫描的 PDF：前几页转成图片再逐页 OCR
        if ($pdftoppm === '') {
            throw new OcrFailedException('This PDF has no text layer and scanned-PDF OCR is not set up. Please enter the details manually.');
        }

        $tmpDir = storage_path('app/private/ocr-tmp/'.Str::uuid());
        File::ensureDirectoryExists($tmpDir);

        try {
            $this->run([$pdftoppm, '-r', '200', '-f', '1', '-l', (string) $maxPages, '-png', $pdfPath, $tmpDir.DIRECTORY_SEPARATOR.'page'], 'PDF converter');

            $pages = collect(File::files($tmpDir))->sortBy(fn ($f) => $f->getFilename())->values();
            if ($pages->isEmpty()) {
                throw new OcrFailedException('The PDF could not be converted for OCR.');
            }

            return $pages->map(fn ($f) => $this->tesseract($f->getPathname()))->implode("\n\n");
        } finally {
            File::deleteDirectory($tmpDir);
        }
    }

    private function run(array $command, string $toolLabel): string
    {
        $process = new Process($command);
        $process->setTimeout((int) config('claims.ocr.timeout', 60));

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new OcrFailedException('OCR took too long and was stopped. Please try again or enter the details manually.');
        } catch (Throwable $e) {
            throw new RuntimeException("{$toolLabel} could not be started: ".$e->getMessage());
        }

        if (! $process->isSuccessful()) {
            // exit code 1 加 "not recognized" / "cannot find" 通常是程序没装；stderr 只进 log，给用户的提示保持笼统
            Log::warning("{$toolLabel} exited with an error", [
                'exit_code' => $process->getExitCode(),
                'stderr' => Str::limit($process->getErrorOutput(), 1000),
            ]);

            throw new OcrFailedException("{$toolLabel} failed (exit code {$process->getExitCode()}). Please try again or enter the details manually.");
        }

        return $process->getOutput();
    }

    private function normalise(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\f"], ["\n", "\n", "\n"], $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim((string) $text);
    }
}
