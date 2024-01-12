#!/bin/bash

# Get the test video.
echo "Downloading test House video from S3"
curl -s -o test-house-video.mp4 https://s3.amazonaws.com/deploy.richmondsunlight.com/test-house-video.mp4

# OCR the video.
export output_dir=test-house-video
bin/ocr.sh test-house-video.mp4 house

# Verify that the chyrons match what we expect them to be.
cd test-house-video/ || exit
if [[ $(grep -c Price ./*.txt) -lt 20 ]]; then
    error=true
    echo "Did not find 'Price' 20+ times"
fi
if [[ $(grep -c "Newport News" ./*.txt) -lt 8 ]]; then
    error=true
    echo "Did not find 'Newport News' 8+ times"
fi
if [[ $(grep -c "Todd Gilbert" ./*.txt) -lt 9 ]]; then
    error=true
    echo "Did not find 'Todd Gilbert' 9+ times"
fi
if [[ $(grep -c Shenandoah ./*.txt) -lt 9 ]]; then
    error=true
    echo "Did not find 'Shenandoah' 9+ times"
fi
if [[ $(grep -c Edmunds ./*.txt) -lt 1 ]]; then
    error=true
    echo "Did not find 'Edmunds' 1+ times"
fi

# Clean up
cd .. || exit
rm -Rf test-house-video/
rm test-house-video.mp4

# If there were any errors, fail
if [[ $error = true ]]; then
    exit 1
fi
