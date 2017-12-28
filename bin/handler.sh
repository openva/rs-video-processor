#!/bin/bash

# Change to the directory containing this script.
cd "$(dirname "$0")" || exit

# Retrieve the video, saving it to a file and to S3.
php get_video.php || exit

# Start the video processor.
./process-video "$VIDEO_FILE" "$CHAMBER"

# Delete this video (and any other video lingering about).
rm -f ./*.mp4

# Run itself again, in case there are more videos in the queue.
./handler.sh

# Stop this instance -- it's done.
sudo shutdown -h now
