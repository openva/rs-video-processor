#!/usr/bin/env php
<?php

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Resolution\RawTextResolver;

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/vendor/autoload.php';
require_once __DIR__ . '/../includes/class.Database.php';
require_once __DIR__ . '/../includes/class.Log.php';

// Parse command-line arguments
$options = getopt('', [
    'file-id:',
    'dry-run',
    'force',
    'type:',
    'limit:',
    'verbose',
    'json',
    'help',
]);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

// Configuration
$fileId = isset($options['file-id']) ? (int)$options['file-id'] : null;
$dryRun = isset($options['dry-run']);
$force = isset($options['force']);
$type = $options['type'] ?? null;
$limit = isset($options['limit']) ? (int)$options['limit'] : null;
$verbose = isset($options['verbose']);
$jsonOutput = isset($options['json']);

// Validate type
if ($type !== null && !in_array($type, ['legislator', 'bill'])) {
    fwrite(STDERR, "Error: --type must be 'legislator' or 'bill'\n");
    exit(1);
}

// Connect to database
try {
    $db = new Database();
    $pdo = $db->connect();
} catch (Exception $e) {
    fwrite(STDERR, "Database connection error: " . $e->getMessage() . "\n");
    exit(1);
}

// Create logger
$logger = class_exists('Log') ? new Log(['verbosity' => $verbose ? 5 : 3]) : null;

// Create resolver
$resolver = new RawTextResolver($pdo, null, null, $logger);

// Display header (unless JSON output)
if (!$jsonOutput) {
    echo "Raw Text Resolution Phase\n";
    echo "=========================\n\n";

    if ($dryRun) {
        echo "DRY RUN MODE - No database updates will be made\n\n";
    }

    if ($force) {
        echo "FORCE MODE - Re-resolving all entries (including already matched)\n\n";
    }
}

// Run resolution
$startTime = microtime(true);

try {
    if ($fileId !== null) {
        // Process specific file
        if (!$jsonOutput) {
            echo "Processing file ID: {$fileId}\n";
            if ($type) {
                echo "Type filter: {$type}\n";
            }
            echo "\n";
        }

        $stats = $resolver->resolveFile($fileId, $dryRun, $force, $type);

        // Display results
        if ($jsonOutput) {
            echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
        } else {
            displayFileStats($stats);
        }
    } else {
        // Process all files
        if (!$jsonOutput) {
            echo "Processing all files with unresolved entries\n";
            if ($type) {
                echo "Type filter: {$type}\n";
            }
            if ($limit) {
                echo "Limit: {$limit} files\n";
            }
            echo "\n";
        }

        $stats = $resolver->resolveAll($dryRun, $force, $type, $limit);

        // Display results
        if ($jsonOutput) {
            echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
        } else {
            displayAllStats($stats);
        }
    }
} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    if ($verbose) {
        fwrite(STDERR, $e->getTraceAsString() . "\n");
    }
    exit(1);
}

$endTime = microtime(true);
$duration = $endTime - $startTime;

if (!$jsonOutput) {
    echo "\n";
    echo "Total Time: " . formatDuration($duration) . "\n";
}

exit(0);

// Helper functions

function showHelp(): void
{
    echo <<<HELP
Raw Text Resolution - Resolve video_index raw_text to linked_id

Usage:
  php bin/resolve_raw_text.php [options]

Options:
  --file-id=<id>         Process specific file ID
  --dry-run              Preview results without updating database
  --force                Re-resolve entries that already have linked_id
  --type=<type>          Only process specific type (legislator|bill)
  --limit=<n>            Limit number of files to process (when processing all)
  --verbose              Show detailed progress and matching info
  --json                 Output results as JSON
  --help                 Show this help message

Examples:
  # Process all unresolved entries
  php bin/resolve_raw_text.php

  # Preview resolution for specific file
  php bin/resolve_raw_text.php --file-id=12345 --dry-run

  # Only resolve legislators
  php bin/resolve_raw_text.php --type=legislator

  # Re-resolve all entries for a file
  php bin/resolve_raw_text.php --file-id=12345 --force

  # Process 10 files and output JSON
  php bin/resolve_raw_text.php --limit=10 --json

HELP;
}

function displayFileStats(array $stats): void
{
    echo "Results:\n";
    echo "========\n";
    echo "Total entries: {$stats['total']}\n";
    echo "Resolved: {$stats['resolved']} (" . percentage($stats['resolved'], $stats['total']) . ")\n";
    echo "Unresolved: {$stats['unresolved']} (" . percentage($stats['unresolved'], $stats['total']) . ")\n";

    echo "\n";
    echo "By Type:\n";

    foreach ($stats['by_type'] as $type => $typeStats) {
        if ($typeStats['total'] > 0) {
            echo "  {$type}:\n";
            echo "    Total: {$typeStats['total']}\n";
            echo "    Resolved: {$typeStats['resolved']} (" . percentage($typeStats['resolved'], $typeStats['total']) . ")\n";
            echo "    Unresolved: {$typeStats['unresolved']} (" . percentage($typeStats['unresolved'], $typeStats['total']) . ")\n";
        }
    }
}

function displayAllStats(array $stats): void
{
    echo "Results:\n";
    echo "========\n";
    echo "Files processed: {$stats['files_processed']}\n";
    echo "Total entries: {$stats['total_entries']}\n";
    echo "Resolved: {$stats['total_resolved']} (" . percentage($stats['total_resolved'], $stats['total_entries']) . ")\n";
    echo "Unresolved: {$stats['total_unresolved']} (" . percentage($stats['total_unresolved'], $stats['total_entries']) . ")\n";
}

function percentage(int $value, int $total): string
{
    if ($total === 0) {
        return '0.0%';
    }
    return number_format(($value / $total) * 100, 1) . '%';
}

function formatDuration(float $seconds): string
{
    if ($seconds < 60) {
        return number_format($seconds, 1) . 's';
    }

    $minutes = floor($seconds / 60);
    $seconds = $seconds % 60;

    return sprintf('%dm %ds', $minutes, (int)$seconds);
}
