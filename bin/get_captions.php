<?php

require_once(__DIR__ . '/../includes/settings.inc.php');
require_once(__DIR__ . '/../includes/functions.inc.php');

$video_dir = (__DIR__ . '/../video/');

/*
 * Get the chamber and date from the CLI.
 */
$chamber = trim($_SERVER['argv'][1]);
$date = trim($_SERVER['argv'][2]);
if ( empty($chamber) || empty($date) )
{
	exit('Chamber and date required.');
}

/*
 * Instantiate our logging class.
 */
$log = new Log;

/*
 * Get the metadata about this file.
 */
$metadata = json_decode(file_get_contents($video_dir . 'metadata.json'));

/*
 * Define the URLs for each of the two chambers.
 */
$chambers = array(
				'house' => 'http://virginia-house.granicus.com/ViewPublisher.php?view_id=3',
				'senate' => 'http://virginia-senate.granicus.com/ViewPublisher.php?view_id=3'
				);

/*
 * Use the URL for the requested chamber.
 */
$url = $chambers[$chamber];

/*
 * Get the HTML for this chamber's video archives.
 */
$list_html = get_content($url);
$pcre = '/(?<timestamp>[0-9]{10})(?:.+?)&clip_id=(?<clip_id>[0-9]{3,5})/s';
preg_match_all($pcre, $list_html, $matches);

if (count($matches) == 0)
{
	exit('No video clips found on the Granicus server.');
}

/*
 * Iterate through every video until we find the one in question.
 */
for ($i=0; $i < count($matches['timestamp']); $i++)
{

	$clip_date = date('Y-m-d', $matches['timestamp'][$i]);
	$clip_id = $matches['clip_id'][$i];

	if ($date == $clip_date)
	{
		break;
	}

}

/*
 * The date from the clip needs to match the 
 */
if ($date != $clip_date)
{
	exit('No captions found.');
}

$captions = file_get_contents('http://virginia-house.granicus.com/videos/' . $clip_id
	. '/captions.vtt');
if ($captions !== FALSE)
{
	$filename = str_replace('-', '', $date) . '.vtt';
	file_put_contents($video_dir . $filename, $captions);
}

echo $filename;
