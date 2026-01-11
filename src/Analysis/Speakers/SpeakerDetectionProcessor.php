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
            $this->logger?->put('No metadata speakers for file #' . $job->fileId . ', running diarization.', 4);
            try {
                $segments = $this->diarizer->diarize($job->videoUrl);
            } catch (\Throwable $e) {
                $this->logger?->put('Diarization failed for file #' . $job->fileId . ': ' . $e->getMessage(), 4);
                $segments = [];
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
}
