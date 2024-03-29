<?php

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/functions.inc.php';

$video_dir = (__DIR__ . '/../video');

# Connect to the database.
$database = new Database();
$db = $database->connect();

# Convert a count of seconds to HH:MM:SS format.
function format_time($secs)
{
    return gmdate('H:i:s', $secs);
}

/*
 * Get the video ID from the command line.
 */
$video_id = $_SERVER['argv'][1];
if (!isset($video_id) || empty($video_id)) {
    die('No video ID specified.');
}

/*
 * Optionally get the capture directory from the command line.
 */
if (isset($_SERVER['argv'][2])) {
    $capture_directory = $_SERVER['argv'][2];
}

$sql = 'SELECT file_id
		FROM video_index
		WHERE file_id=' . $video_id;
$stmt = $db->prepare($sql);
$stmt->execute();
if ($stmt->fetch() === true) {
    die('This video has already been parsed!');
}

$sql = 'SELECT chamber, path, capture_directory, length, capture_rate, capture_directory, date,
		fps, width, height
		FROM files
		WHERE id=' . $video_id;
$stmt = $db->prepare($sql);
$stmt->execute();
$file = $stmt->fetch();
if ($file == false) {
    die('Invalid video ID specified');
}
$file = array_map('stripslashes', $file);

/*
 * If a capture directory has been specified on the command line, use that instead.
 */
if (!empty($capture_directory)) {
    $file['capture_directory'] = $capture_directory;
}

/*
 * If we're missing some basic information, start by trying to fill it in from available data within
 * the database.
 */
if (empty($file['capture_directory']) && !empty($file['date'])) {
    $file['capture_directory'] = '/video/' . $file['chamber'] . '/floor/'
        . str_replace('-', '', $file['date']) . '/';

    /*
     * If the directory turns out not to exist, though, abandon ship.
     */
    if (!file_exists($video_dir . $file['capture_directory'])) {
        echo 'No such directory as ' . $file['capture_directory'];
        echo 'You must go to the command line and run ~/process-video ' . $file['capture_directory'] . '.mp4 [chamber]';
        unset($file['capture_directory']);
    }
}

# Store the environment variables.
$video['id'] = $video_id;
$video['chamber'] = $file['chamber'];   // The chamber of this video.
$video['where'] = 'floor';              // Where the video was taken. Most will be "floor."
$video['date'] = $file['date'];         // The date of the video in question.
$video['fps'] = $file['fps'];           // The frames per second at which the video was played.
$video['capture_rate'] = $file['capture_rate']; // We captured every X frames. "Framestep," in mplayer terms.
$video['dir'] = $file['capture_directory'] . '/';
if (!isset($capture_directory)) {
    $video['dir'] = $video_dir . $file['capture_directory'];
}

# Iterate through the video array and make sure nothing is blank. If so, bail.
foreach ($video as $name => $option) {
    if (empty($option)) {
        die('Cannot parse video without specifying ' . $name . ' in the files table.');
    }
}

# Store the directory contents as an array.
$dir = scandir($video['dir']);

# Iterate through every file in the directory.
foreach ($dir as $file) {
    # Save the image number for use later. Note that this is not the literal frame number from the
    # video, but rather just the capture number. That is, there might be 300 frames of video in
    # 10 seconds of video, but if we capture just 2 screenshots in those 10 seconds, the first frame
    # number will be 1 and the second will be 2.
    $image_number = substr($file, 0, 8);

    if (substr($file, -4) != '.txt') {
        continue;
    }

    # If the filename indicates that this is a bill number
    if (strstr($file, 'bill')) {
        $bill = trim(file_get_contents($video['dir'] . $file));
        $type = 'bill';
    }

    # Otherwise if the filename indicates that this is a legislator's name
    elseif (strstr($file, 'name')) {
        $legislator = trim(file_get_contents($video['dir'] . $file));

        # Fix a common OCR mistake.
        $legislator = str_replace('—', '-', $legislator);

        $type = 'legislator';
    }

    # Check to see if these are really blank or implausibly short and, if so, don't actually
    # store them.
    if (($type == 'bill') && (empty($bill) || (strlen($bill) < 3))) {
        continue;
    }
    if (($type == 'legislator') && (empty($legislator) || (strlen($legislator) < 10))) {
        continue;
    }

    # If this string consists of a low percentage of low-ASCII characters, we can skip it.
    if ((isset($bill) && strlen($bill) > 0)) {
        $invalid = 0;
        foreach (str_split($bill) as $character) {
            if (ord($character) > 127) {
                $invalid++;
            }
        }
        if (($invalid / strlen($bill)) > .33) {
            unset($bill);
        }

        # If the bill has no numbers, then it's not a bill.
        elseif (!preg_match('/[0-9]/Di', $bill)) {
            unset($bill);
        }
    } elseif (isset($legislator)) {
        $invalid = 0;
        foreach (str_split($legislator) as $character) {
            if (ord($character) > 127) {
                $invalid++;
            }
        }
        if (($invalid / strlen($legislator)) > .33) {
            unset($legislator);
        }

        # If the legislator chyron lacks three consecutive letters, it's probably not a
        # legislator (or, if it is, we'll never figure it out).
        elseif (!preg_match('/([a-z]{3})/Di', $legislator)) {
            unset($legislator);
        }
    }

    # If we've successfully gotten a bill number or a legislator name.
    if (isset($bill) || isset($legislator)) {
        # Determine how many seconds into this video this image appears, converting it (with
        # a custom function) into HH:MM:SS format, stepping back five seconds as a buffer.
        $time = format_time((($video['capture_rate'] / $video['fps']) * $image_number) - 5);

        # Assemble the beginnings of a SQL string.
        $sql = 'INSERT INTO video_index
				SET file_id=' . $video['id'] . ', time="' . $time . '",
				screenshot="' . $image_number . '", date_created=now(), ';

        if (isset($bill)) {
            # Finish assembling the SQL string.
            $sql .= 'type="bill", raw_text="' . addslashes($bill) . '"';

            echo $bill . "\n";

            # Unset this variable so that we won't use it the next time around.
            unset($bill);
        }

        # Else if we've successfully gotten a legislator's name.
        elseif (isset($legislator)) {
            # Finish assembling the SQL string.
            $sql .= 'type="legislator", raw_text="' . addslashes($legislator) . '"';

            echo $legislator . "\n";

            # Unset this variable so that we won't use it the next time around.
            unset($legislator);
        }

        $insert_stmt = $db->prepare($sql);
        $insert_stmt->execute();

        unset($sql);
    }

    # Delete this file, now that we've handled it.
    unlink($video['dir'] . '/' . $file);

    # We've used this a few times here, so let's unset it, just in case.
    unset($tmp);
}
