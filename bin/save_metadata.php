<?php

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/functions.inc.php';
include_once __DIR__ . '/../includes/vendor/autoload.php';

$video_dir = (__DIR__ . '/../video/');

define('CLI_ROOT', '/home/ubuntu/video-processor');

/*
 * Connect to the database.
 */
$database = new Database;
$db = $database->connect_mysqli();

/*
 * The filename must be specified at the command line.
 */
$filename = $_SERVER['argv'][1];
$capture_dir = $_SERVER['argv'][2];

if (empty($filename))
{
    die('You must specify the filename');
}

if (empty($capture_dir))
{
    die('You must specify the output directory');
}

/*
 * Make sure the file exists.
 */
if (file_exists(CLI_ROOT . $video_dir . $filename) === FALSE)
{
    echo CLI_ROOT . $video_dir . $filename . ' does not exist';
    exit(1);
}

/*
 * Get the metadata about this file.
 */
$metadata = json_decode(file_get_contents(CLI_ROOT . $video_dir . 'metadata.json'));

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
$video->capture_directory = $capture_dir;
if ($video->extract_file_data() === FALSE)
{
    echo 'Could not get metadata about ' . $filename . ' from mplayer';
    exit(1);
}

$file['fps'] = $video->fps;
$file['width'] = $video->width;
$file['height'] = $video->height;
$file['length'] = $video->length;
$file['capture_rate'] = $video->capture_rate;

/*
 * Then, add information that we already know about this file.
 */
$file['path'] = $metadata->s3_url;
$file['chamber'] = $metadata->chamber;
$file['date'] = $metadata->date_hyphens;
$file['type'] = 'video';
$file['title'] = ucfirst($file['chamber']) . ' Video';
$file['capture_directory'] = '/video/' . $metadata->chamber . '/floor/' . $metadata->date .'/';
if (!empty($metadata->committee_id))
{
    $file['committee_id'] = $metadata->committee_id;
}
elseif (!empty($metadata->committee))
{
    $committee = new Committee;
    $committee->chamber = $metadata->chamber;
    $committee->name = $metadata->committee;
    $file['committee_id'] = $committee->get_id();
}

/*
 * Store this record in the database.
 */
$video->video = $file;
if ($video->submit() !== FALSE)
{
    echo $video->id;
}
else
{
    exit(1);
}
