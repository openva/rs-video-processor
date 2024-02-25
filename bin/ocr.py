from rs_video import *
import sqlite3
import glob
import re

directory_path = './'
output_path = 'screenshot_chyrons.jpg'
db_file = 'chyrons.db'
video_filename = "video.mp4"

# Save one screenshot for each second of video
extract_frames(directory_path + video_filename, directory_path)

# Create a chyron database
conn = sqlite3.connect(db_file)
cursor = conn.cursor()
cursor.execute('''CREATE TABLE IF NOT EXISTS chyrons (
                    "id" INTEGER PRIMARY KEY,
                    "video_id" INTEGER,
                    "timestamp" INTEGER,
                    "type" TEXT CHECK( type IN ('name', 'bill') ),
                    "text" TEXT
                )''')

# Create a database to store video records
cursor.execute('''CREATE TABLE IF NOT EXISTS videos (
                    "id" INTEGER PRIMARY KEY,
                    "date" DATE,
                    "chamber" TEXT CHECK( chamber IN ('house', 'senate') ),
                    "committee" TEXT
                )''')

for image_path in glob.glob(f'{directory_path}/*.jpg'):

    print(f"Processing {image_path}")

    # Determine where the blue chyrons are
    bounding_boxes = find_chyrons(image_path, output_path)
    print(f"Detected {len(bounding_boxes)} chyrons: {bounding_boxes}")

    if len(bounding_boxes) == 0:
        continue

    # Iterate through the bounding boxes
    chyrons = []
    for bounding_box in bounding_boxes:

        # Call the function and get the average color in RGB format
        average_color_rgb = get_average_color(image_path, bounding_box)
        if get_average_color(image_path, bounding_box)[2] > 100:
            chyrons.append(bounding_box)
            print(f"Chyron {bounding_box} validated with an average color of {average_color_rgb}")
        else:
            continue

    # OCR chyrons
    chyron_text = ocr(image_path, chyrons)

    # Get the chryons' timestamp in seconds (it's the filename's number)
    match = re.search(r'\d+', image_path)
    if not match:
            continue
    timestamp = match.group(0)

    # Determine if each chyron is a top or a bottom chyron
    for chyron in chyron_text:
        if chyron['coordinates'][1] < 100:
            chyron['type'] = 'bill'
        else:
            chyron['type'] = 'name'

        sql_values = {}
        sql_values['text'] = chyron['text']
        sql_values['type'] = chyron['type']
        sql_values['timestamp'] = timestamp

        insert_query = '''INSERT INTO chyrons (text, type, timestamp)
                            VALUES (:text, :type, :timestamp)'''
        cursor.execute(insert_query, sql_values)

conn.commit()
conn.close()
