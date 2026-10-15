<?php

namespace Tests\Support;

use App\Services\OcrFailedException;
use App\Services\OcrService;

/** 测试时只替换掉调用 Tesseract 那一步，状态处理、文件检查等其余部分都是真的代码 */
class FakeOcrService extends OcrService
{
    public string $text = "ABC TRADING\nDATE: 05/10/2026 14:30\nMineral Water 2 x 2.00 4.00\nTOTAL 4.00";

    public bool $fail = false;

    public int $calls = 0;

    protected function extractText(string $absolutePath, string $mime): string
    {
        $this->calls++;

        if (! is_file($absolutePath)) {
            throw new \RuntimeException('File not found');
        }

        if ($this->fail) {
            throw new OcrFailedException('OCR engine failed (exit code 1). Please try again or enter the details manually.');
        }

        return $this->text;
    }
}
