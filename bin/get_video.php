<?php

/*
 * @returns 0 if successful
 * @returns 1 if this one video fails, but there may be another
 * @returns 2 if the queue is empty
 */

# INCLUDES
# Include any files or libraries that are necessary for this specific
# page to function.
include_once __DIR__ . '/../includes/settings.inc.php';
include_once __DIR__ . '/../includes/functions.inc.php';
include_once __DIR__ . '/../includes/vendor/autoload.php';

$log = new Log();

/*
 * Define the URL for SQS.
 */
define('SQS_URL', 'https://sqs.us-east-1.amazonaws.com/947603853016/rs-video-harvester.fifo');

/*
 * Submit this video back to the queue. We run this if this process fails in any way.
 */
function requeue($message)
{

    global $sqs_client;
    global $log;
    global $message;
    global $video;
    global $url;

    /*
     * We're getting some extra slashes added to URLs, rendering them invalid. Strip slashes before
     * requeuing the video.
     */
    $message->url = stripslashes($message->url);

    /*
     * Log this to SQS.
     */
    $sqs_client->sendMessage([
        'MessageGroupId'            => '1',
        'MessageDeduplicationId'    => mt_rand(),
        'QueueUrl'                  => SQS_URL,
        'MessageBody'               => json_encode($message)
    ]);

    $log->put('Requeued ' . $video->chamber . ' ' . $video->type . ' video for ' . $video->date
        . '.', 5);
}

/*
 * Delete this message from SQS.
 */
function delete($message)
{

    global $sqs_client;

    /*
     * Now that we have the message, delete it from SQS.
     */
    $sqs_client->DeleteMessage([
        'QueueUrl' => SQS_URL,
        'ReceiptHandle' => $message['ReceiptHandle']
    ]);
}

/*
 * Instantiate methods for AWS.
 */
use Aws\S3\S3Client;

$s3_client = new S3Client([
    'profile'   => 'default',
    'key'       => AWS_ACCESS_KEY,
    'secret'    => AWS_SECRET_KEY,
    'region'    => 'us-east-1',
    'version'   => '2006-03-01'
]);

use Aws\Sqs\SqsClient;

$sqs_client = new SqsClient([
    'profile'   => 'default',
    'key'       => AWS_ACCESS_KEY,
    'secret'    => AWS_SECRET_KEY,
    'region'    => 'us-east-1',
    'version'   => '2012-11-05'
]);

/*
 * Query SQS for any available videos.
 */
try {
    $result = $sqs_client->ReceiveMessage([
        'QueueUrl' => SQS_URL,
    ]);
    if (count($result->get('Messages')) > 0) {
        $message = current($result->get('Messages'));
    } else {
        $log->put('No pending videos found in SQS.', 1);
        exit(2);
    }
} catch (AwsException $e) {
    $log->put('No pending videos found in SQS.', 1);
    exit(1);
}

/*
 * Pull the video information out of the message body.
 */
$video = json_decode($message['Body']);

if (!isset($video)) {
    $log->put('No pending videos found in SQS.', 1);
    exit(1);
}

$log->put('Found video: ' . print_r($video, true), 5);

/*
 * Decline to process old videos, which the RSS feed coughs up sometimes.
 */
if ((bool) strtotime($video->date) && (substr($video->date, 0, 4) != SESSION_YEAR)) {
    $log->put('Not processing video from ' . $video->date . ', because it’s too old.', 5);
    delete($message);
    exit(1);
}

/*
 * Decline to process videos with invalid URLs, as can happen.
 */
if (filter_var($video->url, FILTER_VALIDATE_URL) === false) {
    $log->put('Not processing video from ' . $video->url . ', because that is not a valid URL.', 5);
    delete($message);
    exit(1);
}

/*
 * Delete this message from SQS.
 */
delete($message);

/*
 * Take as long as necessary to get the video and then store it.
 */
set_time_limit(0);

/*
 * Retrieve the file and store it locally.
 */
$video->filename = $video->chamber . '-' . $video->type . '-' . $video->date . '.mp4';
$fp = fopen('../video/' . $video->filename, 'w+');
$ch = curl_init($video->url);
curl_setopt($ch, CURLOPT_FILE, $fp);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$result = curl_exec($ch);
$curl_error = curl_error($ch);
curl_close($ch);
fclose($fp);

/*
 * If the file transfer failed.
 */
if ($result == false || !file_exists('../video/' . $video->filename)) {
    $log->put('Abandoning ' . $video->filename . ' because it could not be retrieved from ' .
        $video->url . ' . cURL error: ' . $curl_error, 7);
    unset($video->filename);
    requeue($video);
    exit(1);
}

/*
 * If the file is less than 1 MB, we've gotten an HTML error page instead of video.
 */
if (filesize('../video/' . $video->filename) < 1048576) {
    $log->put('The ' . $video->chamber . ' ' . $video->type . ' video for ' . $video->date
        . ', at ' . $video->url . ' is returning HTML instead of video. Requeuing for later '
        . 'retrieval and analysis.', 7);
    unset($video->filename);
    requeue($video);
    exit(1);
}

/*
 * Connect to the database.
 */
$database = new Database();
$db = $database->connect_mysqli();

/*
 * Get committee info.
 */
if ($video->type == 'committee') {
    $committee = new Committee();
    $committee->chamber = $video->chamber;
    $committee->name = $video->committee;
    $committee->id = $committee->get_id();
    $committee->info();
    if (!isset($committee->shortname) || !isset($committee->id)) {
        $log->put('Could not identify the committee shortname or ID for the committee named '
            . '"' . $video->committee . '" — abandoning ' . $video->filename . '.', 6);
        die();
    }
    $video->committee_id = $committee->id;
    $video->committee_shortname = $committee->shortname;
}

/*
 * Copy the file to S3.
 */
if ($video->type == 'floor') {
    $s3_key = $video->chamber . '/' . 'floor/' . $video->date . '.mp4';
} elseif ($video->type == 'committee') {
    $s3_key = $video->chamber . '/' . 'committee/' . urlencode(strtolower($video->committee_shortname)) . '/' . $video->date . '.mp4';
}
$s3_url = 'https://s3.amazonaws.com/video.richmondsunlight.com/' . $s3_key;

try {
    $result = $s3_client->putObject([
        'Bucket'     => 'video.richmondsunlight.com',
        'Key'        => $s3_key,
        'SourceFile' => '../video/' . $video->filename
    ]);

    $s3_client->waitUntil('ObjectExists', [
        'Bucket' => 'video.richmondsunlight.com',
        'Key'    => $s3_key
    ]);
} catch (S3Exception $e) {
    $log->put('Could not upload video ' . $video->filename . ' to S3. Error reported: '
        . $e->getMessage(), 6);
    die();
}

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
$metadata['type'] = $video->type;
if ($video->type == 'committee') {
    $metadata['committee'] = $video->committee;
}
file_put_contents('../video/metadata.json', json_encode($metadata));

$video_handler = new Video();

/*
 * Get metadata about the video.
 */
$video_handler->path = $video->filename;
$video_handler->video = (array) $video;
$video_handler->extract_file_data();

/*
 * Assign any missing data.
 */
$video->path = $metadata['s3_url'];
$video->date = $metadata['date_hyphens'];

/*
 * Save this video to the database.
 */
foreach ((array) $video as $key => $value) {
    $video_handler->video[$key] = $value;
}
$video_handler->submit();

$log->put('Stored new ' . ucfirst($video->chamber) . ' ' . $video->type . ' video, for '
    . $video->date . ': ' . $video->path, 4);
