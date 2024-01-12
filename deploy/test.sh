#!/bin/bash

# Get the test video.
echo "Downloading test House video from S3"
if ! curl -s -o test-house-video.mp4 https://s3.amazonaws.com/deploy.richmondsunlight.com/test-house-video.mp4; then
    echo "cURL video download failed"
    exit 1
fi

# OCR the video.
export output_dir=test-house-video
if ! bin/ocr.sh test-house-video.mp4 house; then
    echo "ocr.sh process failed"
    exit 1
fi

# Verify that the chyrons match what we expect them to be.
error=false
cd test-house-video/ || exit
if [[ $(grep Price ./*.txt |wc -l) -lt 20 ]]; then
    error=true
    echo "Did not find 'Price' 20+ times"
fi
if [[ $(grep "Newport News" ./*.txt |wc -l) -lt 8 ]]; then
    error=true
    echo "Did not find 'Newport News' 8+ times"
fi
if [[ $(grep "Todd Gilbert" ./*.txt |wc -l) -lt 9 ]]; then
    error=true
    echo "Did not find 'Todd Gilbert' 9+ times"
fi
if [[ $(grep Shenandoah ./*.txt |wc -l) -lt 9 ]]; then
    error=true
    echo "Did not find 'Shenandoah' 9+ times"
fi
if [[ $(grep Edmunds ./*.txt |wc -l) -lt 1 ]]; then
    error=true
    echo "Did not find 'Edmunds' 1+ times"
fi

# Clean up
cd .. || exit
rm -Rf test-house-video/
rm test-house-video.mp4

# If there were any errors, fail
if [[ "$error" = true ]]; then
    exit 1
fi
