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
    'profile' => 'default'
]);

use Aws\Sqs\SqsClient;
$sqs_client = new SqsClient([
    'profile' => 'default',
    'region'  => 'us-east-1'
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
		$video = $result->get('Messages')[0];
	}

}
catch (AwsException $e)
{
	$log->put('No pending videos found in SQS.', 1);
	exit(1);
}

/*
 * Take as long as necessary to get the video and then store it,
 */
set_time_limit(0);


$video['filename'] = $video['chamber'] . '-' . $video['date'] . '.mp4';
$fp = fopen($video['filename'], 'w+');
$ch = curl_init($video['url']);
curl_setopt($ch, CURLOPT_FILE, $fp);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
curl_exec($ch); 
curl_close($ch);
fclose($fp);

/*
 * Move the file to S3.
 */
$s3_key = '/'  . $video['chamber'] . '/' . 'floor/' . $video['date'] . '.mp4';
$s3_url = 'https://s3.amazon.com' . $s3_key;

try
{
	$result = $s3_client->putObject([
	    'Bucket'     => 'video.richmondsunlight.com',
	    'Key'        => $s3_key,
	    'SourceFile' => $video['filename']
	]);

	$s3_client->waitUntil('ObjectExists', [
	    'Bucket' => 'video.richmondsunlight.com',
	    'Key'    => $s3_key
	]);
}
catch (S3Exception $e)
{
	$log->put('Could not upload video ' . $filename . ' to S3. Error reported: '
		. $e->getMessage(), 7);
	die();
}

$log->put('Found and stored new ' . ucfirst($video['chamber']) . ' video, for ' . $video['date']
	. '.', 4);
