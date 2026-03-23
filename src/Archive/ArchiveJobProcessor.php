<?php

namespace RichmondSunlight\VideoProcessor\Archive;

use Log;
use PDO;
use Throwable;

class ArchiveJobProcessor
{
    /** @var (\Closure(): PDO)|null */
    private ?\Closure $pdoFactory;

    public function __construct(
        private ArchiveJobQueue $queue,
        private MetadataBuilder $metadataBuilder,
        private InternetArchiveUploader $uploader,
        private PDO $pdo,
        private Log $logger,
        ?\Closure $pdoFactory = null
    ) {
        $this->pdoFactory = $pdoFactory;
    }

    public function run(int $limit = 2): void
    {
        $jobs = $this->queue->fetch($limit);
        if (empty($jobs)) {
            $this->logger->put('No files pending Internet Archive upload.', 3);
            return;
        }

        foreach ($jobs as $job) {
            try {
                $metadata = $this->metadataBuilder->build($job);
                $url = $this->uploader->upload($job, $metadata);
                if ($url) {
                    // Get a fresh connection — uploads take minutes (video download,
                    // ia upload, metadata polling with 90s sleeps) and the original
                    // connection will have timed out.
                    $pdo = $this->pdoFactory ? ($this->pdoFactory)() : $this->pdo;
                    $stmt = $pdo->prepare('UPDATE files SET path = :path WHERE id = :id');
                    $stmt->execute([':path' => $url, ':id' => $job->fileId]);
                    $this->logger->put('Uploaded file #' . $job->fileId . ' to ' . $url, 3);
                }
            } catch (Throwable $e) {
                $this->logger->put('IA upload failed for file #' . $job->fileId . ': ' . $e->getMessage(), 5);
            }
        }
    }
}
