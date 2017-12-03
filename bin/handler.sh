#!/bin/bash

# Retrieve the video, saving it to a file and to S3.
php get_video.php

# Start the video processor.
./process-video "$VIDEO_FILE" "$CHAMBER"

# Delete this video (and any other video lingering about).
rm -f *.mp4
