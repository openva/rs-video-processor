#!/bin/bash

# Example of Base URL: http://sg001-vod.sliq.net/00285-vod/_definst_/2016/03/House%20in%20Session_2016-03-22-13.58.50_2461_2.mp4
BASEURL=$1

# Allow up to 9,999 video fragments to be requested.
MAX=9999

# Use this download directory.
DOWNLOAD_DIRECTORY=/tmp/$(echo "$BASEURL" |md5sum |cut -d " " -f 1)

# Iterate through every fragment and save it
for i in $(seq -f "%04g" 0 $MAX); do
    wget "$BASEURL/media_$i.ts" -O "$DOWNLOAD_DIRECTORY/video-$i.mp4" || break
done

# change the ulimit -n 1024 to a higher number if your MAX ever gets higher than 900 or so.
ulimit -n 1024

# Must have FFMPEG installed for this to work
ffmpeg -i concat:"$(find "$DOWNLOAD_DIRECTORY/*.mp4" | tr '\n' '|')" -codec copy -bsf:a aac_adtstoasc output.mp4

# Remove all of the MP4 fragments
rm -Rf "$DOWNLOAD_DIRECTORY"
