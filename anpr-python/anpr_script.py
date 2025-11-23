# anpr_script.py
# Detect plate-like region (simple heuristics) and run OCR (pytesseract)
import cv2
import numpy as np
from PIL import Image
import pytesseract
import sys

def preprocess_image(img):
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    gray = cv2.bilateralFilter(gray, 9, 75, 75)
    th = cv2.adaptiveThreshold(gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
                               cv2.THRESH_BINARY, 11, 2)
    edged = cv2.Canny(gray, 50, 200)
    return gray, th, edged

def find_plate_candidate(thresh):
    cnts, _ = cv2.findContours(thresh.copy(), cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    cnts = sorted(cnts, key=cv2.contourArea, reverse=True)[:20]
    plate_cnt = None
    for c in cnts:
        x,y,w,h = cv2.boundingRect(c)
        ar = w / float(h) if h>0 else 0
        # heuristik sederhana: rasio dan ukuran minimum
        if 2.5 < ar < 8 and w>60 and h>15:
            plate_cnt = (x,y,w,h)
            break
    return plate_cnt

def ocr_tesseract(roi):
    pil = Image.fromarray(cv2.cvtColor(roi, cv2.COLOR_BGR2RGB))
    config = "--psm 7 -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789- "
    text = pytesseract.image_to_string(pil, config=config)
    return text.strip()

def detect_plate_text_from_path(img_path, debug=False):
    img = cv2.imread(img_path)
    if img is None:
        raise FileNotFoundError(f"Image not found: {img_path}")
    gray, th, edged = preprocess_image(img)
    plate = find_plate_candidate(th)
    if plate is None:
        plate = find_plate_candidate(edged)
    result = {'image': str(img_path), 'plate_text': None, 'error': None}
    try:
        if plate is not None:
            x,y,w,h = plate
            roi = img[y:y+h, x:x+w]
            text = ocr_tesseract(roi)
            result['plate_text'] = text
            if debug:
                cv2.rectangle(img, (x,y), (x+w, y+h), (0,255,0), 2)
                dbg_path = str(img_path).replace('.jpg', '_debug.jpg')
                cv2.imwrite(dbg_path, img)
        else:
            # fallback: OCR pada band horizontal tengah
            h, w = img.shape[:2]
            band = img[int(h*0.4):int(h*0.6), int(w*0.1):int(w*0.9)]
            text = ocr_tesseract(band)
            result['plate_text'] = text
    except Exception as e:
        result['error'] = str(e)
    return result

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print('Usage: python anpr_script.py <image_path> [--debug]')
        sys.exit(1)
    debug = '--debug' in sys.argv
    res = detect_plate_text_from_path(sys.argv[1], debug=debug)
    print(res)
