<?php

require_once(__DIR__ . '/../includes/settings.inc.php');
require_once(__DIR__ . '/../includes/functions.inc.php');
include_once(__DIR__ . '/../includes/vendor/autoload.php');

$video_dir = (__DIR__ . '/../video/');

/*
 * Connect to the database.
 */
$db = new Database;
$db->connect_old();

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
if (file_exists($video_dir . $filename) === FALSE)
{
	echo $video_dir . $filename . ' does not exist';
	exit(1);
}

/*
 * Get the metadata about this file.
 */
$metadata = json_decode(file_get_contents($video_dir . 'metadata.json'));

/*
 * Identify the committee ID for this video.
 */
if ($metadata->type == 'committee')
{

	/*
	 * First, get a list of all committees' names and IDs.
	 */
	$sql = 'SELECT id, name
			FROM committees
			WHERE parent_id IS NULL
			AND chamber = "' . $metadata->chamber . '"';
	$result = mysql_query($sql);
	if (mysql_num_rows($result) > 0)
	{

		$committees = array();
		while ($committee = mysql_fetch_array($result))
		{
			$committees[] = $committee;
		}

		$shortest = -1;
		foreach ($committees as $id => $name)
		{

			$distance = levenshtein($metadata->committee, $name);
			if ($distance === 0)
			{
				$closest = $id;
				$shortest = 0;
				break;
			}

			elseif ($distance <= $shortest || $shortest < 0)
			{
				$closest = $id;
				$shortest = $distance;
			}

		}

		$metadata->committee_id = $closest;

	}

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

/*
 * Store this record in the database.
 */
$video->video = $file;
if ($video->submit() !== FALSE)
{
	echo $video->id;
	return;
}
