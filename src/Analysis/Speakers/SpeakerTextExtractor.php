<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

use GdImage;
use RichmondSunlight\VideoProcessor\Analysis\Bills\CropConfig;
use RichmondSunlight\VideoProcessor\Analysis\Bills\OcrEngineInterface;
use RuntimeException;

class SpeakerTextExtractor
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
            throw new RuntimeException('Failed to crop screenshot region.');
        }

        $region = $this->preprocessForOcr($region);

        $temp = tempnam(sys_get_temp_dir(), 'ocr_') . '.jpg';
        imagejpeg($region, $temp, 95);
        $text = $this->ocr->extractText($temp);
        @unlink($temp);
        return $text;
    }

    private function preprocessForOcr(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = 3;
        $scaled = imagescale(
            $image,
            max(1, (int) ($width * $scale)),
            max(1, (int) ($height * $scale)),
            IMG_BICUBIC
        );
        if ($scaled !== false) {
            $image = $scaled;
        }
        imagefilter($image, IMG_FILTER_GRAYSCALE);
        imagefilter($image, IMG_FILTER_CONTRAST, -35);
        imagefilter($image, IMG_FILTER_BRIGHTNESS, 15);

        return $image;
    }
}
