#!/bin/bash

cd ~; mkdir -p video-processor/ && cd video-processor/ && aws s3 cp s3://deploy.video.richmondsunlight.com/latest.zip latest.zip && unzip -o latest.zip
