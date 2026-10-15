<?php

namespace App\Services;

use GdImage;

/**
 * 把收据照片处理成 Tesseract 比较好读的样子，只用 GD，不靠外部工具：
 * 先按手机的 EXIF 转正，再裁到白色纸张范围（桌面、托盘、手会搞乱版面分析），
 * 最后把小字放大，Tesseract 大概要 20–30 px 高的字才读得准。
 *
 * Tesseract 的准确度跟字的大小关系很不稳定：测试时有张照片 2× 读得完美、1.5× 很差，另一张刚好相反。
 * 所以输出好几个尺寸，由调用方连同原图全部 OCR，取分数最高的。
 */
class ReceiptImagePreparer
{
    private const SCALES = [1.0, 1.5, 2.0, 2.5];

    private const MAX_WIDTH = 2000;         // 再大也没帮助，只会拖慢 Tesseract

    private const MAX_PIXELS = 40_000_000;  // 超大图片直接不处理，免得爆内存

    /** @return array<int, string> 临时 PNG 路径，用完由调用方删除 */
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
            // 1.0 倍只有在转正或裁切真的改变了图片时才有意义，不然跟原图一样
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

    /**
     * 找出偏白、低饱和度的纸张范围，返回 [x, y, width, height]；找不到就返回整张图。
     * 为了速度只抽样，长边大约取 400 个点。
     */
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

        // 范围太小不像收据，多半是白色贴纸或纸巾，那就整张保留
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
