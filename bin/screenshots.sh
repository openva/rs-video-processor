#!/bin/bash

# Duplicate all JPEGs with a -150 suffix.
for F in $(find ./*.jpg -maxdepth 1 |awk -F. '{print $2}')
do
	cp ."$F".jpg ."${F}"-150.jpg
done

if [ -z "$output_dir" ]; then
	echo "Error: output_dir is not set as an environment variable"
	exit 1
fi

cd "$output_dir" || exit

# Create thumbnails of all *150.jpg screenshots.
echo Creating thumbnails of screenshots
mogrify -resize 150x112 ./*-150.jpg
