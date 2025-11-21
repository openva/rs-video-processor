<?php

namespace RichmondSunlight\VideoProcessor\Archive;

use Log;
use PDO;
use Throwable;

class ArchiveJobProcessor
{
    public function __construct(
        private ArchiveJobQueue $queue,
        private MetadataBuilder $metadataBuilder,
        private InternetArchiveUploader $uploader,
        private PDO $pdo,
        private Log $logger
    ) {
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
                    $stmt = $this->pdo->prepare('UPDATE files SET path = :path WHERE id = :id');
                    $stmt->execute([':path' => $url, ':id' => $job->fileId]);
                    $this->logger->put('Uploaded file #' . $job->fileId . ' to ' . $url, 3);
                }
            } catch (Throwable $e) {
                $this->logger->put('IA upload failed for file #' . $job->fileId . ': ' . $e->getMessage(), 5);
            }
        }
    }
}
