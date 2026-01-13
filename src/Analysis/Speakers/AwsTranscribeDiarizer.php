<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

use Aws\TranscribeService\TranscribeServiceClient;
use Aws\S3\S3Client;
use RichmondSunlight\VideoProcessor\Transcripts\AudioExtractor;
use RuntimeException;

class AwsTranscribeDiarizer implements DiarizerInterface
{
    public function __construct(
        private TranscribeServiceClient $transcribe,
        private S3Client $s3,
        private string $bucket,
        private ?AudioExtractor $audioExtractor = null
    ) {
        $this->audioExtractor = $audioExtractor ?? new AudioExtractor();
    }

    public function diarize(string $videoUrl): array
    {
        $audioPath = $this->audioExtractor->extract($videoUrl);
        try {
            // Upload audio to S3
            $s3Key = $this->uploadToS3($audioPath);

            // Start transcription job with diarization
            $jobName = 'diarize-' . uniqid() . '-' . time();
            $this->transcribe->startTranscriptionJob([
                'TranscriptionJobName' => $jobName,
                'LanguageCode' => 'en-US',
                'Media' => [
                    'MediaFileUri' => "s3://{$this->bucket}/{$s3Key}",
                ],
                'Settings' => [
                    'ShowSpeakerLabels' => true,
                    'MaxSpeakerLabels' => 20,
                ],
            ]);

            // Poll for completion
            $result = $this->waitForCompletion($jobName);

            // Clean up S3 file
            $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
            ]);

            // Parse and return speaker segments
            return $this->parseTranscript($result);
        } finally {
            @unlink($audioPath);
        }
    }

    private function uploadToS3(string $audioPath): string
    {
        $key = 'transcribe-temp/' . uniqid() . '-' . time() . '.mp3';
        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => fopen($audioPath, 'r'),
            'ContentType' => 'audio/mpeg',
        ]);
        return $key;
    }

    private function waitForCompletion(string $jobName, int $maxWaitSeconds = 3600): array
    {
        $startTime = time();
        while (true) {
            $response = $this->transcribe->getTranscriptionJob([
                'TranscriptionJobName' => $jobName,
            ]);

            $status = $response['TranscriptionJob']['TranscriptionJobStatus'];

            if ($status === 'COMPLETED') {
                $transcriptUri = $response['TranscriptionJob']['Transcript']['TranscriptFileUri'];
                $transcriptJson = file_get_contents($transcriptUri);
                return json_decode($transcriptJson, true);
            }

            if ($status === 'FAILED') {
                $reason = $response['TranscriptionJob']['FailureReason'] ?? 'Unknown error';
                throw new RuntimeException("Transcription job failed: {$reason}");
            }

            if (time() - $startTime > $maxWaitSeconds) {
                throw new RuntimeException("Transcription job timed out after {$maxWaitSeconds} seconds");
            }

            sleep(5);
        }
    }

    private function parseTranscript(array $result): array
    {
        if (!isset($result['results']['speaker_labels']['segments'])) {
            return [];
        }

        $segments = [];
        foreach ($result['results']['speaker_labels']['segments'] as $segment) {
            $speaker = $segment['speaker_label'] ?? 'Unknown Speaker';
            $startTime = (float) ($segment['start_time'] ?? 0);

            $segments[] = [
                'name' => $speaker,
                'start' => $startTime,
            ];
        }

        return $segments;
    }
}
