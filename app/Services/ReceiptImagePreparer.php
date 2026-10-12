<?php

namespace App\Services;

use GdImage;

/**
 * Prepares a receipt photo for Tesseract (GD only, no external tools):
 *  1. applies the phone's EXIF rotation,
 *  2. crops to the white paper area (drops table / tray / hands, which confuse the layout analysis),
 *  3. upscales small text — Tesseract needs roughly 20–30 px high characters.
 *
 * Tesseract's accuracy jumps around with text size (in testing, one photo read perfectly at 2× and badly at 1.5×,
 * another the other way round), so several scales are produced. The caller OCRs all of them plus the original
 * and keeps the best-scoring text.
 */
class ReceiptImagePreparer
{
    private const SCALES = [1.0, 1.5, 2.0, 2.5];

    private const MAX_WIDTH = 2000;         // larger gains nothing and slows Tesseract down

    private const MAX_PIXELS = 40_000_000;  // refuse absurd images (memory)

    /** @return array<int, string> temporary PNG paths (caller deletes them) */
    public function variants(string $path, string $tmpDir): array
    {
        if (! extension_loaded('gd')) {
            return [];
        }

        $info = @getimagesize($path);
        if ($info === false || $info[0] * $info[1] > self::MAX_PIXELS) {
            return [];
        }

        $im = @imagecreatefromstring((string) file_get_contents($path));
        if (! $im instanceof GdImage) {
            return [];
        }

        $rotated = $this->applyExifRotation($im, $path, $info['mime'] ?? '');
        $base = $this->cropToPaper($rotated);
        $changed = $base !== $im;
        imagefilter($base, IMG_FILTER_GRAYSCALE);

        $paths = [];
        foreach (self::SCALES as $scale) {
            // Scale 1.0 is only useful when rotation/cropping actually changed the image.
            if (($scale === 1.0 && ! $changed) || imagesx($base) * $scale > self::MAX_WIDTH) {
                continue;
            }

            $out = rtrim($tmpDir, '/\\').DIRECTORY_SEPARATOR.'prepared-'.bin2hex(random_bytes(8)).'.png';
            if (imagepng($scale === 1.0 ? $base : $this->scale($base, $scale), $out)) {
                $paths[] = $out;
            }
        }

        return $paths;
    }

    /** [x, y, width, height] of the bright, low-saturation paper area; the whole image if none is found. */
    public function paperBox(GdImage $im): array
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $step = max(1, intdiv(max($w, $h), 400));
        $cols = [];
        $rows = [];
        $nx = 0;
        $ny = 0;

        for ($y = 0; $y < $h; $y += $step) {
            $ny++;
            $nx = 0;
            for ($x = 0; $x < $w; $x += $step) {
                $nx++;
                $c = imagecolorat($im, $x, $y);
                $r = ($c >> 16) & 255;
                $g = ($c >> 8) & 255;
                $b = $c & 255;
                if (min($r, $g, $b) > 150 && max($r, $g, $b) - min($r, $g, $b) < 45) {
                    $cols[$x] = ($cols[$x] ?? 0) + 1;
                    $rows[$y] = ($rows[$y] ?? 0) + 1;
                }
            }
        }

        $xs = array_keys(array_filter($cols, fn ($v) => $v > $ny * 0.35));
        $ys = array_keys(array_filter($rows, fn ($v) => $v > $nx * 0.08));
        if ($xs === [] || $ys === []) {
            return [0, 0, $w, $h];
        }

        $pad = 15;
        $x0 = max(0, min($xs) - $pad);
        $y0 = max(0, min($ys) - $pad);
        $x1 = min($w, max($xs) + $pad);
        $y1 = min($h, max($ys) + $pad);

        // Too small to be the receipt (probably a white sticker / napkin) → keep everything.
        if (($x1 - $x0) < $w * 0.12 || ($y1 - $y0) < $h * 0.3) {
            return [0, 0, $w, $h];
        }

        return [$x0, $y0, $x1 - $x0, $y1 - $y0];
    }

    private function cropToPaper(GdImage $im): GdImage
    {
        [$x, $y, $w, $h] = $this->paperBox($im);
        if ($w >= imagesx($im) * 0.95 && $h >= imagesy($im) * 0.95) {
            return $im;
        }

        return imagecrop($im, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]) ?: $im;
    }

    private function scale(GdImage $im, float $factor): GdImage
    {
        $out = imagecreatetruecolor((int) round(imagesx($im) * $factor), (int) round(imagesy($im) * $factor));
        imagecopyresampled($out, $im, 0, 0, 0, 0, imagesx($out), imagesy($out), imagesx($im), imagesy($im));

        return $out;
    }

    private function applyExifRotation(GdImage $im, string $path, string $mime): GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $im;
        }

        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;

        return $angle !== 0 ? (imagerotate($im, $angle, 0) ?: $im) : $im;
    }
}
