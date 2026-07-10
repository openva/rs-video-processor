<?php

namespace RichmondSunlight\VideoProcessor\Maintenance;

use PDO;

/**
 * Releases stale in-progress claims so crashed/interrupted jobs get retried.
 *
 * Two claim mechanisms exist:
 *  - ScreenshotJobQueue marks claimed files capture_directory='/pending', capture_rate=0.
 *  - BillDetectionJobQueue / SpeakerJobQueue insert video_index placeholder rows
 *    (raw_text='/pending', ignored='y') so NOT EXISTS checks skip claimed files.
 *
 * If a worker dies (error, EC2 auto-shutdown mid-job), these claims are never
 * released and the video becomes permanently invisible to its pipeline stage.
 * Claims older than the cutoff are released here; processing sessions are capped
 * at ~110 minutes, so a claim older than the default 3-hour cutoff is dead.
 *
 * Terminal '/none' sentinel rows (written when a stage legitimately finds
 * nothing) are deliberately NOT touched.
 *
 * Caveat — files.date_modified is a shared ON UPDATE column: it is defined
 * `timestamp ... ON UPDATE current_timestamp()`, so ANY write to a '/pending'
 * file row (e.g. TranscriptWriter, committee_id repair scripts) bumps it. A
 * dedicated claim-timestamp column would be cleaner, but this project does no
 * schema migrations, so we accept this. It fails SAFE: a shared write can only
 * make a claim look NEWER, so the cleaner may DELAY recovery of a stuck
 * screenshot claim but will never prematurely reset a still-live one. The
 * bills/speakers half is immune: video_index.date_created has no ON UPDATE.
 */
class StaleClaimCleaner
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{screenshot_claims:int,index_placeholders:int}
     */
    public function clean(int $maxAgeHours = 3): array
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        // Build the cutoff DB-side so it shares the database's clock/timezone
        // with the CURRENT_TIMESTAMP / NOW() values stored in date_modified and
        // date_created. A PHP-computed cutoff (date()) would use PHP's timezone,
        // which can differ from the MySQL server's, silently offsetting every
        // comparison by hours. $maxAgeHours is an int, so interpolation is safe.
        if ($driver === 'sqlite') {
            $cutoffExpr = "datetime('now', '-{$maxAgeHours} hours')";
        } else {
            $cutoffExpr = "(NOW() - INTERVAL {$maxAgeHours} HOUR)";
        }

        // date_modified IS NULL covers claims made before claims were timestamped.
        $stmt = $this->pdo->prepare(
            "UPDATE files
             SET capture_directory = NULL, capture_rate = NULL
             WHERE capture_directory = '/pending'
               AND (date_modified IS NULL OR date_modified < {$cutoffExpr})"
        );
        $stmt->execute();
        $screenshotClaims = $stmt->rowCount();

        $stmt = $this->pdo->prepare(
            "DELETE FROM video_index
             WHERE raw_text = '/pending'
               AND ignored = 'y'
               AND type IN ('bill', 'legislator')
               AND date_created < {$cutoffExpr}"
        );
        $stmt->execute();
        $indexPlaceholders = $stmt->rowCount();

        return [
            'screenshot_claims' => $screenshotClaims,
            'index_placeholders' => $indexPlaceholders,
        ];
    }
}
