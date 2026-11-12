<?php

namespace RichmondSunlight\VideoProcessor\Sync;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Gives a slug (see VideoSlug) to every files row that doesn't have one: those
 * from before files.slug existed. Run once, after the column is added and
 * before it's made NOT NULL. Running it again does nothing.
 */
class VideoSlugBackfill
{
    /** @var \Closure(): string */
    private \Closure $newSlug;

    /**
     * @param (\Closure(): string)|null $newSlug makes slugs; for tests
     */
    public function __construct(private PDO $pdo, ?\Closure $newSlug = null)
    {
        $this->newSlug = $newSlug ?? fn(): string => VideoSlug::generate();
    }

    /**
     * @return int how many rows were given slugs
     */
    public function run(): int
    {
        $ids = $this->pdo->query('SELECT id FROM files WHERE slug IS NULL ORDER BY id ASC')
            ->fetchAll(PDO::FETCH_COLUMN);

        $update = $this->pdo->prepare('UPDATE files SET slug = :slug WHERE id = :id AND slug IS NULL');
        $count = 0;
        foreach ($ids as $id) {
            for ($attempt = 1;; $attempt++) {
                $error = null;
                try {
                    if ($update->execute([':slug' => ($this->newSlug)(), ':id' => (int) $id])) {
                        break;
                    }
                    $error = $update->errorInfo();
                } catch (PDOException $e) {
                    $error = $e->errorInfo;
                }
                // A statement that failed must be reset before it's executed again.
                $update->closeCursor();
                if (!VideoSlug::isCollision($error) || $attempt >= VideoSlug::MAX_ATTEMPTS) {
                    throw new RuntimeException(sprintf(
                        'Could not give file #%d a slug: %s',
                        $id,
                        (string) ($error[2] ?? 'unknown error')
                    ));
                }
            }
            $count++;
        }

        return $count;
    }
}
