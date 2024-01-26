#!/bin/bash

cd /home/ubuntu/video-processor/

# Set up the crontab
CRONTAB="$(grep video-processor /etc/crontab)"
if [ -z "$CRONTAB" ]
then
    crontab deploy/crontab.txt
fi

cd ~

# Move the mplayer config file to its proper location.
[ -d ~/.mplayer/ ] || mkdir ~/.mplayer/
mv -f ~/video-processor/deploy/mplayer-config ~/.mplayer/config
