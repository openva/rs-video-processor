<?php

namespace RichmondSunlight\VideoProcessor\Tests\Archive;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobQueue;

class ArchiveJobQueueTest extends TestCase
{
    public function testSelectsEligibleRows(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chamber TEXT,
            committee_id INTEGER,
            title TEXT,
            date TEXT,
            path TEXT,
            html TEXT,
            webvtt TEXT,
            srt TEXT,
            capture_directory TEXT,
            video_index_cache TEXT,
            date_created TEXT
        )');
        $pdo->exec('CREATE TABLE committees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            chamber TEXT
        )');

        $pdo->prepare('INSERT INTO files (chamber,committee_id,title,date,path,webvtt,srt,capture_directory,video_index_cache,date_created) VALUES ("house",NULL,"Floor","2025-01-01","https://video.richmondsunlight.com/house/floor/20250101.mp4","WEBVTT",NULL,NULL,"{}","2025-01-01")')->execute();
        $pdo->prepare('INSERT INTO files (chamber,committee_id,title,date,path,webvtt,srt,capture_directory,video_index_cache,date_created) VALUES ("senate",NULL,"Floor","2025-01-02","https://archive.org/details/test",NULL,NULL,NULL,"{}","2025-01-02")')->execute();

        $queue = new ArchiveJobQueue($pdo);
        $jobs = $queue->fetch();
        $this->assertCount(1, $jobs);
        $this->assertSame('house', strtolower($jobs[0]->chamber));
    }
}
