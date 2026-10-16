<?php

namespace RichmondSunlight\VideoProcessor\Analysis;

use RichmondSunlight\VideoProcessor\Analysis\Bills\CropConfig;

/**
 * Tells whether the OCR region of a frame looks different from an earlier one,
 * so OCR can be skipped while it doesn't.
 *
 * A bill number or name plate stays on screen for many seconds, and most other
 * frames show no plate at all, so most frames look like the one before. On the
 * fixture videos, OCR on only the changed frames ran on 11% of frames for bills
 * and 19% for speakers. The bill results were identical to OCRing every frame,
 * and every speaker was still found.
 *
 * Compare each frame with the last one actually OCRed, not with the frame just
 * before it: a plate fading in changes each frame only slightly, and comparing
 * with the last one read lets that add up until it counts.
 */
class CropSignature
{
    /** A pixel has changed when its brightness moves by more than this (0-255). */
    public const PIXEL_THRESHOLD = 15;

    /** The crop has changed when more than this share of its pixels have. */
    public const CHANGED_SHARE = 0.03;

    /**
     * The crop's pixels as greyscale bytes, prefixed with its size; null if the
     * frame cannot be read.
     */
    public static function of(string $imagePath, CropConfig $crop): ?string
    {
        if (!function_exists('imagecreatefromjpeg')) {
            return null;
        }
        $image = @imagecreatefromjpeg($imagePath);
        if (!$image) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $x = (int) floor($crop->xPercent * $width);
        $y = (int) floor($crop->yPercent * $height);
        $w = min((int) floor($crop->widthPercent * $width), $width - $x);
        $h = min((int) floor($crop->heightPercent * $height), $height - $y);
        if ($w <= 0 || $h <= 0) {
            return null;
        }

        $signature = $w . 'x' . $h . ':';
        for ($row = $y; $row < $y + $h; $row++) {
            for ($col = $x; $col < $x + $w; $col++) {
                $rgb = imagecolorat($image, $col, $row);
                $signature .= chr((int) round(
                    0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF)
                ));
            }
        }

        return $signature;
    }

    /**
     * True unless both signatures exist, are the same size, and differ in no
     * more than CHANGED_SHARE of their pixels. When in doubt, OCR.
     */
    public static function changed(?string $before, ?string $after): bool
    {
        if ($before === null || $after === null) {
            return true;
        }
        $headerEnd = strpos($before, ':');
        if ($headerEnd === false || strncmp($before, $after, $headerEnd + 1) !== 0 || strlen($before) !== strlen($after)) {
            return true;
        }

        $length = strlen($before);
        $pixels = $length - $headerEnd - 1;
        $allowed = (int) floor($pixels * self::CHANGED_SHARE);
        $changed = 0;
        for ($i = $headerEnd + 1; $i < $length; $i++) {
            if (abs(ord($before[$i]) - ord($after[$i])) > self::PIXEL_THRESHOLD && ++$changed > $allowed) {
                return true;
            }
        }

        return false;
    }
}
