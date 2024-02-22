import cv2
import pytesseract
from PIL import Image
import json

def crop_and_ocr(image_path, top_coords, bottom_coords):
    # Read the image using OpenCV
    image = cv2.imread(image_path)
    
    # Convert to grayscale
    gray_image = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    
    # Crop function
    def crop_image(image, coords):
        x, y, w, h = coords
        return image[y:y+h, x:x+w]
    
    # OCR function
    def ocr_cropped_image(cropped_image):
        pil_image = Image.fromarray(cropped_image)
        return pytesseract.image_to_string(pil_image, lang='eng', config='--psm 6').strip()
    
    # Crop images based on the coordinates
    top_cropped = crop_image(gray_image, top_coords)
    bottom_cropped = crop_image(gray_image, bottom_coords)
    
    # Perform OCR
    top_text = ocr_cropped_image(top_cropped)
    bottom_text = ocr_cropped_image(bottom_cropped)
    
    # Combine the text into a JSON object
    ocr_results = {
        "top_box_text": top_text,
        "bottom_box_text": bottom_text
    }
    
    return json.dumps(ocr_results, indent=4)

# The coordinates for the boxes would be passed along with the image path when calling the function
image_path = 'screenshot.jpg'  # Replace with your image file path
top_box_coords = (715, 54, 962 - 715, 91 - 54)
bottom_box_coords = (275, 415, 820 - 275, 475 - 415)

# Call the function and print the results
results_json = crop_and_ocr(image_path, top_box_coords, bottom_box_coords)
print(results_json)
