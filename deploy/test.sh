#!/bin/bash

# Get the test video.
echo "Downloading test House video from S3"
curl -s -o test-house-video.mp4 https://s3.amazonaws.com/deploy.richmondsunlight.com/test-house-video.mp4

# OCR the video.
set output_dir=test-house-video
ocr.sh test-house-video.mp4 house

# Verify that the chyrons match what we expect them to be.
cat *.txt
