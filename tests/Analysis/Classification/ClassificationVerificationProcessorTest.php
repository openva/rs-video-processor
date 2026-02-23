<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Classification;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotFetcher;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotManifestLoader;
use RichmondSunlight\VideoProcessor\Analysis\Classification\ClassificationCorrectionWriter;
use RichmondSunlight\VideoProcessor\Analysis\Classification\ClassificationVerificationJob;
use RichmondSunlight\VideoProcessor\Analysis\Classification\ClassificationVerificationProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Classification\FrameClassifier;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;

class ClassificationVerificationProcessorTest extends TestCase
{
    private function createJob(string $eventType = 'floor', ?int $committeeId = null): ClassificationVerificationJob
    {
        return new ClassificationVerificationJob(
            1,
            'house',
            $eventType,
            $committeeId,
            '/house/floor/20250115/',
            'https://video.richmondsunlight.com/house/floor/20250115/manifest.json',
            ['event_type' => $eventType],
            'House Session',
            '20250115'
        );
    }

    public function testMismatchTriggersCorrection(): void
    {
        $loader = $this->createMock(ScreenshotManifestLoader::class);
        $loader->method('load')->willReturn([
            ['timestamp' => 0, 'full' => 'https://example.com/00000000.jpg', 'thumb' => null],
        ]);

        $fetcher = $this->createMock(ScreenshotFetcher::class);
        $fetcher->method('fetch')->willReturn('/tmp/fake.jpg');

        $classifier = $this->createMock(FrameClassifier::class);
        $classifier->method('classify')->willReturn([
            'event_type' => 'committee',
            'committee_name' => 'Agriculture',
        ]);

        $writer = $this->createMock(ClassificationCorrectionWriter::class);
        $writer->expects($this->once())
            ->method('correct')
            ->with(1, 42, 'House Agriculture', 'committee', ['event_type' => 'floor']);

        $committeeDir = $this->createMock(CommitteeDirectory::class);
        $committeeDir->method('matchEntry')->willReturn([
            'id' => 42,
            'name' => 'Agriculture',
            'shortname' => 'agriculture',
            'chamber' => 'house',
            'parent_id' => null,
            'type' => 'committee',
        ]);

        $processor = new ClassificationVerificationProcessor(
            $loader, $fetcher, $classifier, $writer, $committeeDir
        );
        $processor->process($this->createJob());
    }

    public function testMatchMarksVerifiedOnly(): void
    {
        $loader = $this->createMock(ScreenshotManifestLoader::class);
        $loader->method('load')->willReturn([
            ['timestamp' => 0, 'full' => 'https://example.com/00000000.jpg', 'thumb' => null],
        ]);

        $fetcher = $this->createMock(ScreenshotFetcher::class);
        $fetcher->method('fetch')->willReturn('/tmp/fake.jpg');

        $classifier = $this->createMock(FrameClassifier::class);
        $classifier->method('classify')->willReturn([
            'event_type' => 'floor',
            'committee_name' => null,
        ]);

        $writer = $this->createMock(ClassificationCorrectionWriter::class);
        $writer->expects($this->once())->method('markVerified')->with(1, ['event_type' => 'floor']);
        $writer->expects($this->never())->method('correct');

        $committeeDir = $this->createMock(CommitteeDirectory::class);

        $processor = new ClassificationVerificationProcessor(
            $loader, $fetcher, $classifier, $writer, $committeeDir
        );
        $processor->process($this->createJob());
    }

    public function testFloorConfirmedWhenPhotographicFrame(): void
    {
        $loader = $this->createMock(ScreenshotManifestLoader::class);
        $loader->method('load')->willReturn([
            ['timestamp' => 0, 'full' => 'https://example.com/00000000.jpg', 'thumb' => null],
        ]);

        $fetcher = $this->createMock(ScreenshotFetcher::class);
        $fetcher->method('fetch')->willReturn('/tmp/fake.jpg');

        $classifier = $this->createMock(FrameClassifier::class);
        $classifier->method('classify')->willReturn([
            'event_type' => 'floor',
            'committee_name' => null,
        ]);

        $writer = $this->createMock(ClassificationCorrectionWriter::class);
        $writer->expects($this->once())->method('markVerified');
        $writer->expects($this->never())->method('correct');

        $committeeDir = $this->createMock(CommitteeDirectory::class);

        $job = $this->createJob('floor');
        $processor = new ClassificationVerificationProcessor(
            $loader, $fetcher, $classifier, $writer, $committeeDir
        );
        $processor->process($job);
    }

    public function testCorrectionToFloorClearsCommittee(): void
    {
        $loader = $this->createMock(ScreenshotManifestLoader::class);
        $loader->method('load')->willReturn([
            ['timestamp' => 0, 'full' => 'https://example.com/00000000.jpg', 'thumb' => null],
        ]);

        $fetcher = $this->createMock(ScreenshotFetcher::class);
        $fetcher->method('fetch')->willReturn('/tmp/fake.jpg');

        $classifier = $this->createMock(FrameClassifier::class);
        $classifier->method('classify')->willReturn([
            'event_type' => 'floor',
            'committee_name' => null,
        ]);

        $writer = $this->createMock(ClassificationCorrectionWriter::class);
        $writer->expects($this->once())
            ->method('correct')
            ->with(1, null, 'House Session', 'floor', ['event_type' => 'committee']);

        $committeeDir = $this->createMock(CommitteeDirectory::class);

        $job = $this->createJob('committee', 42);
        $processor = new ClassificationVerificationProcessor(
            $loader, $fetcher, $classifier, $writer, $committeeDir
        );
        $processor->process($job);
    }

    public function testNoManifestUrlSkipsProcessing(): void
    {
        $loader = $this->createMock(ScreenshotManifestLoader::class);
        $loader->expects($this->never())->method('load');

        $fetcher = $this->createMock(ScreenshotFetcher::class);
        $classifier = $this->createMock(FrameClassifier::class);
        $writer = $this->createMock(ClassificationCorrectionWriter::class);
        $writer->expects($this->never())->method('correct');
        $writer->expects($this->never())->method('markVerified');

        $committeeDir = $this->createMock(CommitteeDirectory::class);

        $job = new ClassificationVerificationJob(
            1, 'house', 'floor', null, '/house/floor/20250115/',
            null, null, 'House Session'
        );

        $processor = new ClassificationVerificationProcessor(
            $loader, $fetcher, $classifier, $writer, $committeeDir
        );
        $processor->process($job);
    }

    public function testUnmatchedCommitteeNameSkipsCorrection(): void
    {
        $loader = $this->createMock(ScreenshotManifestLoader::class);
        $loader->method('load')->willReturn([
            ['timestamp' => 0, 'full' => 'https://example.com/00000000.jpg', 'thumb' => null],
        ]);

        $fetcher = $this->createMock(ScreenshotFetcher::class);
        $fetcher->method('fetch')->willReturn('/tmp/fake.jpg');

        $classifier = $this->createMock(FrameClassifier::class);
        $classifier->method('classify')->willReturn([
            'event_type' => 'committee',
            'committee_name' => 'Nonexistent Committee',
        ]);

        $writer = $this->createMock(ClassificationCorrectionWriter::class);
        $writer->expects($this->once())->method('markVerified');
        $writer->expects($this->never())->method('correct');

        $committeeDir = $this->createMock(CommitteeDirectory::class);
        $committeeDir->method('matchEntry')->willReturn(null);

        $processor = new ClassificationVerificationProcessor(
            $loader, $fetcher, $classifier, $writer, $committeeDir
        );
        $processor->process($this->createJob());
    }
}
