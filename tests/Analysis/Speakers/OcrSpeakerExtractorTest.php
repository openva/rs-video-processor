<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Speakers;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotFetcher;
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
}
