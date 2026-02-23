<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Classification;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\OcrEngineInterface;
use RichmondSunlight\VideoProcessor\Analysis\Classification\FrameClassifier;

class FrameClassifierTest extends TestCase
{
    public function testDarkFrameDetectedAsTitleCard(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required.');
        }

        $image = imagecreatetruecolor(200, 200);
        $navy = imagecolorallocate($image, 10, 10, 46);
        imagefill($image, 0, 0, $navy);
        $path = tempnam(sys_get_temp_dir(), 'dark_') . '.jpg';
        imagejpeg($image, $path);
        imagedestroy($image);

        $ocr = $this->createMock(OcrEngineInterface::class);
        $ocr->method('extractText')->willReturn('Committee on Agriculture');

        $classifier = new FrameClassifier($ocr);
        $this->assertTrue($classifier->isTitleCard($path));

        $result = $classifier->classify($path, 'house');
        $this->assertSame('committee', $result['event_type']);
        $this->assertNotNull($result['committee_name']);

        @unlink($path);
    }

    public function testPhotographicFrameDetectedAsFloor(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required.');
        }

        $image = imagecreatetruecolor(200, 200);
        // Simulate a bright, varied photographic image
        for ($x = 0; $x < 200; $x += 10) {
            for ($y = 0; $y < 200; $y += 10) {
                $color = imagecolorallocate($image, rand(100, 255), rand(100, 255), rand(100, 255));
                imagefilledrectangle($image, $x, $y, $x + 9, $y + 9, $color);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'bright_') . '.jpg';
        imagejpeg($image, $path);
        imagedestroy($image);

        $ocr = $this->createMock(OcrEngineInterface::class);
        $classifier = new FrameClassifier($ocr);

        $this->assertFalse($classifier->isTitleCard($path));

        $result = $classifier->classify($path, 'house');
        $this->assertSame('floor', $result['event_type']);
        $this->assertNull($result['committee_name']);

        @unlink($path);
    }

    public function testSubcommitteeDetectedFromOcrText(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required.');
        }

        $image = imagecreatetruecolor(200, 200);
        $navy = imagecolorallocate($image, 10, 10, 46);
        imagefill($image, 0, 0, $navy);
        $path = tempnam(sys_get_temp_dir(), 'dark_') . '.jpg';
        imagejpeg($image, $path);
        imagedestroy($image);

        $ocrText = "House of Delegates\nSubcommittee on Public Safety\nThe Meeting Will Begin Shortly";
        $ocr = $this->createMock(OcrEngineInterface::class);
        $ocr->method('extractText')->willReturn($ocrText);

        $classifier = new FrameClassifier($ocr);
        $result = $classifier->classify($path, 'house');

        $this->assertSame('subcommittee', $result['event_type']);
        $this->assertNotNull($result['committee_name']);

        @unlink($path);
    }

    public function testCommitteeNameExtraction(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required.');
        }

        $image = imagecreatetruecolor(200, 200);
        $navy = imagecolorallocate($image, 10, 10, 46);
        imagefill($image, 0, 0, $navy);
        $path = tempnam(sys_get_temp_dir(), 'dark_') . '.jpg';
        imagejpeg($image, $path);
        imagedestroy($image);

        $ocrText = "Virginia\nHouse Committee on Education\nTuesday, January 21\n10:00 a.m.";
        $ocr = $this->createMock(OcrEngineInterface::class);
        $ocr->method('extractText')->willReturn($ocrText);

        $classifier = new FrameClassifier($ocr);
        $result = $classifier->classify($path, 'house');

        $this->assertSame('committee', $result['event_type']);
        $this->assertSame('Education', $result['committee_name']);

        @unlink($path);
    }
}
