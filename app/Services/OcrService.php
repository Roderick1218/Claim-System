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
 * Local OCR only (Tesseract, optionally Poppler for PDFs). No cloud / AI service is called.
 *
 * Any failure marks the receipt as `failed` with a short, safe message; the claim itself is never affected.
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
            // Full detail stays in the server log; the user only sees a safe summary.
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

    /** Overridable in tests. */
    protected function extractText(string $absolutePath, string $mime): string
    {
        if ($mime === 'application/pdf') {
            return $this->extractFromPdf($absolutePath);
        }

        return $this->ocrImage($absolutePath);
    }

    /**
     * OCRs the original photo and several prepared copies (rotated, cropped to the paper, scaled)
     * and keeps the best-scoring text. No single version is always best, so all are tried.
     */
    private function ocrImage(string $imagePath): string
    {
        $modes = (array) config('claims.ocr.psm', [4]);

        // First reading outside the try: a missing / broken Tesseract must fail loudly.
        $best = $this->tesseract($imagePath, $modes[0]);
        $bestScore = $this->score($best);
        // Note: no early stop on the first reading whose items add up — tried 2026-10-12: amounts were right
        // but the header was worse (ZUS read as "US COFFEE (¥)", year 2026 as 2025). All readings are compared.

        $tmpDir = storage_path('app/private/ocr-tmp');
        File::ensureDirectoryExists($tmpDir);
        $variants = [];

        try {
            $variants = $this->preparer->variants($imagePath, $tmpDir);
            foreach ([$imagePath, ...$variants] as $n => $image) {
                foreach ($modes as $m => $psm) {
                    if ($n === 0 && $m === 0) {
                        continue; // already done above
                    }
                    $text = $this->tesseract($image, $psm);
                    $score = $this->score($text);
                    if ($score > $bestScore) {
                        [$best, $bestScore] = [$text, $score];
                    }
                }
            }
        } catch (OcrFailedException $e) {
            // e.g. a timeout on one variant — keep the best result so far.
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
     * Readability score. By far the strongest signal is arithmetic: when the items read from the text add up
     * exactly to the TOTAL, the reading is almost certainly right. Then amounts, items and real words count up,
     * noise lines count down.
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

        // Every argument is passed separately — nothing is concatenated into a shell command.
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

        // 1) Text layer (digital PDFs).
        if ($pdftotext !== '') {
            $text = $this->run([$pdftotext, '-layout', '-f', '1', '-l', (string) $maxPages, $pdfPath, '-'], 'PDF text reader');
            // pdftotext prints only form feeds (\f) for image-only pages; trim() alone would not strip those.
            if (preg_replace('/\s+/u', '', $text) !== '') {
                return $text;
            }
        }

        // 2) Scanned PDF: rasterise the first pages, then OCR each image.
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
            // Exit code 1 + "not recognized"/"cannot find" = binary missing; keep message generic for users.
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
