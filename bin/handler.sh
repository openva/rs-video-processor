#!/bin/bash

# Change to the directory containing this script.
cd "$(dirname "$0")" || exit

# Retrieve the video, saving it to a file and to S3.
php get_video.php || exit

# Figure out the filename and chamber we're processing.
VIDEO_FILE="$(ls "*.mp4")"
if [[ "$VIDEO_FILE" == "house"* ]]; then
	CHAMBER="house";
else
	CHAMBER="senate";
fi

# Start the video processor.
./process-video "$VIDEO_FILE" "$CHAMBER"

# Delete this video (and any other video lingering about).
rm -f ./*.mp4

############
## TO DO
############

# Copy screenshots to S3.
# If the sync worked properly, delete screenshots.
if aws s3 sync . s3://video.richmondsunlight.com/"$CHAMBER"/floor/"$DATE" --exclude "*" --include "*.jpg"
then
	echo Deleting all local screenshots
	rm ./*.jpg
else
	echo "AWS S3 sync didn't finish successfully, so screenshots were not thumbnailed or deleted"
fi

# Create the record for this video in the database.
VIDEO_ID="$(php save_metadata.php "$VIDEO_FILE")" || exit

# Insert the chyrons into the database.
#php parse_video.php "$VIDEO_ID"

# Resolve the chyrons to individual legislators and bills.
#php resolve_chyrons.php  "$VIDEO_ID"

#/home/ubuntu/youtube-upload-master/bin/youtube-upload '
#	. '--tags="virginia, legislature, general assembly" '
#	. '--default-language="en" '
#	. '--default-audio-language="en" '
#	. '--title="Virginia ' . ucfirst($video['chamber']) . ', ' . date('F j, Y', strtotime($video['date'])) . '" '
#	. '--recording-date="' . $DATE . 'T00:00:00.0Z" '
#	. $DATE
##

# Run itself again, in case there are more videos in the queue.
./handler.sh

# Stop this instance -- it's done.
#sudo shutdown -h now
