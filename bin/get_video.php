<?php

# INCLUDES
# Include any files or libraries that are necessary for this specific
# page to function.
include_once(__DIR__ . '/../includes/settings.inc.php');
include_once(__DIR__ . '/../includes/functions.inc.php');
include_once(__DIR__ . '/../includes/vendor/autoload.php');

$log = new Log;

/*
 * Instantiate methods for AWS.
 */
use Aws\S3\S3Client;
$s3_client = new S3Client([
    'profile'	=> 'default',
  	'key'		=> AWS_ACCESS_KEY,
  	'secret'	=> AWS_SECRET_KEY,
    'region'	=> 'us-east-1',
    'version'	=> '2006-03-01'
]);

use Aws\Sqs\SqsClient;
$sqs_client = new SqsClient([
    'profile'	=> 'default',
  	'key'		=> AWS_ACCESS_KEY,
  	'secret'	=> AWS_SECRET_KEY,
    'region'	=> 'us-east-1',
    'version'	=> '2012-11-05'
]);

/*
 * Query SQS for any available videos.
 */
try
{

	$result = $sqs_client->ReceiveMessage([
		'QueueUrl' => 'https://sqs.us-east-1.amazonaws.com/947603853016/rs-video-harvester.fifo'
	]);
	if (count($result->get('Messages')) > 0)
	{
		$message = current($result->get('Messages'));
	}

}
catch (AwsException $e)
{
	$log->put('No pending videos found in SQS.', 1);
	exit(1);
}

/*
 * Pull the video information out of the message body.
 */
$video = json_decode($message['Body']);

if (!isset($video))
{
	$log->put('No pending videos found in SQS.', 1);
	exit(1);
}

/*
 * Take as long as necessary to get the video and then store it.
 */
set_time_limit(0);

# Retrieve the file and store it locally.
$video->filename = '../video/' . $video->date . '.mp4';
$fp = fopen($video->filename, 'w+');
$ch = curl_init($video->url);
curl_setopt($ch, CURLOPT_FILE, $fp);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
curl_exec($ch); 
curl_close($ch);
fclose($fp);

/*
 * Copy the file to S3.
 */
$s3_key = '/'  . $video->chamber . '/' . 'floor/' . $video->date . '.mp4';
$s3_url = 'https://s3.amazonaws.com/video.richmondsunlight.com/' . $s3_key;

try
{
	$result = $s3_client->putObject([
	    'Bucket'     => 'video.richmondsunlight.com',
	    'Key'        => $s3_key,
	    'SourceFile' => $video->filename
	]);

	$s3_client->waitUntil('ObjectExists', [
	    'Bucket' => 'video.richmondsunlight.com',
	    'Key'    => $s3_key
	]);
}
catch (S3Exception $e)
{
	$log->put('Could not upload video ' . $video->filename . ' to S3. Error reported: '
		. $e->getMessage(), 7);
	die();
}

/*
 * Now that we have saved the file to S3, delete the message from SQS.
 */
$result = $sqs_client->DeleteMessage([
				'QueueUrl' => 'https://sqs.us-east-1.amazonaws.com/947603853016/rs-video-harvester.fifo',
				'ReceiptHandle' => $message['ReceiptHandle']
			]);

/*
 * Save metadata about this to a JSON file, to be used elsewhere in the processing pipeline.
 * Note that all values must be strings, or else jq will not convert them to environment
 * variables correctly.
 */
$metadata = [];
$metadata['filename'] = $video->filename;
$metadata['date'] = (string)$video->date;
$metadata['date_hyphens'] = substr($video->date, 0, 4) . '-' . substr($video->date, 4, 2) . '-'
	. substr($video->date, 6, 2);
$metadata['s3_url'] = $s3_url;
$metadata['chamber'] = $video->chamber;
file_put_contents('../video/metadata.json', json_encode($metadata));

$log->put('Found and stored new ' . ucfirst($video->chamber) . ' video, for ' . $video->date
	. '.', 4);
