<?php

# INCLUDES
# Include any files or libraries that are necessary for this specific
# page to function.
include_once __DIR__ . '/../includes/settings.inc.php';
include_once __DIR__ . '/../includes/functions.inc.php';
include_once __DIR__ . '/../includes/vendor/autoload.php';

$video_dir = (__DIR__ . '/../video/');

$log = new Log();

/*
 * The filename must be specified at the command line.
 */
if (!isset($_SERVER['argv'][1])) {
    die('VTT filename must be provided.');
}
$filename = $_SERVER['argv'][1];

/*
 * The video ID must be specified at the command line.
 */
if (!isset($_SERVER['argv'][2])) {
    die('Video ID must be provided.');
}
$video_id = $_SERVER['argv'][2];

/*
 * Make sure the file exists.
 */
if (file_exists($video_dir . $filename) === false) {
    $log->put('Could not import caption file, because ' . $video_dir . $filename . ' does not exist', 4);
    exit(1);
}

$db = new Database();
$db->connect_old();

$log = new Log();

/*
 * Get the metadata about this file.
 */
$metadata = json_decode(file_get_contents($video_dir . 'metadata.json'));

/*
 * Perform some basic cleanup on this WebVTT file.
 */
$captions = new Video();
$captions->webvtt = trim(file_get_contents($filename));
$captions->normalize_line_endings();
//$captions->offset = -18;
//$captions->time_shift_srt();

if (strlen($captions->webvtt) < 200) {
    $log->put('Captions file is implausibly short.', 4);
    return false;
}

/*
 * Store the WebVTT file in the database.
 */
$captions->file_id = $video_id;
if ($captions->store_webvtt() === false) {
    $log->put('WebVTT file could not be inserted into the database for video ID ' . $video_id
        . '.', 4);
}

/*
 * Turn the WebVTT file into a human-readable transcript (e.g., no timestamps).
 */
if ($captions->parse_webvtt() === false) {
    $log->put('Could not atomize WebVTT for video ID ' . $video_id . '.', 4);
    return false;
}

/*
 * Atomize the transcripts into tiny, time-bound chunks, and store them as individual DB records.
 */
if ($captions->captions_to_database() === false) {
    $log->put('Could not atomize captions and store them in the database for video ID ' . $video_id
        . '.', 4);
    return false;
}

/*
 * Attempt to resolve each spoken line to a given legislator. (They are not identified within the
 * transcripts, so it is necessary to infer their identity from the transcript text.)
 */
if ($captions->identify_speakers() === false) {
    $log->put('Could not identify speakers for captions stored in the database for video ID '
        . $video_id . '.', 4);
    return false;
}

/*
 * Generate a transcript.
 * INSERT THESE IN THE DATABASE AFTERWARDS.
 */
if ($captions->generate_transcript() === false) {
    $log->put('Could not identify speakers for captions stored in the database for video ID '
        . $video_id . '.', 4);
    return false;
}
