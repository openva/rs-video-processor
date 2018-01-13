#!/bin/bash

cd /home/ubuntu/video-processor/

# Set up the crontab
cat deploy/crontab.txt |sudo tee -a /etc/crontab
