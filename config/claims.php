<?php

return [

    'currency' => 'MYR',

    'upload' => [
        // Per file, in kilobytes.
        'max_kb' => (int) env('RECEIPT_MAX_KB', 10240),
        'max_files' => (int) env('RECEIPT_MAX_FILES', 10),
        'mimes' => ['image/jpeg', 'image/png', 'application/pdf'],
        'extensions' => ['jpg', 'jpeg', 'png', 'pdf'],
        // Private disk (storage/app/private) — never the public disk.
        'disk' => 'local',
        'directory' => 'receipts',
    ],

    'ocr' => [
        // Full path to tesseract.exe; set in .env on each server. Never taken from user input.
        'tesseract_path' => env('TESSERACT_PATH', 'tesseract'),
        // Only use language packs that are actually installed (`tesseract --list-langs`), e.g. eng+msa+chi_sim.
        'languages' => env('TESSERACT_LANG', 'eng'),
        // Page segmentation modes, all tried; the best-scoring reading wins.
        // 4 = single column (best for till receipts), 6 = uniform block (best for A4 invoices with a table:
        // psm 4 dropped the Qty / Unit Price / Amount columns entirely).
        'psm' => array_values(array_filter(
            array_map('intval', explode(',', (string) env('TESSERACT_PSM', '4,6'))),
            fn ($p) => $p >= 0 && $p <= 13,
        )) ?: [4],
        'timeout' => (int) env('OCR_TIMEOUT', 60),
        // Optional Poppler tools for PDF receipts. Leave empty if not installed: PDF OCR then fails gracefully.
        'pdftotext_path' => env('PDFTOTEXT_PATH', ''),
        'pdftoppm_path' => env('PDFTOPPM_PATH', ''),
        'pdf_max_pages' => (int) env('PDF_MAX_PAGES', 3),
        // Max OCR retries per user per minute.
        'retry_per_minute' => (int) env('OCR_RETRY_PER_MINUTE', 6),
    ],

];
