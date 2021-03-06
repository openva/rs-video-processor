#!/bin/bash

# Clean up before we're finished.
function finish {

	# Delete everything from the /video/ directory -- we're done with it.
	cd "$VIDEO_DIR" || exit
	cd ..
	rm -Rf video/

	if [[ -v "SHUTDOWN" ]]; then
		sleep 180
		USER_COUNT=$(who | sort --key=2,1 --unique | wc --lines)
		if [[ $USER_COUNT -lt 1 ]]; then
			sudo shutdown -h now
		fi
	else
		cd bin/ || exit
		./handler.sh || exit
	fi

}

# Run the finish() function every time this script exits for any reason.
trap finish EXIT

# Change to the directory containing this script.
cd "$(dirname "$0")" || exit 1

export VIDEO_DIR="../video/"

# Make a videos directory, if it doesn't already exist.
mkdir -p "$VIDEO_DIR"

# Retrieve the video, saving it to a file and to S3.
cd "$VIDEO_DIR" || exit 1
php ../bin/get_video.php
GOT_VIDEO=$?
if [ $GOT_VIDEO -eq 1 ]; then
	exit 1
elif [ $GOT_VIDEO -eq 2 ]; then
	export SHUTDOWN=1
	exit 1
fi

# Turn the JSON into key/value pairs, and make them into environment variables.
eval "$(jq -r '. | to_entries | .[] | .key + "=\"" + .value + "\""' < metadata.json)"
set "$filename"
set "$date"
set "$date_hyphens"
set "$s3_url"
set "$chamber"
set "$type"
set "$committee"
set "$step_all"
set "$step_download"
set "$step_screenshots"
set "$step_crop_chyrons"
set "$step_ocr_chyrons"
set "$step_create_clips"
set "$step_get_captions"
set "$step_save_captions"
set "$step_internet_archive"

if [ "$step_all" = true ]; then
	step_download=true
	step_screenshots=true
	step_crop_chyrons=true
	step_ocr_chyrons=true
	step_create_clips=true
	step_get_captions=true
	step_save_captions=true
	step_internet_archive=true
fi

# If no steps are defined, this is a legacy SQS message -- complete all steps.
if [ "$step_download" = false ] && [ "$step_screenshots" = false ] && [ "$step_crop_chyrons" = false ] \
	 && [ "$step_ocr_chyrons" = false ] && [ "$step_create_clips" = false ] \
	 && [ "$step_get_captions" = false ] && [ "$step_save_captions" = false ] \
	 && [ "$step_internet_archive" = false ]
then
	step_all=true
fi

# Define the name of the directory that will store the extracted chyrons.
export output_dir="${filename/.mp4/}"

# OCR the video. This also generates screeshots and thumbnails.
if [ "$step_screenshots" = true ]; then
	../bin/ocr.sh "$filename" "$chamber" "$committee" || exit $?

	# Move screenshots to S3.
	cd "$VIDEO_DIR" || exit $?
	cd "$output_dir" || exit $?
	if aws s3 sync . s3://video.richmondsunlight.com/"$chamber"/floor/"$date" --exclude "*" --include "*.jpg"
	then
		echo Deleting all local screenshots
		rm ./*.jpg
	else
		echo "AWS S3 sync didn't finish successfully, so screenshots were not thumbnailed or deleted"
	fi

fi

# Create the record for this video in the database.
cd ..
export VIDEO_ID="$(php bin/save_metadata.php "$filename" "$output_dir")" || exit $?

# Make sure that we got a valid video ID.
if [[ "$VIDEO_ID" =~ ^[0-9]+$ ]]; then
        echo "The video was stored in the database with video ID $VIDEO_ID"
else
        echo "Error: Unexpected response instead of a video ID: $VIDEO_ID "
        exit
fi

# Only deal with chyrons and captions for floor video.
if [ "$type" = "floor" ] && [ "$step_ocr_chyrons" = true ]; then

	# Insert the chyrons into the database.
	php ../bin/save_chyrons.php "$VIDEO_ID" "$output_dir" || exit $?

	# Resolve the chyrons to individual legislators and bills.
	php ../bin/resolve_chyrons.php "$VIDEO_ID" || exit $?

	# Retrieve the captions.
	CAPTIONS_FILE="$(php ../bin/get_captions.php "$chamber" "$date_hyphens")" || exit $?

	# Process the captions.
	php ../bin/process_captions.php "$CAPTIONS_FILE" "$VIDEO_ID" || exit $?

fi
