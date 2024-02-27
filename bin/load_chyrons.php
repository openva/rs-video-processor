<?php

/**
 * Load chyrons
 *
 * This is intended to be run after ocr.py. That script extracts the chyrons and saves them to
 * SQLite, this script transfers the chyrons from SQLite into MariaDB.
 */

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/functions.inc.php';

// Connect to the database.
$database = new Database();
$db = $database->connect();

// Get the video ID from the command line.
$video_id = $_SERVER['argv'][1];
if (!isset($video_id) || empty($video_id)) {
    die('No video ID specified.');
}

// Optionally get the capture directory from the command line.
if (isset($_SERVER['argv'][2])) {
    $capture_directory = $_SERVER['argv'][2];
} else {
    $capture_directory = (__DIR__ . '/../video/');
}

// Instantiate our logging class.
$log = new Log();

// Database configurations
$sqliteDsn = 'sqlite:/chyrons.db';

// Make sure that this hasn't already been added to MariaDB
$sql = 'SELECT file_id
		FROM video_index
		WHERE file_id=' . $video_id;
$stmt = $db->prepare($sql);
$stmt->execute();
if ($stmt->fetch() === true) {
    die('This video has already been parsed!');
}

// Make sure that the capture directory exists
if (!file_exists($capture_directory)) {
    echo 'No such directory as ' . $capture_directory;
    echo 'You must first run ocr.py.';
}

try {
    // Connect to SQLite
    $sqlite = new PDO($sqliteDsn);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch records from SQLite
    $sql = 'SELECT *
            FROM chyrons
            WHERE video_id=' . $video_id;
    $fetchStmt = $sqlite->query($sql);

    // Prepare MySQL insert statement
    $sql = 'INSERT INTO video_index
            (file_id, time, screenshot, raw_text, type, date_created, date_modified)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())';
    $insertStmt = $db->prepare($sql);

    while ($chyron = $fetchStmt->fetch(PDO::FETCH_ASSOC)) {
        $chyron['time'] = date('H:i:s', $row['timestamp']);

        // Insert into MySQL
        $insertStmt->execute([
            $chyron['video_id'],
            $chyron['time'],
            $chyron['timestamp'] . '.jpg',
            $chyron['text'],
            $chyron['type'],
        ]);
    }

    // Remove the chyrons from SQLite
    $sql = 'DELETE
            FROM chyrons
            WHERE video_id=' . $video_id;
    $sqlite->query($sql);

    echo "Chyrons successfully loaded into MariaDB\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
