<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

use GuzzleHttp\Client;
use Log;
use RichmondSunlight\VideoProcessor\Transcripts\AudioExtractor;

class SpeakerDetectionProcessor
{
    public function __construct(
        private SpeakerMetadataExtractor $metadataExtractor,
        private DiarizerInterface $diarizer,
        private LegislatorDirectory $legislators,
        private SpeakerResultWriter $writer,
        private ?Log $logger = null
    ) {
    }

    public function process(SpeakerJob $job): void
    {
        $segments = $this->metadataExtractor->extract($job->metadata);
        if (empty($segments)) {
            // Only diarize floor videos (not committee videos)
            if ($this->isFloorVideo($job->metadata)) {
                $this->logger?->put('No metadata speakers for file #' . $job->fileId . ', running diarization (floor video).', 4);
                try {
                    $segments = $this->diarizer->diarize($job->videoUrl);
                } catch (\Throwable $e) {
                    $this->logger?->put('Diarization failed for file #' . $job->fileId . ': ' . $e->getMessage(), 4);
                    $segments = [];
                }
            } else {
                $this->logger?->put('Skipping diarization for file #' . $job->fileId . ' (committee video).', 4);
            }
        }

        if (empty($segments)) {
            $this->logger?->put('Unable to determine speakers for file #' . $job->fileId, 4);
            return;
        }

        $mapped = array_map(function ($segment) use ($job) {
            $legislatorId = $this->legislators->matchId($segment['name']);
            return [
                'name' => $segment['name'],
                'start' => $segment['start'],
                'legislator_id' => $legislatorId,
            ];
        }, $segments);

        $this->writer->write($job->fileId, $mapped);
        $this->logger?->put('Stored speaker data for file #' . $job->fileId, 3);
    }

    private function isFloorVideo(?array $metadata): bool
    {
        if ($metadata === null) {
            return false;
        }

        // Floor videos have no committee_name, or event_type is explicitly 'floor'
        $committeeName = $metadata['committee_name'] ?? null;
        $eventType = strtolower($metadata['event_type'] ?? 'floor');

        return empty($committeeName) && $eventType === 'floor';
    }
}
