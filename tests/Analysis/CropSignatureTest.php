<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\CropConfig;
use RichmondSunlight\VideoProcessor\Analysis\CropSignature;

class CropSignatureTest extends TestCase
{
    /** @var string[] */
    private array $files = [];

    /** The right half of the frame. */
    private CropConfig $crop;

    protected function setUp(): void
    {
        if (!function_exists('imagecreatefromjpeg')) {
            $this->markTestSkipped('GD is required.');
        }
        $this->crop = new CropConfig(0.5, 0.0, 0.5, 1.0);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    /**
     * A 200x100 grey frame; $draw paints on it.
     */
    private function frame(?callable $draw = null, int $grey = 100): string
    {
        $image = imagecreatetruecolor(200, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, $grey, $grey, $grey));
        if ($draw) {
            $draw($image);
        }
        $path = tempnam(sys_get_temp_dir(), 'sig_') . '.jpg';
        imagejpeg($image, $path, 90);
        $this->files[] = $path;
        return $path;
    }

    private function changed(string $before, string $after): bool
    {
        return CropSignature::changed(
            CropSignature::of($before, $this->crop),
            CropSignature::of($after, $this->crop)
        );
    }

    public function testIdenticalFramesAreUnchanged(): void
    {
        $this->assertFalse($this->changed($this->frame(), $this->frame()));
    }

    public function testSlightNoiseIsUnchanged(): void
    {
        $this->assertFalse($this->changed($this->frame(null, 100), $this->frame(null, 108)));
    }

    /**
     * A dark plate across the crop with white text, drawn at twice GD's
     * largest font so it fills the plate the way a real one does.
     */
    private function plate(string $text): callable
    {
        return function ($image) use ($text) {
            $small = imagecreatetruecolor(50, 16);
            imagefill($small, 0, 0, imagecolorallocate($small, 20, 20, 120));
            imagestring($small, 5, 0, 0, $text, imagecolorallocate($small, 255, 255, 255));
            imagecopyresized($image, $small, 100, 0, 0, 0, 100, 100, 50, 16);
        };
    }

    public function testAPlateAppearingIsAChange(): void
    {
        $this->assertTrue($this->changed($this->frame(), $this->frame($this->plate('Smith'))));
    }

    public function testDifferentTextOnThePlateIsAChange(): void
    {
        $this->assertTrue($this->changed($this->frame($this->plate('Smith')), $this->frame($this->plate('Jones'))));
    }

    public function testTheSamePlateIsUnchanged(): void
    {
        $this->assertFalse($this->changed($this->frame($this->plate('Smith')), $this->frame($this->plate('Smith'))));
    }

    public function testChangesOutsideTheCropAreIgnored(): void
    {
        $outside = fn($image) => imagefilledrectangle($image, 0, 0, 90, 99, imagecolorallocate($image, 255, 255, 255));
        $this->assertFalse($this->changed($this->frame(), $this->frame($outside)));
    }

    /**
     * A name plate fading in brightens every pixel a little at a time. Compared
     * with the last frame actually read, rather than the one just before, the
     * change adds up until it counts.
     */
    public function testAFadeCountsOnceItAddsUp(): void
    {
        $read = $this->frame(null, 100);
        $this->assertFalse($this->changed($read, $this->frame(null, 110)));
        $this->assertTrue($this->changed($read, $this->frame(null, 130)));
    }

    public function testAnUnreadableFrameAlwaysCountsAsChanged(): void
    {
        $bogus = tempnam(sys_get_temp_dir(), 'sig_');
        $this->files[] = $bogus;
        file_put_contents($bogus, 'not an image');

        $this->assertNull(CropSignature::of($bogus, $this->crop));
        $this->assertTrue(CropSignature::changed(null, null));
        $this->assertTrue(CropSignature::changed(CropSignature::of($this->frame(), $this->crop), null));
    }
}
