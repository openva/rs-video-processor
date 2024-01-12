#!/bin/bash -e

cd ~
mkdir -p video-processor/
cd video-processor/
aws s3 cp s3://deploy.richmondsunlight.com/rs-video-processor.zip rs-video-processor.zip
unzip -o rs-video-processor.zip

# Run the postdeploy script
deploy/postdeploy.sh
