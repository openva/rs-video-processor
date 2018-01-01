<?php

# INCLUDES
# Include any files or libraries that are necessary for this specific
# page to function.
include_once(__DIR__ . '/../includes/settings.inc.php');
include_once(__DIR__ . '/../includes/functions.inc.php');
include_once(__DIR__ . '/../includes/vendor/autoload.php');

$video_dir = (__DIR__ . '/../video/');

/*
 * The filename must be specified at the command line.
 */
$filename = $_SERVER['argv'][1];

/*
 * Make sure the file exists.
 */
if (file_exists($video_dir . $filename) === FALSE)
{
	$log->put('Could not import caption file, because ' . $video_dir . $filename . ' does not exist', 4);
	exit(1);
}

$log = new Log;

/*
 * Get the metadata about this file.
 */
$metadata = json_decode(file_get_contents($video_dir . 'metadata.json'));

/*
 * Perform some basic cleanup on this WebVTT file.
 */
$captions = new Video;
$captions->webvtt = file_get_contents($filename);
$captions->normalize_line_endings();
$captions->eliminate_duplicates();
$captions->offset = -18;
$captions->time_shift_srt();

if (strlen($captions->webvtt) < 200)
{
	$log->put('Captions file is implausibly short.', 4);
	return FALSE;
}

/*
 * Store the WebVTT file in the database.
 */
$sql = 'UPDATE files
		SET webvtt = "' . mysql_real_escape_string($captions->webvtt) . '"
		WHERE id = ' . $_GET['id'];
$result = mysql_query($sql);
if ($result === FALSE)
{
	$log->put('WebVTT file could not be inserted into the database', 4);
}

/*
 * Turn the WebVTT file into a human-readable transcript (e.g., no timestamps).
 */
if ($captions->webvtt_to_transcript() === FALSE)
{
	$log->put('Could not generate transcript.', 4);
	return FALSE;
}
elseif (empty($captions->transcript))
{
	$log->put('No captions generated', 4);
	return FALSE;
}

/*
 * Atomize the transcripts into tiny, time-bound chunks, and store them as individual DB records.
 */
if ($captions->webvtt_to_database() === FALSE)
{
	$log->put('Could not atomize captions and store them in the database.', 4);
	return FALSE;
}

/*
 * Attempt to resolve each spoken line to a given legislator. (They are not identified within the
 * transcripts, so it is necessary to infer their identity from the transcript text.)
 */
if ($captions->identify_speakers() === FALSE)
{
	$log->put('Could not identify speakers for captions stored in the database.', 4);
	return FALSE;
}

