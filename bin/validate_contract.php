#!/usr/bin/env php
<?php

/**
 * Validate processor output against the front-end contract.
 *
 * Usage:
 *   php bin/validate_contract.php                    # Validate all recent files
 *   php bin/validate_contract.php --file-id=123      # Validate a specific file
 *   php bin/validate_contract.php --limit=50         # Validate last 50 files
 *   php bin/validate_contract.php --level=error      # Only show errors (not warnings/info)
 */

require_once __DIR__ . '/../includes/vendor/autoload.php';

use RichmondSunlight\VideoProcessor\Contract\ContractValidator;

// Parse arguments
$options = getopt('', ['file-id:', 'limit:', 'level:', 'help']);

if (isset($options['help'])) {
    echo "Usage: php bin/validate_contract.php [options]\n";
    echo "  --file-id=ID   Validate a specific file\n";
    echo "  --limit=N      Validate last N files (default: 20)\n";
    echo "  --level=LEVEL  Minimum level to show: info, warning, error (default: info)\n";
    echo "  --help         Show this help\n";
    exit(0);
}

$fileId = isset($options['file-id']) ? (int) $options['file-id'] : null;
$limit = isset($options['limit']) ? (int) $options['limit'] : 20;
$minLevel = $options['level'] ?? 'info';

$levelOrder = ['info' => 0, 'warning' => 1, 'error' => 2];
$minLevelOrder = $levelOrder[$minLevel] ?? 0;

// Connect to database
if (!file_exists(__DIR__ . '/../includes/settings.inc.php')) {
    fprintf(STDERR, "Error: includes/settings.inc.php not found. Cannot connect to database.\n");
    fprintf(STDERR, "This tool requires the production database configuration.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/settings.inc.php';

if (!defined('PDO_DSN')) {
    fprintf(STDERR, "Error: PDO_DSN not defined in settings.\n");
    exit(1);
}

$pdo = new PDO(PDO_DSN, PDO_USERNAME ?? null, PDO_PASSWORD ?? null);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$validator = new ContractValidator($pdo);

// Determine which files to validate
if ($fileId !== null) {
    $fileIds = [$fileId];
} else {
    $stmt = $pdo->prepare('SELECT id FROM files ORDER BY id DESC LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $fileIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
}

$totalIssues = 0;
$issueCounts = ['info' => 0, 'warning' => 0, 'error' => 0];

foreach ($fileIds as $id) {
    $issues = $validator->validateFile((int) $id);

    $filteredIssues = array_filter($issues, function ($issue) use ($levelOrder, $minLevelOrder) {
        return ($levelOrder[$issue['level']] ?? 0) >= $minLevelOrder;
    });

    if (!empty($filteredIssues)) {
        echo sprintf("\n--- File #%d ---\n", $id);
        foreach ($filteredIssues as $issue) {
            $prefix = match ($issue['level']) {
                'error' => "\033[31m[ERROR]\033[0m",
                'warning' => "\033[33m[WARN]\033[0m ",
                default => "\033[36m[INFO]\033[0m ",
            };
            echo sprintf("  %s %s: %s\n", $prefix, $issue['code'], $issue['message']);
            $issueCounts[$issue['level']]++;
            $totalIssues++;
        }
    }
}

echo sprintf(
    "\nValidated %d file(s): %d error(s), %d warning(s), %d info\n",
    count($fileIds),
    $issueCounts['error'],
    $issueCounts['warning'],
    $issueCounts['info']
);

exit($issueCounts['error'] > 0 ? 1 : 0);
