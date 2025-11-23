# anpr_service.py
import os, base64, time, requests, io
from ultralytics import YOLO
import cv2
from PIL import Image
import pytesseract

# optional google vision client fallback
try:
    from google.cloud import vision
    GVISION = True
    client = vision.ImageAnnotatorClient()
except Exception as e:
    GVISION = False
    print("Google Vision not available, will use pytesseract.")

# CONFIG
YOLO_MODEL = "/models/yolo11n.pt"   # ganti path model Anda
CAMERA_INDEX = 0   # atau RTSP url
LARAVEL_API = "http://your-laravel-host/api/anpr/result"  # ganti sesuai env
USE_GOOGLE_VISION = GVISION and os.getenv("GOOGLE_APPLICATION_CREDENTIALS") is not None

# load model
model = YOLO(YOLO_MODEL)

def preprocess_plate(pil_img):
    import numpy as np
    img = cv2.cvtColor(np.array(pil_img), cv2.COLOR_RGB2GRAY)
    img = cv2.bilateralFilter(img, 9, 75, 75)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8,8))
    img = clahe.apply(img)
    # threshold
    _, th = cv2.threshold(img,0,255,cv2.THRESH_BINARY+cv2.THRESH_OTSU)
    return Image.fromarray(th)

def ocr_with_google(image_bytes):
    if not GVISION:
        raise RuntimeError("Google Vision not available")
    image = vision.Image(content=image_bytes)
    response = client.text_detection(image=image)
    texts = response.text_annotations
    if texts:
        return texts[0].description.strip()
    return ""

def ocr_with_tesseract(pil_img):
    # preprocessing for tesseract
    gray = pil_img.convert('L')
    bw = gray.point(lambda x: 0 if x<150 else 255, '1')
    text = pytesseract.image_to_string(bw, config='--psm 7 -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-')
    return text.strip()

def detect_plate_and_ocr(frame):
    # frame = BGR OpenCV image
    results = model(frame, imgsz=640)  # returns Results objects
    # iterate detections
    for r in results:
        boxes = r.boxes
        for box in boxes:
            cls = int(box.cls.cpu().numpy()) if hasattr(box, 'cls') else None
            # if your model trained only for plates, we accept all boxes
            x1,y1,x2,y2 = map(int, box.xyxy[0].cpu().numpy())
            crop = frame[y1:y2, x1:x2]
            if crop.size == 0: continue
            pil = Image.fromarray(cv2.cvtColor(crop, cv2.COLOR_BGR2RGB))
            img_bytes = io.BytesIO()
            pil.save(img_bytes, format='JPEG')
            b = img_bytes.getvalue()
            text = ""
            if USE_GOOGLE_VISION:
                try:
                    text = ocr_with_google(b)
                except Exception as e:
                    print("google vision error:", e)
                    text = ocr_with_tesseract(pil)
            else:
                text = ocr_with_tesseract(pil)
            # cleanup text
            text = "".join(ch for ch in text if ch.isalnum() or ch == '-').upper()
            return text, b
    return None, None

def send_result_to_laravel(plate, mode, image_bytes, entry_id=None):
    b64 = base64.b64encode(image_bytes).decode('utf-8')
    payload = {
        'plate': plate,
        'mode': mode,
        'image_base64': b64
    }
    if entry_id:
        payload['entry_id'] = entry_id
    r = requests.post(LARAVEL_API, json=payload, timeout=10)
    return r.status_code, r.text

def main_loop():
    cap = cv2.VideoCapture(CAMERA_INDEX)  # or rtsp://...
    while True:
        ret, frame = cap.read()
        if not ret:
            time.sleep(0.5)
            continue
        plate, img_bytes = detect_plate_and_ocr(frame)
        if plate:
            print("Found plate:", plate)
            # decide mode: you can decide by separate triggers (arrival vs exit)
            # for demo we assume entry
            status, text = send_result_to_laravel(plate, 'entry', img_bytes)
            print("Laravel response:", status, text)
            # wait a bit to avoid repeated detections for same vehicle
            time.sleep(4)
        else:
            time.sleep(0.1)

if __name__ == "__main__":
    main_loop()
