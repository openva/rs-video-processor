#!/usr/bin/python3

"""For OCRing video."""

import sys
import sqlite3
import glob
import re
from rs_video import *

DB_FILE = 'chyrons.db'

if len(sys.argv) > 3:
    VIDEO_ID = sys.argv[1]
    VIDEO_DIR = sys.argv[2]
    VIDEO_FILE = sys.argv[3]
else:
    print('Error: Must provide video ID, output directory, and video file, in that order')
    sys.exit(1)

# Save one screenshot for each second of video
extract_frames(VIDEO_DIR + '/' + VIDEO_FILE, VIDEO_DIR)

# Determine the boundaries to use
bounding_boxes = sample_chyrons(VIDEO_DIR)

# Create a chyron database
conn = sqlite3.connect(DB_FILE)
cursor = conn.cursor()
cursor.execute('''CREATE TABLE IF NOT EXISTS chyrons (
                    "id" INTEGER PRIMARY KEY,
                    "video_id" INTEGER,
                    "timestamp" INTEGER,
                    "type" TEXT CHECK( type IN ('legislator', 'bill') ),
                    "text" TEXT
                )''')

# Create a database to store video records
cursor.execute('''CREATE TABLE IF NOT EXISTS videos (
                    "id" INTEGER PRIMARY KEY,
                    "date" DATE,
                    "chamber" TEXT CHECK( chamber IN ('house', 'senate') ),
                    "committee" TEXT
                )''')

for image_path in glob.glob(f'{VIDEO_DIR}/*.jpg'):

    print(f"Processing {image_path}")

    matched_bounding_boxes = []

    for bounding_box in bounding_boxes:
        average_color = get_average_color(image_path, bounding_box)
        if get_average_color(image_path, bounding_box)[2] > 100:
            matched_bounding_boxes.append(bounding_box)

    # OCR chyrons
    chyron_text = ocr(image_path, matched_bounding_boxes)

    # Get the chryons' timestamp in seconds (it's the filename's number)
    match = re.search(r'\d+', image_path)
    if not match:
        continue
    timestamp = match.group(0)

    # Determine if each chyron is a bill or a name chyron
    for chyron in chyron_text:
        if chyron['coordinates'][1] < 100:
            chyron['type'] = 'bill'
        else:
            chyron['type'] = 'legislator'

        sql_values = {}
        sql_values['text'] = chyron['text']
        sql_values['type'] = chyron['type']
        sql_values['timestamp'] = timestamp
        sql_values['video_id'] = VIDEO_ID

        insert_query = '''REPLACE INTO chyrons (video_id, text, type, timestamp)
                            VALUES (:video_id, :text, :type, :timestamp)'''
        cursor.execute(insert_query, sql_values)

conn.commit()
conn.close()
