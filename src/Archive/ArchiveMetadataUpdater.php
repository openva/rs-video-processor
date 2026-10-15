<?php

namespace RichmondSunlight\VideoProcessor\Archive;

use PDO;
use RuntimeException;

/**
 * Rewrites the title, subject and description of a video already on the
 * Internet Archive from its current files row, after that row has been
 * reclassified. Uses the same `ia` command line tool as uploading.
 */
class ArchiveMetadataUpdater
{
    /** @var callable(string, array): int */
    private $commandRunner;

    /**
     * @param callable(string, array): int|null $commandRunner runs a shell command, fills
     *        its output lines by reference and returns the exit status; for tests
     */
    public function __construct(
        private PDO $pdo,
        private MetadataBuilder $metadataBuilder,
        ?callable $commandRunner = null
    ) {
        $this->commandRunner = $commandRunner ?? function (string $command, array &$output): int {
            exec($command, $output, $status);
            return $status;
        };
    }

    /**
     * @return bool false when the video is not on the Internet Archive, so there is nothing to update
     * @throws RuntimeException when the update is rejected
     */
    public function update(int $fileId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.id, f.chamber, f.title, f.date, f.path, f.committee_id, c.name AS committee_name
             FROM files f LEFT JOIN committees c ON c.id = f.committee_id
             WHERE f.id = :id'
        );
        $stmt->execute([':id' => $fileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }

        $identifier = self::identifierFromPath((string) $row['path']);
        if ($identifier === null) {
            return false;
        }

        $job = new ArchiveJob(
            (int) $row['id'],
            (string) $row['chamber'],
            (string) $row['title'],
            (string) $row['date'],
            (string) $row['path'],
            null,
            null,
            null,
            null,
            $row['committee_id'] !== null ? (int) $row['committee_id'] : null,
            $row['committee_name'] ?? null
        );
        $metadata = $this->metadataBuilder->build($job);

        // Only the descriptive fields: collection, mediatype and date are unchanged.
        $command = 'ia metadata ' . escapeshellarg($identifier);
        foreach (['title', 'description', 'subject'] as $field) {
            $command .= ' --modify=' . escapeshellarg($field . ':' . $metadata[$field]);
        }
        $command .= ' 2>&1';

        $output = [];
        $status = ($this->commandRunner)($command, $output);
        if ($status !== 0) {
            throw new RuntimeException(sprintf(
                'Updating Internet Archive item %s failed: %s',
                $identifier,
                trim(implode('; ', $output)) ?: 'no output'
            ));
        }

        return true;
    }

    /**
     * The item identifier in an archive.org download or details URL, or null.
     */
    public static function identifierFromPath(string $path): ?string
    {
        if (!preg_match('#^https://archive\.org/(?:download|details)/([^/?\#]+)#', $path, $m)) {
            return null;
        }

        return rawurldecode($m[1]);
    }
}
