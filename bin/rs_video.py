import os
import glob
import random
import cv2
import pytesseract
from PIL import Image
from collections import Counter
import numpy as np

def extract_frames(video_path, output_folder):
    # Open the video file
    cap = cv2.VideoCapture(video_path)
    
    if not cap.isOpened():
        print('Error: Could not open video.')
        return

    fps = int(cap.get(cv2.CAP_PROP_FPS))  # Get frames per second of the video
    frame_count = 0

    while cap.isOpened():
        ret, frame = cap.read()
        
        # If frame is read correctly ret is True
        if not ret:
            break  # Exit the loop if there are no frames left to read

        # Save a frame every 'fps' frames (i.e., once every second)
        if frame_count % fps == 0:
            frame_time = int(frame_count / fps)
            output_path = f"{output_folder}/{frame_time}.jpg"
            cv2.imwrite(output_path, frame)
            print(f"Saved {output_path}")

        frame_count += 1

    cap.release()

def ocr(image_path, chyrons):
    # Read the image using OpenCV
    image = cv2.imread(image_path)
    
    # Convert to grayscale
    gray_image = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    
    ocr_results = []

    for chyron in chyrons:

        # Crop function
        def crop_image(image, chyron):
            x, y, w, h = chyron
            return image[y:y+h, x:x+w]
        
        # OCR function
        def ocr_cropped_image(cropped_image):
            pil_image = Image.fromarray(cropped_image)
            return pytesseract.image_to_string(pil_image, lang='eng', config='--psm 6').strip()
        
        # The bottom chyron needs to be pulled in a bit, to avoid including the state seal
        if chyron[1] > 100:
            chyron_list = list(chyron)
            chyron_list[0] = chyron_list[0] + 12
            chyron_list[2] = chyron_list[2] - 12
            chyron = tuple(chyron_list)

        # Crop images based on the coordinates
        cropped = crop_image(gray_image, chyron)
        
        # Perform OCR
        text = ocr_cropped_image(cropped)
   
        # Combine the outputs
        chyron_results = {'coordinates': chyron, 'text': text}
        
        ocr_results.append(chyron_results)
    
    return ocr_results

def sample_chyrons(directory_path):
    # Select a bunch of random screenshots
    screenshots = glob.glob(os.path.join(directory_path, '*.jpg'))
    random_screenshots = random.sample(screenshots, 200)

    boundaries = []

    for random_screenshot in random_screenshots:
        image_path = directory_path + random_screenshot
        output_path = image_path + '-outlined.jpg'
        chyrons = find_chyrons(image_path, output_path)
        for chyron in chyrons:
            boundaries.append(chyron)

    # Define a function to compare two boundaries
    def are_similar(boundary1, boundary2, threshold=10):
        return all(abs(a - b) <= threshold for a, b in zip(boundary1, boundary2))

    # Find nearly-identical boundaries
    similar_groups = []
    visited = set()

    for i, boundary in enumerate(boundaries):
        if i in visited:
            continue
        # Create a new group for each unvisited boundary
        current_group = [boundary]
        for j, other_boundary in enumerate(boundaries[i+1:], start=i+1):
            if are_similar(boundary, other_boundary):
                current_group.append(other_boundary)
                visited.add(j)
        similar_groups.append(current_group)

    # Save the two most-found similar boundaries
    sorted_similar_groups = sorted(similar_groups, key=lambda group: len(group), reverse=True)
    sorted_similar_groups = sorted_similar_groups[:2]

    def mean_boundary(group):
        # Transpose the list of tuples to easily calculate mean for each boundary dimension
        transposed = list(zip(*group))
        # Calculate mean for each dimension
        mean = tuple(round(sum(dim) / len(dim)) for dim in transposed)
        return mean

    # Calculate the mean boundary for each group
    mean_boundaries = [mean_boundary(group) for group in sorted_similar_groups]

    return mean_boundaries
    
def find_chyrons(image_path, output_path):
    # Load the image
    image = cv2.imread(image_path)

    # Convert the image to HSV color space
    hsv = cv2.cvtColor(image, cv2.COLOR_BGR2HSV)

    # Define the range of blue color in HSV
    lower_blue = np.array([100, 150, 50])
    upper_blue = np.array([140, 255, 255])

    # Threshold the HSV image to get only blue colors
    mask = cv2.inRange(hsv, lower_blue, upper_blue)

    # Find contours in the mask
    contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

    # Filter contours to find large blue rectangles
    chyrons = []
    for contour in contours:
        x, y, w, h = cv2.boundingRect(contour)
        # Filtering criteria for large rectangles
        if w >= 200 and h >= 18:
            chyrons.append((x, y, w, h))
            # Draw a green rectangle around the detected object
            cv2.rectangle(image, (x, y), (x + w, y + h), (0, 255, 0), 2)

    # Save the result
    cv2.imwrite(output_path, image)

    return chyrons

def get_average_color(image_path, bounding_box):
    # Load the image
    image = cv2.imread(image_path)
    
    # Extract the bounding box coordinates
    x, y, w, h = bounding_box
    
    # Crop the region of interest based on the bounding box
    roi = image[y:y+h, x:x+w]
    
    # Calculate the average color of the region of interest
    average_color_per_channel_bgr = np.mean(roi, axis=(0, 1))
    
    # Convert BGR to RGB
    average_color_per_channel = average_color_per_channel_bgr[::-1]
    
    # Convert the averages to integers
    average_color = tuple(map(int, average_color_per_channel))
    
    return average_color
