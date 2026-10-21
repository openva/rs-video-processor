<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Speakers;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotFetcher;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotMissingException;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotManifestLoader;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\OcrSpeakerExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerChamberConfig;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerNameParser;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerTextExtractor;

class OcrSpeakerExtractorTest extends TestCase
{
    /**
     * @param array<int,?string> $namesBySecond the name on screen at each second, or null
     * @param string[] $fetched filled with the frames that were read
     */
    private function extractor(array $namesBySecond, int $frameStep, array &$fetched): OcrSpeakerExtractor
    {
        $manifest = [];
        foreach (array_keys($namesBySecond) as $second) {
            $manifest[] = ['timestamp' => $second, 'full' => "https://example.test/{$second}.jpg", 'thumb' => null];
        }
        $loader = $this->createMock(ScreenshotManifestLoader::class);
        $loader->method('load')->willReturn($manifest);

        $fetcher = $this->createMock(ScreenshotFetcher::class);
        $fetcher->method('fetch')->willReturnCallback(function (string $url) use (&$fetched) {
            $fetched[] = basename($url);
            return $url;
        });

        // The fake "image path" is the URL, so the text read from it is its second.
        $textExtractor = $this->createMock(SpeakerTextExtractor::class);
        $textExtractor->method('extract')->willReturnCallback(
            fn(string $chamber, string $path) => (string) (int) basename($path, '.jpg')
        );

        $parser = $this->createMock(SpeakerNameParser::class);
        $parser->method('parse')->willReturnCallback(fn(string $text) => $namesBySecond[(int) $text]);

        return new OcrSpeakerExtractor($loader, $fetcher, $textExtractor, $parser, new SpeakerChamberConfig(), 3, null, $frameStep);
    }

    public function testGivesUpWhenScreenshotsAreMissing(): void
    {
        $manifest = [];
        for ($i = 1; $i <= 500; $i++) {
            $manifest[] = ['timestamp' => $i, 'full' => sprintf('https://video.richmondsunlight.com/house/floor/20260116/%08d.jpg', $i)];
        }
        $loader = $this->createMock(ScreenshotManifestLoader::class);
        $loader->method('load')->willReturn($manifest);
        $fetcher = $this->createMock(ScreenshotFetcher::class);
        $fetcher->expects($this->exactly(ScreenshotMissingException::LIMIT_IN_A_ROW))->method('fetch')
            ->willThrowException(new ScreenshotMissingException('Screenshot not found'));
        $extractor = new OcrSpeakerExtractor($loader, $fetcher, $this->createMock(SpeakerTextExtractor::class),
            new SpeakerNameParser(), new SpeakerChamberConfig(), 3);

        $this->expectExceptionMessage('25 screenshots in a row are missing for the video at https://video.richmondsunlight.com/house/floor/20260116/manifest.json');
        $extractor->extract('https://video.richmondsunlight.com/house/floor/20260116/manifest.json', 'house', 'floor', '2026-01-16');
    }

    public function testReadsEveryFrameByDefault(): void
    {
        $fetched = [];
        $names = ['Smith', 'Smith', 'Smith', null];
        $segments = $this->extractor($names, 1, $fetched)->extract('https://example.test/manifest.json', 'senate', 'floor');

        $this->assertSame(['0.jpg', '1.jpg', '2.jpg', '3.jpg'], $fetched);
        $this->assertSame([['name' => 'Smith', 'start' => 0.0]], $segments);
    }

