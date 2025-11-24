<?php

namespace RichmondSunlight\VideoProcessor\Tests\Screenshots;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\StorageInterface;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotGenerator;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJob;

class ScreenshotGeneratorTest extends TestCase
{
    public function testGeneratesScreenshotsAndUpdatesDatabase(): void
    {
        $this->requireFfmpeg();

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE committees (id INTEGER PRIMARY KEY, name TEXT, shortname TEXT, chamber TEXT, parent_id INTEGER)');
        $pdo->exec("INSERT INTO committees (id, name, shortname, chamber, parent_id) VALUES (1, 'Finance Committee', 'finance', 'senate', NULL)");
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chamber TEXT,
            committee_id INTEGER,
            title TEXT,
            date TEXT,
            path TEXT,
            capture_directory TEXT,
            capture_rate INTEGER,
            date_created TEXT,
            date_modified TEXT
        )');
        $pdo->prepare('INSERT INTO files (chamber, committee_id, title, date, path, capture_directory, capture_rate, date_created, date_modified) VALUES ("senate", NULL, "Test", "2025-11-19", :path, "", 0, "2025-11-19 12:00:00", "2025-11-19 12:00:00")')
            ->execute([':path' => 'file://FAKE']);

        $fixture = $this->getVideoFixture('senate-floor.mp4');
        $job = new ScreenshotJob(
            1,
            'senate',
            null,
            '2025-11-19',
            'file://' . $fixture,
            null,
            'Test Video'
        );

        $storage = new class implements StorageInterface {
            public array $uploads = [];
            public function upload(string $localPath, string $key): string
            {
                $this->uploads[$key] = $localPath;
                return 'https://example.test/' . $key;
            }
        };

        $directory = new CommitteeDirectory($pdo);
        $keyBuilder = new S3KeyBuilder();

        $generator = new ScreenshotGenerator($pdo, $storage, $directory, $keyBuilder, null, sys_get_temp_dir());
        $generator->process($job);

        $row = $pdo->query('SELECT capture_directory FROM files WHERE id = 1')->fetchColumn();
        $this->assertNotEmpty($row);
        $this->assertStringContainsString('screenshots/full', $row);
    }

    private function getVideoFixture(string $filename): string
    {
        $path = __DIR__ . '/../fixtures/' . $filename;
        if (!file_exists($path)) {
            $this->markTestSkipped('Missing video fixture ' . $filename . '. Run bin/fetch_test_fixtures.php.');
        }
        return $path;
    }

    private function requireFfmpeg(): void
    {
        exec('ffmpeg -version > /dev/null 2>&1', $output, $status);
        if ($status !== 0) {
            $this->markTestSkipped('ffmpeg is required for screenshot tests.');
        }
    }
}
