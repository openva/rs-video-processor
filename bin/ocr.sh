#!/bin/bash

# If the filename is missing, explain that it's required.
if [ -z "$1" ]; then
	echo "usage: $0 [YYYYMMDD.mp4] [chamber]"
	exit
fi

# If the chamber is missing, explain that it's required.
if [ -z "$2" ]; then
	echo "usage: $0 [YYYYMMDD.mp4] [chamber]"
	exit
fi

# Reassign command-line variables to named variables.
SRC="$1"
if [ -z "$CHAMBER" ]; then
	CHAMBER="$2"
fi

if [ ! -f "$SRC" ]; then
	echo "Error: $SRC does not exist"
	exit 1;
fi

if [ -z "$output_dir" ]; then
	echo "Error: output_dir is not set as an environment variable"
	exit 1;
fi

if [ "$CHAMBER" = "house" ]; then
	FRAMESTEP=150
elif [ "$CHAMBER" = "senate" ]; then
	FRAMESTEP=60
else
	echo "The chamber must be either 'house' or 'senate'."
	exit
fi

# Have mplayer create a folder full of screenshots.
if ! mplayer -vf framestep="$FRAMESTEP" -framedrop -nosound "$SRC" -speed 100 -vo jpeg:outdir="$output_dir"
then
	exit "$?"
fi

echo "Extracting names and bill numbers from each frame"

cd "$output_dir" || exit

# Standardize screenshot dimensions
if ! mogrify -resize 640x480 ./*
then
	echo "Couldn't resize all images"
	exit "$?"
fi

# All dimensions are width x height, horizontal offset + vertical offset (WxH+H+V).
if [ "$CHAMBER" = "house" ]; then
	NAME_CROP="120x380+333+60"
	BILL_CROP="465x42+129+27"
elif [ "$CHAMBER" = "senate" ]; then
	NAME_CROP="345x60+176+340"
	BILL_CROP="172x27+0+40"
elif [ "$CHAMBER" = "house-committee" ]; then
	NAME_AND_BILL_CROP="176x340+398+39"
elif [ "$CHAMBER" = "senate-committee" ]; then
	NAME_CROP="345x60+176+340"
	BILL_CROP="172x27+0+40"
fi

if [[ -v NAME_CROP ]]; then
	for f in *[0-9].jpg; do convert "$f" -crop "$NAME_CROP" +repage -negate -fx '.8*r+.8*g+0*b' -compress none -depth 8 "$f".name.jpg; done
	for f in *[0-9].jpg; do convert "$f" -crop "$BILL_CROP" +repage -negate -fx '.8*r+.8*g+0*b' -compress none -depth 8 "$f".bill.jpg; done
else

	for f in *[0-9].jpg; do convert "$f" -crop "$NAME_AND_BILL_CROP" +repage -negate -fx '.8*r+.8*g+0*b' -compress none -depth 8 "$f".chyron.jpg; done
fi	

echo "OCRing names and bill numbers"

# We do this in two steps to avoid exceeding the limits of ls.
find . -type f -name '*.name.jpg' -exec tesseract {} {} \;
find . -type f -name '*.bill.jpg' -exec tesseract {} {} \;

# Delete all of the images that we just OCRed.
find . -type f -name '*.name.jpg' -exec rm {} \;
find . -type f -name '*.bill.jpg' -exec rm {} \;

# Duplicate all JPEGs with a -150 suffix.
for F in $(find ./*.jpg -maxdepth 1 |awk -F. '{print $2}')
do
	cp ."$F".jpg ."${F}"-150.jpg
done

# Create thumbnails of all *150.jpg screenshots.
echo Creating thumbnails of screenshots
mogrify -resize 150x112 ./*-150.jpg
