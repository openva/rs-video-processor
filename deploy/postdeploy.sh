#!/bin/bash

cd /home/ubuntu/video-processor/

# Set up the crontab
CRONTAB="$(video-processor /etc/crontab)"
if [ -z "$CRONTAB" ]
then
    cat deploy/crontab.txt |sudo tee -a /etc/crontab
fi
