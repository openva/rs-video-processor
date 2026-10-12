<?php

namespace RichmondSunlight\VideoProcessor\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJobQueue;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJobQueue;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobQueue;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadQueue;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJobQueue;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJobQueue;

/**
 * Runs the six job queues against a real MariaDB, where the row locking and
 * claim code that SQLite skips actually executes.
 *
 * Runs only in the Docker test environment (./docker-tests.sh), which sets
 * DB_HOST=db. It is skipped everywhere else, and refuses any other host so it
 * can never touch a production database.
 */
class MariaDbQueueTest extends TestCase
{
    private const MARKER = 'MariaDbQueueTest';
    private const S3 = 'https://video.richmondsunlight.com/house/floor/20990101-test.mp4';
    private const SHOTS = '/house/floor/20990101-test/';

    private function connect(): PDO
    {
        $host = getenv('DB_HOST');
        if ($host !== 'db') {
            $this->markTestSkipped('Runs only in the Docker test environment (DB_HOST=db).');
        }

        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s', $host, getenv('DB_NAME') ?: 'richmondsunlight'),
            getenv('DB_USER') ?: 'ricsun',
            getenv('DB_PASS') ?: 'password',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        return $pdo;
    }

    protected function setUp(): void
    {
        // video_jobs and video_sources come from deploy/mysql/structure.sql, which
        // the Docker database loads when it is first created.
        $this->removeTestRows($this->connect());
    }

    protected function tearDown(): void
    {
        if (getenv('DB_HOST') === 'db') {
            $this->removeTestRows($this->connect());
        }
    }

    private function removeTestRows(PDO $pdo): void
    {
        $pdo->prepare(
            'DELETE FROM video_transcript WHERE file_id IN (SELECT id FROM files WHERE title = :marker)'
        )->execute([':marker' => self::MARKER]);
        // video_index, video_jobs and video_sources rows go with the file (ON DELETE CASCADE).
        $pdo->prepare('DELETE FROM files WHERE title = :marker')->execute([':marker' => self::MARKER]);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function seed(PDO $pdo, array $overrides): int
    {
        $row = array_merge([
            'chamber' => 'house',
            'title' => self::MARKER,
            'description' => '',
            'license' => 'public-domain',
            'type' => 'video',
            // Far in the future, so these rows sort first in every date-ordered queue.
            'date' => '2099-01-01',
            'path' => null,
            'html' => null,
            'webvtt' => null,
            'transcript' => null,
            'capture_directory' => null,
            'capture_rate' => null,
            'video_index_cache' => null,
            'date_created' => '2099-01-01 00:00:00',
        ], $overrides);

        $columns = array_keys($row);
        $stmt = $pdo->prepare(sprintf(
            'INSERT INTO files (%s) VALUES (%s)',
            implode(', ', array_map(fn(string $c) => '`' . $c . '`', $columns)),
            implode(', ', array_map(fn(string $c) => ':' . $c, $columns))
        ));
        $stmt->execute(array_combine(array_map(fn(string $c) => ':' . $c, $columns), array_values($row)));

        return (int) $pdo->lastInsertId();
    }

    /**
     * @return array<string,array{0:\Closure,1:\Closure,2:array<string,mixed>}>
     */
    public static function queues(): array
    {
        $cache = json_encode(['video_url' => 'https://example.test/a.mp4']);

        return [
            'download' => [
                fn(PDO $pdo) => new VideoDownloadQueue($pdo),
                fn(object $job): int => $job->id,
                ['path' => '', 'video_index_cache' => $cache],
            ],
            'screenshots' => [
                fn(PDO $pdo) => new ScreenshotJobQueue($pdo),
                fn(object $job): int => $job->id,
                ['path' => self::S3],
            ],
            'transcript' => [
                fn(PDO $pdo) => new TranscriptJobQueue($pdo),
                fn(object $job): int => $job->fileId,
                ['path' => self::S3],
            ],
            'bills' => [
                fn(PDO $pdo) => new BillDetectionJobQueue($pdo),
                fn(object $job): int => $job->fileId,
                ['capture_directory' => self::SHOTS],
            ],
            'speakers' => [
                fn(PDO $pdo) => new SpeakerJobQueue($pdo),
                fn(object $job): int => $job->fileId,
                ['path' => self::S3],
            ],
            'archive' => [
                fn(PDO $pdo) => new ArchiveJobQueue($pdo),
                fn(object $job): int => $job->fileId,
                ['path' => self::S3, 'webvtt' => 'WEBVTT', 'capture_directory' => self::SHOTS, 'transcript' => 'text'],
            ],
        ];
    }

    /**
     * @dataProvider queues
     */
    public function testTwoWorkersNeverReceiveTheSameVideo(\Closure $makeQueue, \Closure $idOf, array $row): void
    {
        $workerA = $this->connect();
        $workerB = $this->connect();

        $seeded = [$this->seed($workerA, $row), $this->seed($workerA, $row)];

        $this->assertGreaterThanOrEqual(2, $makeQueue($workerA)->countPending());

        $first = $makeQueue($workerA)->fetch(1);
        $second = $makeQueue($workerB)->fetch(1);

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);

        $firstId = $idOf($first[0]);
        $secondId = $idOf($second[0]);
        $this->assertContains($firstId, $seeded);
        $this->assertContains($secondId, $seeded);
        $this->assertNotSame($firstId, $secondId, 'Two workers received the same video.');

        // Both are now claimed; neither may be handed out again.
        foreach ($makeQueue($workerA)->fetch(5) as $job) {
            $this->assertNotContains($idOf($job), $seeded);
        }
    }
}
