#!/bin/bash

cd /home/ubuntu/video-processor/

# Set up the crontab
CRONTAB="$(video-processor /etc/crontab)"
if [ -z "$CRONTAB" ]
then
    cat deploy/crontab.txt |sudo tee -a /etc/crontab
fi

# Move the mplayer config file to its proper location.
[ -d ~/.mplayer/ ] || mkdir ~/.mplayer/
mv -f ~/video-processor/deploy/mplayer-config ~/.mplayer/config
