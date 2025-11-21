<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

use RuntimeException;

class BillTextExtractor
{
    public function __construct(private OcrEngineInterface $ocr)
    {
    }

    public function extract(string $chamber, string $imagePath, CropConfig $crop): string
    {
        if (!function_exists('imagecreatefromjpeg')) {
            return $this->ocr->extractText($imagePath);
        }

        $src = @imagecreatefromjpeg($imagePath);
        if (!$src) {
            return $this->ocr->extractText($imagePath);
        }
        $width = imagesx($src);
        $height = imagesy($src);
        $x = (int) floor($crop->xPercent * $width);
        $y = (int) floor($crop->yPercent * $height);
        $w = (int) floor($crop->widthPercent * $width);
        $h = (int) floor($crop->heightPercent * $height);
        $w = min($w, $width - $x);
        $h = min($h, $height - $y);
        $region = imagecrop($src, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
        if ($region === false) {
            imagedestroy($src);
            throw new RuntimeException('Failed to crop screenshot region.');
        }
        $temp = tempnam(sys_get_temp_dir(), 'ocr_') . '.jpg';
        imagejpeg($region, $temp, 95);
        imagedestroy($region);
        imagedestroy($src);
        $text = $this->ocr->extractText($temp);
        @unlink($temp);
        return $text;
    }
}
