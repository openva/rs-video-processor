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
VIDEO_FILE="$(find ./*.mp4 |head -1)"
if [[ "$VIDEO_FILE" == "house"* ]]; then
	CHAMBER="house"
else
	CHAMBER="senate"
fi

# Remove the chamber from the filename.
NEW_FILENAME="${$VIDEO_FILE/$CHAMBER-/}"
mv "$VIDEO_FILE" "$NEW_FILENAME"
VIDEO_FILE="$NEW_FILENAME"

# Define the name of the directory that will store the extracted chyrons.
VIDEO_DIR="${$VIDEO_FILE/.mp4/}"

# Start the video processor.
../bin/process-video "$VIDEO_FILE" "$CHAMBER" || exit

############
## TO DO
############

# Copy screenshots to S3.
# If the sync worked properly, delete screenshots.
cd "$VIDEO_DIR"
if aws s3 sync . s3://video.richmondsunlight.com/"$CHAMBER"/floor/"$DATE" --exclude "*" --include "*.jpg"
then
	echo Deleting all local screenshots
	rm ./*.jpg
else
	echo "AWS S3 sync didn't finish successfully, so screenshots were not thumbnailed or deleted"
fi

# Create the record for this video in the database.
cd ..
VIDEO_ID="$(php save_metadata.php "$VIDEO_FILE")" || exit

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

# Delete everything from the /video/ directory -- we're done with it.
rm -Rf ../video/

# Run itself again, in case there are more videos in the queue.
./handler.sh

# Stop this instance -- it's done.
#sudo shutdown -h now
