#!/bin/bash

########
# HOUSE NAME
# +----------------------------------+
# |237x367                    725x367|
# |                                  |
# |237x425                    725x425|
# +----------------------------------+
#
# HOUSE BILL
# +----------------------------------+
# |630x49                      850x49|
# |                                  |
# |630x90                      850x90|
# +----------------------------------+
#
# HOUSE BILL TALLY NUMBER
# +----------------------------------+
# |57x35                       381x35|
# |                                  |
# |57x132                     381x132|
# +----------------------------------+
#
# HOUSE BILL TALLY SPONSOR
# +----------------------------------+
# |592x35                     1076x35|
# |                                  |
# |592x132                   1076x132|
# +----------------------------------+
#
# SENATE NAME
# +----------------------------------+
# |91x296                     476x296|
# |                                  |
# |91x323                     476x323|
# +----------------------------------+
#
# SENATE BILL
# +----------------------------------+
# |477x41                      570x41|
# |                                  |
# |477x59                      570x59|
# +----------------------------------+
#
# SENATE BILL TALLY NUMBER & SPONSOR
# +----------------------------------+
# |0x27                        465x27|
# |                                  |
# |0x58                        465x58|
# +----------------------------------+
#
########


# If the filename is missing, explain that it's required.
if [ -z "$1" ]; then
	echo "usage: $0 [YYYYMMDD.mp4] [chamber] [committee]"
	echo "If this is video of a (sub)commitee meeting, simply specify 'true' as the third flag."
	exit 1
fi

# If the chamber is missing, explain that it's required.
if [ -z "$2" ]; then
	echo "usage: $0 [YYYYMMDD.mp4] [chamber] [committee]"
	echo "If this is video of a (sub)commitee meeting, simply specify 'true' as the third flag."
	exit 1
fi

# Reassign command-line variables to named variables.
SRC="$1"
if [ -z "$CHAMBER" ]; then
	CHAMBER="$2"
fi

# See if a flag has been set indicating that this is a committee meeting
if [ -z "$3" ]; then
	COMMITTEE=false
else
	COMMITTEE=true
fi

if [ ! -f "$SRC" ]; then
	echo "Error: $SRC does not exist"
	exit 1
fi

if [ -z "$output_dir" ]; then
	echo "Error: output_dir is not set as an environment variable"
	exit 1
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

# All dimensions are width x height, horizontal offset + vertical offset (WxH+H+V).
if [ "$CHAMBER" = "house" ] && [ "$COMMITTEE" = false ]; then
	RESIZE="854x480"
	NAME_CROP="592x35+58+488"
	BILL_CROP="630x49+41+220"
elif [ "$CHAMBER" = "senate" ] && [ "$COMMITTEE" = false ]; then
	RESIZE="640x360"
	NAME_CROP="91x296+27+385"
	BILL_CROP="477x41+18+93"
elif [ "$CHAMBER" = "house" ] && [ "$COMMITTEE" = true ]; then
	NAME_CROP="471x54+15+293"
	BILL_CROP="292x19+15+268"
	NAME_CROP_LOWER="623x18+15+342"
	BILL_CROP_LOWER="623x21+15+314"
elif [ "$CHAMBER" = "senate" ] && [ "$COMMITTEE" = true ]; then
	NAME_CROP="345x60+176+340"
	BILL_CROP="172x27+0+40"
fi

# Standardize screenshot dimensions
# Note that this doesn't mean that the screenshots will be e.g. 640x480. It means that the widest
# that they'll be is 640, and the tallest is 480. If the ratio isn't 4:3, by instead 16:9, then the
# screenshots will be 640x360.
if ! mogrify -resize "$RESIZE" ./*
then
	exit "$?"
fi

# If we have name crop dimensions, do that.
if [[ -v NAME_CROP ]]; then
	for f in *[0-9].jpg; do convert "$f" -crop "$NAME_CROP" +repage -negate -fx '.8*r+.8*g+0*b' -compress none -depth 8 "$f".name.jpg; done
fi

# If we have bill crop dimensions, do that.
if [[ -v BILL_CROP ]]; then
	for f in *[0-9].jpg; do convert "$f" -crop "$BILL_CROP" +repage -negate -fx '.8*r+.8*g+0*b' -compress none -depth 8 "$f".bill.jpg; done
fi

# If we have lower name crop dimensions, do that.
if [[ -v NAME_CROP_LOWER ]]; then
	for f in *[0-9].jpg; do convert "$f" -crop "$NAME_CROP_LOWER" +repage -negate -fx '.8*r+.8*g+0*b' -compress none -depth 8 "$f".name-lower.jpg; done
fi

# If we have lower bill crop dimensions, do that.
if [[ -v BILL_CROP_LOWER ]]; then
	for f in *[0-9].jpg; do convert "$f" -crop "$BILL_CROP_LOWER" +repage -negate -fx '.8*r+.8*g+0*b' -compress none -depth 8 "$f".bill-lower.jpg; done
fi

# Do the OCRing
echo "OCRing names and bill numbers"

find . -type f -name '*.name.jpg' -exec tesseract {} {} \;
find . -type f -name '*.bill.jpg' -exec tesseract {} {} \;
find . -type f -name '*.name-lower.jpg' -exec tesseract {} {} \;
find . -type f -name '*.bill-lower.jpg' -exec tesseract {} {} \;

# Delete all of the images that we just OCRed.
find . -type f -name '*.name.jpg' -exec rm {} \;
find . -type f -name '*.bill.jpg' -exec rm {} \;
find . -type f -name '*.name-lower.jpg' -exec rm {} \;
find . -type f -name '*.bill-lower.jpg' -exec rm {} \;

# Duplicate all JPEGs with a -150 suffix.
for F in $(find ./*.jpg -maxdepth 1 |awk -F. '{print $2}')
do
	cp ."$F".jpg ."${F}"-150.jpg
done

# Create thumbnails of all *150.jpg screenshots.
echo Creating thumbnails of screenshots
mogrify -resize 150x112 ./*-150.jpg
