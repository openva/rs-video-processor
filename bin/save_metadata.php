<?php

require_once(__DIR__ . '/../includes/settings.inc.php');
require_once(__DIR__ . '/../includes/functions.inc.php');

$video_dir = (__DIR__ . '/../video');

/*
 * The filename must be specified at the command line.
 */
$filename = $_SERVER['argv'][1];

/*
 * Make sure the file exists.
 */
if (file_exists($filename) === FALSE)
{
	echo $filename . ' does not exist';
	exit(1);
}

/*
 * Instantiate the video class
 */
$video = new Video;

/*
 * Assemble data about this video file, which we'll use to create the database record for it.
 */
$file = array();

/*
 * Use mplayer to get some metadata about this video.
 */
$video->path = $filename;
if ($video->extract_file_data() === FALSE)
{
	echo 'Could not get metadata about ' . $filename . ' from mplayer';
	exit(1);
}

$file['fps'] = $video['fps'];
$file['width'] = $video['width'];
$file['height'] = $video['height'];
$file['length'] = $video['length'];
$file['capture_rate'] = $video['capture_rate'];


/*
 * Then, store information that we already know about this file.
 */
$file['path'] = $s3_url;
$file['chamber'] = ( strpos($file['chamber'], 'house') ? 'house' : 'senate');
$file['date'] = substr($filename, -14, 10);
$file['type'] = 'video';
$file['title'] = ucfirst($file['chamber']) . ' Video';

/*
 * Store this record in the database.
 */
$video->video = $file;
if ($video->submit() === FALSE)
{
	echo 'Metadata stored successfully';
	return 0;
}