#!/bin/bash

# Clean up before we're finished.
function finish {

	# Delete everything from the /video/ directory -- we're done with it.
	cd "$VIDEO_DIR" || exit
	cd ..
	rm -Rf ../video/
	
	./handler.sh
	
	#sudo shutdown -h now

}

# Run the finish() function every time this script exits for any reason.
trap finish EXIT

# Change to the directory containing this script.
cd "$(dirname "$0")" || exit

export VIDEO_DIR="../video/"

# Make a videos directory, if it doesn't already exist.
mkdir -p $VIDEO_DIR

# Retrieve the video, saving it to a file and to S3.
php get_video.php || exit

# Figure out the filename and chamber we're processing.
cd "$VIDEO_DIR" || exit

# Turn the JSON into key/value pairs, and make them into Bash variables.
export eval "$(jq -r '. | to_entries | .[] | .key + "=\"" + .value + "\""' < metadata.json)"

# Define the name of the directory that will store the extracted chyrons.
export output_dir="${filename/.mp4/}"

# OCR the video. This also generates screeshots and thumbnails.
../bin/ocr.sh "$filename" "$chamber" || exit

# Move screenshots to S3.
cd "$VIDEO_DIR" || exit
cd "$output_dir" || exit
if aws s3 sync . s3://video.richmondsunlight.com/"$chamber"/floor/"$date" --exclude "*" --include "*.jpg"
then
	echo Deleting all local screenshots
	rm ./*.jpg
else
	echo "AWS S3 sync didn't finish successfully, so screenshots were not thumbnailed or deleted"
fi

# Create the record for this video in the database.
cd ..
export VIDEO_ID="$(php ../bin/save_metadata.php "$filename" "$output_dir")" || exit

# Insert the chyrons into the database.
php ../bin/parse_video.php "$VIDEO_ID"

# Resolve the chyrons to individual legislators and bills.
php ../bin/resolve_chyrons.php "$VIDEO_ID"

# Resolve the chyrons to individual legislators and bills.
php ../bin/get_captions.php "$chamber" "$date"

# Deal with captions
php ../bin/process_captions.php "$chamber" "$date"

#/home/ubuntu/youtube-upload-master/bin/youtube-upload '
#	. '--tags="virginia, legislature, general assembly" '
#	. '--default-language="en" '
#	. '--default-audio-language="en" '
#	. '--title="Virginia ' . ucfirst($video['chamber']) . ', ' . date('F j, Y', strtotime($video['date'])) . '" '
#	. '--recording-date="' . $DATE . 'T00:00:00.0Z" '
#	. $DATE
##

