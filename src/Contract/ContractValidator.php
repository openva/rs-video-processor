<?php

namespace RichmondSunlight\VideoProcessor\Contract;

use PDO;

/**
 * Validates that processor output meets the front-end's expectations.
 *
 * Can be used in tests or against real database state via bin/validate_contract.php.
 */
class ContractValidator
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Validate all contract expectations for a given file.
     *
     * @param int $fileId
     * @return array<int, array{level: string, code: string, message: string}>
     */
    public function validateFile(int $fileId): array
    {
        $issues = [];

        $file = $this->loadFile($fileId);
        if (!$file) {
            return [['level' => 'error', 'code' => 'FILE_NOT_FOUND', 'message' => "File {$fileId} not found"]];
        }

        $issues = array_merge(
            $issues,
            $this->checkCaptions($fileId, $file),
            $this->checkVideoIndex($fileId, $file),
            $this->checkCaptureDirectory($file),
            $this->checkClassification($file),
        );

        return $issues;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadFile(int $fileId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM files WHERE id = :id');
        $stmt->execute([':id' => $fileId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Check that captions/transcript are present and valid.
     *
     * @return array<int, array{level: string, code: string, message: string}>
     */
    private function checkCaptions(int $fileId, array $file): array
    {
        $issues = [];

        // Check for transcript content in files table
        $hasWebvtt = !empty($file['webvtt']);
        $hasTranscript = !empty($file['transcript']);

        if (!$hasWebvtt && !$hasTranscript) {
            $issues[] = [
                'level' => 'warning',
                'code' => 'MISSING_CAPTIONS',
                'message' => "File {$fileId} has no webvtt or transcript content",
            ];
        }

        // Check for transcript segments
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM video_transcript WHERE file_id = :id');
        $stmt->execute([':id' => $fileId]);
        $segmentCount = (int) $stmt->fetchColumn();

        if ($segmentCount === 0) {
            $issues[] = [
                'level' => 'warning',
                'code' => 'NO_TRANSCRIPT_SEGMENTS',
                'message' => "File {$fileId} has no video_transcript rows",
            ];
        } else {
            // Validate transcript segments have required fields
            $stmt = $this->pdo->prepare('
                SELECT COUNT(*) FROM video_transcript
                WHERE file_id = :id AND (text IS NULL OR text = "")
            ');
            $stmt->execute([':id' => $fileId]);
            $emptyCount = (int) $stmt->fetchColumn();

            if ($emptyCount > 0) {
                $issues[] = [
                    'level' => 'warning',
                    'code' => 'EMPTY_TRANSCRIPT_TEXT',
                    'message' => "File {$fileId} has {$emptyCount} transcript segment(s) with empty text",
                ];
            }

            // Check for valid time ranges
            $stmt = $this->pdo->prepare('
                SELECT COUNT(*) FROM video_transcript
                WHERE file_id = :id AND (time_start IS NULL OR time_end IS NULL)
            ');
            $stmt->execute([':id' => $fileId]);
            $nullTimeCount = (int) $stmt->fetchColumn();

            if ($nullTimeCount > 0) {
                $issues[] = [
                    'level' => 'error',
                    'code' => 'NULL_TRANSCRIPT_TIMES',
                    'message' => "File {$fileId} has {$nullTimeCount} transcript segment(s) with null time_start or time_end",
                ];
            }
        }

        return $issues;
    }

    /**
     * Check video_index entries for a file.
     *
     * @return array<int, array{level: string, code: string, message: string}>
     */
    private function checkVideoIndex(int $fileId, array $file): array
    {
        $issues = [];

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM video_index WHERE file_id = :id');
        $stmt->execute([':id' => $fileId]);
        $indexCount = (int) $stmt->fetchColumn();

        if ($indexCount === 0) {
            $issues[] = [
                'level' => 'info',
                'code' => 'NO_VIDEO_INDEX',
                'message' => "File {$fileId} has no video_index entries",
            ];
            return $issues;
        }

        // Check for unresolved entries (raw_text present but no linked_id)
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) FROM video_index
            WHERE file_id = :id
              AND raw_text IS NOT NULL AND raw_text != ""
              AND linked_id IS NULL
              AND (ignored IS NULL OR ignored != "y")
        ');
        $stmt->execute([':id' => $fileId]);
        $unresolvedCount = (int) $stmt->fetchColumn();

        if ($unresolvedCount > 0) {
            $issues[] = [
                'level' => 'warning',
                'code' => 'UNRESOLVED_RAW_TEXT',
                'message' => "File {$fileId} has {$unresolvedCount} video_index entries with raw_text but no linked_id",
            ];
        }

        return $issues;
    }

    /**
     * Validate capture_directory format.
     *
     * @return array<int, array{level: string, code: string, message: string}>
     */
    private function checkCaptureDirectory(array $file): array
    {
        $issues = [];
        $fileId = $file['id'];
        $dir = $file['capture_directory'] ?? null;

        if (empty($dir)) {
            $issues[] = [
                'level' => 'info',
                'code' => 'NO_CAPTURE_DIRECTORY',
                'message' => "File {$fileId} has no capture_directory",
            ];
            return $issues;
        }

        // capture_directory should be a relative path like "house/floor/20250110"
        // It should NOT start with http://, /screenshots/, or /video/
        if (str_starts_with($dir, 'http://') || str_starts_with($dir, 'https://')) {
            $issues[] = [
                'level' => 'error',
                'code' => 'ABSOLUTE_CAPTURE_DIRECTORY',
                'message' => "File {$fileId} has absolute URL as capture_directory: {$dir}",
            ];
        }

        if (str_starts_with($dir, '/screenshots/') || str_starts_with($dir, '/video/')) {
            $issues[] = [
                'level' => 'error',
                'code' => 'BAD_CAPTURE_DIRECTORY_PREFIX',
                'message' => "File {$fileId} has incorrect prefix in capture_directory: {$dir}",
            ];
        }

        return $issues;
    }

    /**
     * Validate classification fields.
     *
     * @return array<int, array{level: string, code: string, message: string}>
     */
    private function checkClassification(array $file): array
    {
        $issues = [];
        $fileId = $file['id'];

        if (empty($file['chamber'])) {
            $issues[] = [
                'level' => 'error',
                'code' => 'MISSING_CHAMBER',
                'message' => "File {$fileId} has no chamber set",
            ];
        } elseif (!in_array($file['chamber'], ['house', 'senate'], true)) {
            $issues[] = [
                'level' => 'error',
                'code' => 'INVALID_CHAMBER',
                'message' => "File {$fileId} has invalid chamber: {$file['chamber']}",
            ];
        }

        return $issues;
    }
}