    /**
     * Reading every second frame halves the OCR work. A name must still be on
     * screen for three seconds to count, which two readings two seconds apart show.
     */
    public function testReadsEveryNthFrame(): void
    {
        $fetched = [];
        //         0        1        2        3     4        5        6        7     8
        $names = ['Smith', 'Smith', 'Smith', null, 'Jones', 'Jones', 'Jones', null, 'Blip'];
        $segments = $this->extractor($names, 2, $fetched)->extract('https://example.test/manifest.json', 'senate', 'floor');

        $this->assertSame(['0.jpg', '2.jpg', '4.jpg', '6.jpg', '8.jpg'], $fetched);
        $this->assertSame(
            [['name' => 'Smith', 'start' => 0.0], ['name' => 'Jones', 'start' => 4.0]],
            $segments,
            'A name seen in only one reading (Blip) is still discarded as too brief.'
        );
    }
    /**
     * Frames whose name plate region looks the same as the last one read reuse
     * that reading instead of running OCR again.
     */
    public function testUnchangedFramesReuseTheLastReading(): void
    {
        if (!function_exists('imagecreatefromjpeg')) {
            $this->markTestSkipped('GD is required.');
        }
        $plain = imagecreatetruecolor(320, 180);
        imagefill($plain, 0, 0, imagecolorallocate($plain, 90, 90, 90));
        $plate = imagecreatetruecolor(320, 180);
        imagefill($plate, 0, 0, imagecolorallocate($plate, 200, 200, 200));

        // Seconds 0-3 show a plate, 4-5 do not, 6-8 show it again.
        $frames = [];
        foreach ([1, 1, 1, 1, 0, 0, 1, 1, 1] as $second => $hasPlate) {
            $path = tempnam(sys_get_temp_dir(), 'frame_') . '.jpg';
            imagejpeg($hasPlate ? $plate : $plain, $path, 90);
            $frames[$second] = $path;
        }

        $manifest = [];
        foreach (array_keys($frames) as $second) {
            $manifest[] = ['timestamp' => $second, 'full' => "https://example.test/{$second}.jpg", 'thumb' => null];
        }
        $loader = $this->createMock(ScreenshotManifestLoader::class);
        $loader->method('load')->willReturn($manifest);
        $fetcher = $this->createMock(ScreenshotFetcher::class);
        $fetcher->method('fetch')->willReturnCallback(fn(string $url) => $frames[(int) basename($url, '.jpg')]);

        $ocrRuns = [];
        $textExtractor = $this->createMock(SpeakerTextExtractor::class);
        $textExtractor->method('extract')->willReturnCallback(function (string $chamber, string $path) use (&$ocrRuns, $frames) {
            $second = array_search($path, $frames, true);
            $ocrRuns[] = $second;
            return in_array($second, [4, 5], true) ? '' : 'Smith';
        });

        $extractor = new OcrSpeakerExtractor($loader, $fetcher, $textExtractor, new SpeakerNameParser(), new SpeakerChamberConfig(), 3);
        $segments = $extractor->extract('https://example.test/manifest.json', 'senate', 'floor');

        foreach ($frames as $path) {
            @unlink($path);
        }
        $this->assertSame([0, 4, 6], $ocrRuns, 'OCR runs only when the region changes.');
        // A gap with no plate does not end a turn, as before.
        $this->assertSame([['name' => 'Smith', 'start' => 0.0]], $segments);
    }

    /**
     * Frames are downloaded a batch at a time, ahead of being read, and only
     * the frames that will be read are downloaded.
     */
    public function testPrefetchesTheFramesItWillReadInBatches(): void
    {
        $manifest = [];
        for ($second = 0; $second < 60; $second++) {
            $manifest[] = ['timestamp' => $second, 'full' => "https://example.test/{$second}.jpg", 'thumb' => null];
        }
        $loader = $this->createMock(ScreenshotManifestLoader::class);
        $loader->method('load')->willReturn($manifest);

        $batches = [];
        $fetcher = $this->createMock(ScreenshotFetcher::class);
        $fetcher->method('prefetch')->willReturnCallback(function (array $urls) use (&$batches) {
            $batches[] = $urls;
        });
        $fetcher->method('fetch')->willReturnArgument(0);
        $fetcher->expects($this->once())->method('discardPrefetched');

        $textExtractor = $this->createMock(SpeakerTextExtractor::class);
        $textExtractor->method('extract')->willReturn('');

        $extractor = new OcrSpeakerExtractor($loader, $fetcher, $textExtractor, new SpeakerNameParser(), new SpeakerChamberConfig(), 3, null, 2);
        $extractor->extract('https://example.test/manifest.json', 'senate', 'floor');

        $this->assertSame([25, 5], array_map('count', $batches), 'Every 2nd of 60 frames is 30, in batches of 25.');
        $this->assertSame('https://example.test/0.jpg', $batches[0][0]);
        $this->assertSame('https://example.test/2.jpg', $batches[0][1]);
    }
}
