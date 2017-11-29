<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'settings.inc.php';
include 'functions.inc.php';

/*
 * Iterate through the chambers.
 */
$chambers = array(
				'house' => 'http://virginia-house.granicus.com/ViewPublisher.php?view_id=3',
				'senate' => 'http://virginia-senate.granicus.com/ViewPublisher.php?view_id=3'
				);
foreach ($chambers as $chamber => $url)
{

	$list_html = get_content($url);
	$pcre = '/<\/span>(?<date>(?:[a-zA-Z]{3}) (?:[0-9]{1,2}), (?:[0-9]{4}))<\/td>(?:.+?)&clip_id=(?<clip_id>[0-9]{3,5})/s';
	preg_match_all($pcre, $list_html, $matches);

	if (count($matches) == 0)
	{
		exit('No matches found.');
	}

	unset($matches[0]);
	$total = count($matches['date']);
	$files = array();

	for ($i=0; $i<$total; $i++)
	{
		$files[$i]['date'] = date('Y-m-d', strtotime($matches['date'][$i]));
		$files[$i]['clip_id'] = $matches['clip_id'][$i];
	}

	/*
	 * Iterate through each of the files.
	 */
	foreach ($files as $file)
	{

		$captions = Video::fetch_granicus_captions($file['clip_id']);
		if ($captions !== FALSE)
		{
			$filename = str_replace('-', '', $file['date']) . '-captions.json';
			file_put_contents($_SERVER['DOCUMENT_ROOT'] . 'video/' . $chamber . '/floor/' . $filename, $captions);
		}

	}

	echo 'Done with ' . ucfirst($chamber);

}
