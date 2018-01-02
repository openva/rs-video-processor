#!/bin/bash

# Change to the directory containing this script.
cd "$(dirname "$0")" || exit

VIDEO_DIR="../video/"

# Make a videos directory, if it doesn't already exist.
mkdir -p $VIDEO_DIR

# Retrieve the video, saving it to a file and to S3.
php get_video.php || exit

# Figure out the filename and chamber we're processing.
cd $VIDEO_DIR || exit

# Turn the JSON into key/value pairs, and make them into Bash variables.
eval "$(jq -r '. | to_entries | .[] | .key + "=\"" + .value + "\""' < metadata.json)"

# Define the name of the directory that will store the extracted chyrons.
output_dir="${$filename/.mp4/}"

# OCR the video. This also generates screeshots and thumbnails.
../bin/ocr.sh "$filename" "$chamber" || exit

############
## TO DO
############

# Copy screenshots to S3.
# If the sync worked properly, delete screenshots.
cd "$VIDEO_DIR"
if aws s3 sync . s3://video.richmondsunlight.com/"$chamber"/floor/"$date" --exclude "*" --include "*.jpg"
then
	echo Deleting all local screenshots
	rm ./*.jpg
else
	echo "AWS S3 sync didn't finish successfully, so screenshots were not thumbnailed or deleted"
fi

# Create the record for this video in the database.
cd ..
VIDEO_ID="$(php save_metadata.php "$filename")" || exit

# Insert the chyrons into the database.
cd "$VIDEO_DIR"
php parse_video.php "$VIDEO_ID"

# Resolve the chyrons to individual legislators and bills.
php resolve_chyrons.php  "$VIDEO_ID"

#/home/ubuntu/youtube-upload-master/bin/youtube-upload '
#	. '--tags="virginia, legislature, general assembly" '
#	. '--default-language="en" '
#	. '--default-audio-language="en" '
#	. '--title="Virginia ' . ucfirst($video['chamber']) . ', ' . date('F j, Y', strtotime($video['date'])) . '" '
#	. '--recording-date="' . $DATE . 'T00:00:00.0Z" '
#	. $DATE
##

# Deal with captions

# Delete everything from the /video/ directory -- we're done with it.
rm -Rf ../video/

# Run itself again, in case there are more videos in the queue.
./handler.sh

# Stop this instance -- it's done.
#sudo shutdown -h now
