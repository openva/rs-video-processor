<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Classification;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Classification\ClassificationVerificationJobQueue;

class ClassificationVerificationJobQueueTest extends TestCase
{
    private function createDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chamber TEXT,
            committee_id INTEGER,
            capture_directory TEXT,
            video_index_cache TEXT,
            title TEXT,
            date TEXT,
            date_created TEXT
        )');
        return $pdo;
    }

    public function testFetchReturnsEligibleHouseFiles(): void
    {
        $pdo = $this->createDatabase();
        $pdo->prepare('INSERT INTO files (chamber, committee_id, capture_directory, video_index_cache, title, date, date_created) VALUES ("house", NULL, :dir, :cache, "House Session", "20250115", "2025-01-15")')
            ->execute([
                ':dir' => '/house/floor/20250115/',
                ':cache' => json_encode(['event_type' => 'floor']),
            ]);

        $queue = new ClassificationVerificationJobQueue($pdo);
        $jobs = $queue->fetch();

        $this->assertCount(1, $jobs);
        $this->assertSame('house', $jobs[0]->chamber);
        $this->assertSame('floor', $jobs[0]->currentEventType);
        $this->assertStringContainsString('manifest.json', $jobs[0]->manifestUrl);
    }

    public function testAlreadyVerifiedFilesExcluded(): void
    {
        $pdo = $this->createDatabase();
        $pdo->prepare('INSERT INTO files (chamber, committee_id, capture_directory, video_index_cache, title, date, date_created) VALUES ("house", NULL, :dir, :cache, "House Session", "20250115", "2025-01-15")')
            ->execute([
                ':dir' => '/house/floor/20250115/',
                ':cache' => json_encode(['event_type' => 'floor', 'classification_verified' => true]),
            ]);

        $queue = new ClassificationVerificationJobQueue($pdo);
        $jobs = $queue->fetch();

        $this->assertCount(0, $jobs);
    }

    public function testNoCaptureDirectoryExcluded(): void
    {
        $pdo = $this->createDatabase();
        $pdo->prepare('INSERT INTO files (chamber, committee_id, capture_directory, video_index_cache, title, date, date_created) VALUES ("house", NULL, "", :cache, "House Session", "20250115", "2025-01-15")')
            ->execute([
                ':cache' => json_encode(['event_type' => 'floor']),
            ]);

        $queue = new ClassificationVerificationJobQueue($pdo);
        $jobs = $queue->fetch();

        $this->assertCount(0, $jobs);
    }

    public function testSenateFilesExcluded(): void
    {
        $pdo = $this->createDatabase();
        $pdo->prepare('INSERT INTO files (chamber, committee_id, capture_directory, video_index_cache, title, date, date_created) VALUES ("senate", NULL, :dir, :cache, "Senate Session", "20250115", "2025-01-15")')
            ->execute([
                ':dir' => '/senate/floor/20250115/',
                ':cache' => json_encode(['event_type' => 'floor']),
            ]);

        $queue = new ClassificationVerificationJobQueue($pdo);
        $jobs = $queue->fetch();

        $this->assertCount(0, $jobs);
    }

    public function testNullVideoIndexCacheExcluded(): void
    {
        $pdo = $this->createDatabase();
        $pdo->exec('INSERT INTO files (chamber, committee_id, capture_directory, video_index_cache, title, date, date_created) VALUES ("house", NULL, "/house/floor/20250115/", NULL, "House Session", "20250115", "2025-01-15")');

        $queue = new ClassificationVerificationJobQueue($pdo);
        $jobs = $queue->fetch();

        $this->assertCount(0, $jobs);
    }

    public function testLimitIsRespected(): void
    {
        $pdo = $this->createDatabase();
        for ($i = 1; $i <= 5; $i++) {
            $pdo->prepare('INSERT INTO files (chamber, committee_id, capture_directory, video_index_cache, title, date, date_created) VALUES ("house", NULL, :dir, :cache, "House Session", :date, "2025-01-15")')
                ->execute([
                    ':dir' => "/house/floor/2025010{$i}/",
                    ':cache' => json_encode(['event_type' => 'floor']),
                    ':date' => "2025010{$i}",
                ]);
        }

        $queue = new ClassificationVerificationJobQueue($pdo);
        $jobs = $queue->fetch(2);

        $this->assertCount(2, $jobs);
    }
}
