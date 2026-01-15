#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Database Connection Diagnostics
 *
 * Checks MySQL timeout settings and tests connection persistence
 * to diagnose "MySQL server has gone away" errors.
 */

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo ?? null;

if (!$pdo) {
    echo "ERROR: Unable to connect to database\n";
    exit(1);
}

echo "=== Database Connection Diagnostics ===\n\n";

// Check if using persistent connection
$isPersistent = $pdo->getAttribute(PDO::ATTR_PERSISTENT);
echo "Connection Type: " . ($isPersistent ? "PERSISTENT" : "NON-PERSISTENT") . "\n";
echo "Driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";
echo "Server Version: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n\n";

// Check MySQL timeout settings
echo "=== MySQL Timeout Settings ===\n";
$timeouts = [
    'wait_timeout' => 'Time server waits for activity before closing connection',
    'interactive_timeout' => 'Time server waits for interactive client activity',
    'net_read_timeout' => 'Seconds to wait for more data from connection',
    'net_write_timeout' => 'Seconds to wait for block to be written',
    'connect_timeout' => 'Seconds mysqld waits for connect packet',
];

foreach ($timeouts as $var => $description) {
    $stmt = $pdo->query("SHOW VARIABLES LIKE '$var'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $value = (int)$row['Value'];
        $hours = floor($value / 3600);
        $minutes = floor(($value % 3600) / 60);
        $seconds = $value % 60;

        $timeStr = '';
        if ($hours > 0) $timeStr .= "{$hours}h ";
        if ($minutes > 0) $timeStr .= "{$minutes}m ";
        $timeStr .= "{$seconds}s";

        echo sprintf("  %-20s: %6d seconds (%s)\n", $var, $value, trim($timeStr));
        echo sprintf("  %-20s  %s\n\n", '', $description);
    }
}

// Check connection stats
echo "=== Connection Statistics ===\n";
$stats = [
    'Threads_connected' => 'Currently open connections',
    'Max_used_connections' => 'Max connections used simultaneously',
    'Aborted_clients' => 'Connections aborted due to client issues',
    'Aborted_connects' => 'Failed connection attempts',
];

foreach ($stats as $var => $description) {
    $stmt = $pdo->query("SHOW GLOBAL STATUS LIKE '$var'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo sprintf("  %-25s: %8s - %s\n", $var, $row['Value'], $description);
    }
}

// Test connection persistence over time
echo "\n=== Connection Persistence Test ===\n";
echo "Testing connection over 30 seconds with 10-second idle gaps...\n";

$connectionId = $pdo->query("SELECT CONNECTION_ID()")->fetchColumn();
echo "Initial Connection ID: $connectionId\n";

for ($i = 1; $i <= 3; $i++) {
    echo "  Sleeping 10 seconds...\n";
    sleep(10);

    try {
        $newId = $pdo->query("SELECT CONNECTION_ID()")->fetchColumn();
        if ($newId === $connectionId) {
            echo "  ✓ Connection still alive (ID: $newId)\n";
        } else {
            echo "  ⚠ Connection ID changed! Old: $connectionId, New: $newId\n";
            $connectionId = $newId;
        }
    } catch (PDOException $e) {
        echo "  ✗ Connection failed: " . $e->getMessage() . "\n";
        echo "  Attempting reconnect...\n";
        try {
            $pdo = null;
            $db = new Database();
            $pdo = $db->connect();
            $connectionId = $pdo->query("SELECT CONNECTION_ID()")->fetchColumn();
            echo "  ✓ Reconnected (ID: $connectionId)\n";
        } catch (Exception $e) {
            echo "  ✗ Reconnect failed: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

echo "\n=== Recommendations ===\n";
if ($isPersistent) {
    echo "⚠ PERSISTENT CONNECTIONS DETECTED\n";
    echo "  Problem: Long video processing operations (ffmpeg, downloads, OCR)\n";
    echo "           can cause connections to sit idle and timeout.\n\n";
    echo "  Solutions:\n";
    echo "  1. Disable persistent connections for video processor\n";
    echo "  2. Add connection health checks before database operations\n";
    echo "  3. Increase RDS wait_timeout (not recommended - masks the issue)\n";
    echo "  4. Reconnect on PDO exceptions in long-running scripts\n";
}

$wait = (int)$pdo->query("SHOW VARIABLES LIKE 'wait_timeout'")->fetch(PDO::FETCH_ASSOC)['Value'];
if ($wait < 28800) { // Less than 8 hours
    echo "\n⚠ LOW wait_timeout DETECTED ($wait seconds)\n";
    echo "  This may be too low for long-running video processing.\n";
    echo "  Consider increasing or implementing connection health checks.\n";
}

echo "\nDiagnostics complete.\n";
