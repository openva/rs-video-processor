<?php

namespace RichmondSunlight\VideoProcessor\Tests\Sync;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Sync\VideoSourceBackfill;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;

class VideoSourceBackfillTest extends TestCase
{
    public function testRegistersOldestRowAndReportsLaterDuplicates(): void
    {
        $pdo = TestDatabaseFactory::create();
        $insert = $pdo->prepare(
            "INSERT INTO files (chamber, title, date, video_index_cache, date_created)
             VALUES ('house', 'T', '2026-08-17', :cache, '2026-08-17 00:00:00')"
        );
        $insert->execute([':cache' => json_encode(['source' => 'house', 'content_id' => 500])]);
        $insert->execute([':cache' => json_encode(['source' => 'house', 'content_id' => 500])]);
        $insert->execute([':cache' => json_encode(['source' => 'house', 'content_id' => 501])]);
        $insert->execute([':cache' => 'not json']);
        $insert->execute([':cache' => null]);

        $result = (new VideoSourceBackfill($pdo, 2))->run();

        $this->assertSame(2, $result['registered']);
        $this->assertSame(
            [['file_id' => 2, 'duplicate_of' => 1, 'source' => 'house', 'source_id' => '500']],
            $result['duplicates']
        );

        // Running again registers nothing new and reports the same duplicate.
        $again = (new VideoSourceBackfill($pdo, 2))->run();
        $this->assertSame(0, $again['registered']);
        $this->assertCount(1, $again['duplicates']);
    }
}
