#!/bin/bash

####
#### TO DO
#### SET $date SO WE HAVE AN OUTPUT DIRECTORY
#### SUPPORT SENATE COMMITTEE VIDEO...SOMEHOW
#### SUPPORT SHORT-FORM HOUSE COMMITTEE VIDEO
####

# If the filename is missing, explain that it's required.
if [ -z "$1" ]; then
	echo "usage: $0 [YYYYMMDD.mp4] [chamber]"
	exit
fi

# If the chamber hasn't been specified, try to guess it.
if [ -z "$2" ]; then
	FILE_PATH=$(dirname $(cd "$(dirname "$1")"; pwd)/$(basename "$1"))
	if [[ "$FILE_PATH" == *"house"* ]]; then
		CHAMBER="house"
	elif [[ "$FILE_PATH" == *"senate"* ]]; then
		CHAMBER="senate"
	elif [[ ${1:0:1} == "h" ]]; then
		CHAMBER="house"
	elif [[ ${1:0:1} == "s" ]]; then
		CHAMBER="senate"
	else
		echo "usage: $0 [YYYYMMDD.mp4] [chamber]"
		exit
	fi
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

if [ "$CHAMBER" = "house" ]; then
	FRAMESTEP=150
elif [ "$CHAMBER" = "senate" ]; then
	FRAMESTEP=60
else
	echo "The chamber must be either 'house' or 'senate'."
	exit
fi

# Have mplayer create a folder full of screenshots.
if ! mplayer -vf framestep="$FRAMESTEP" -framedrop -nosound "$SRC" -speed 100 -vo jpeg:outdir="$date"
then
	exit "$?"
fi

echo "Extracting names and bill numbers from each frame"

cd "$date" || exit

# Standardize screenshot dimensions
if ! mogrify -resize 640x480 ./*
then
	echo "Couldn't resize all images"
	exit "$?"
fi

if [ "$CHAMBER" = "house" ]; then
	NAME_CROP="333x60+120+380"
	BILL_CROP="129x27+465+42"
elif [ "$CHAMBER" = "senate" ]; then
	NAME_CROP="345x60+176+340"
	BILL_CROP="172x27+0+40"
elif [ "$CHAMBER" = "house-committee" ]; then
	NAME_AND_BILL_CROP="176x340+398+39"
elif [ "$CHAMBER" = "senate-committee" ]; then
	NAME_CROP="345x60+176+340"
	BILL_CROP="172x27+0+40"
fi

if "$NAME_CROP"; then
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
for F in $(find -1 ./*.jpg |awk -F. '{print $2}')
do
	cp ."$F".jpg ."${F}"-150.jpg
done

# Create thumbnails of all *150.jpg screenshots.
echo Creating thumbnails of screenshots
mogrify -resize 150x112 ./*-150.jpg
