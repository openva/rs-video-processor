<?php

namespace RichmondSunlight\VideoProcessor\Tests\Sync;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Sync\VideoSlug;
use RichmondSunlight\VideoProcessor\Sync\VideoSlugBackfill;

class VideoSlugTest extends TestCase
{
    public function testMakesSlugsOfTheRightForm(): void
    {
        for ($i = 0; $i < 2000; $i++) {
            $slug = VideoSlug::generate();
            $this->assertMatchesRegularExpression('/^[bcdfghjkmnpqrstvwxz2-9]{4}$/', $slug);
            $this->assertFalse(ctype_digit($slug), "All digits: {$slug}");
        }
    }

    public function testTellsSlugsFromOtherThings(): void
    {
        $this->assertTrue(VideoSlug::isValid('k7qm'));
        $this->assertFalse(VideoSlug::isValid('2345'), 'all digits');
        $this->assertFalse(VideoSlug::isValid('kaqm'), 'a vowel');
        $this->assertFalse(VideoSlug::isValid('k1qm'), 'a 1');
        $this->assertFalse(VideoSlug::isValid('K7QM'), 'uppercase');
        $this->assertFalse(VideoSlug::isValid('k7q'), 'too short');
    }

    public function testRecognizesCollisions(): void
    {
        $this->assertTrue(VideoSlug::isCollision(['23000', 1062, "Duplicate entry 'k7qm' for key 'slug'"]));
        $this->assertTrue(VideoSlug::isCollision(['23000', 19, 'UNIQUE constraint failed: files.slug']));
        $this->assertFalse(VideoSlug::isCollision(['23000', 1062, "Duplicate entry '5' for key 'PRIMARY'"]));
        $this->assertFalse(VideoSlug::isCollision(['42S22', 1054, "Unknown column 'slug'"]));
        $this->assertFalse(VideoSlug::isCollision(null));
    }

    public function testBackfillGivesSlugsOnlyToVideosWithout(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE files (id INTEGER PRIMARY KEY, slug TEXT UNIQUE)');
        $pdo->exec("INSERT INTO files (id, slug) VALUES (1, 'bbbb'), (2, NULL), (3, NULL)");

        // The first slug made is taken, so it must try another.
        $slugs = ['bbbb', 'cccc', 'dddd'];
        $backfill = new VideoSlugBackfill($pdo, function () use (&$slugs): string {
            return array_shift($slugs);
        });

        $this->assertSame(2, $backfill->run());
        $this->assertSame(
            ['1' => 'bbbb', '2' => 'cccc', '3' => 'dddd'],
            array_map('strval', $pdo->query('SELECT id, slug FROM files ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR))
        );
        $this->assertSame(0, (new VideoSlugBackfill($pdo))->run());
    }
}
