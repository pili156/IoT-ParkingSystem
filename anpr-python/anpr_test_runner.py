#!/usr/bin/env python3

import os
import sys
import cv2
import io
import time
import json
import argparse
import requests
import numpy as np
from pathlib import Path
from PIL import Image, ImageEnhance
import pytesseract
import re

# Optional dependencies
try:
    from google.cloud import vision
    GOOGLE_AVAILABLE = True
except Exception:
    GOOGLE_AVAILABLE = False

try:
    import easyocr
    EASY_OCR_AVAILABLE = True
except Exception:
    EASY_OCR_AVAILABLE = False


# ============================================================
#  NORMALIZER PLAT NOMOR INDONESIA (FINAL & TERINTEGRASI)
# ============================================================
def normalize_plate_indonesia(raw):
    if not raw:
        return None

    # Uppercase
    text = raw.upper()

    # Hilangkan karakter noise
    text = re.sub(r"[^A-Z0-9 ]", " ", text)
    text = re.sub(r"\s+", " ", text).strip()

    # Koreksi OCR umum (angka → huruf)
    corrections = {
        '0': 'O',
        '1': 'I',
        '8': 'B',
        '5': 'S',
        '4': 'A',
        '2': 'Z'
    }

    fixed = ""
    for c in text:
        if c in corrections:
            fixed += corrections[c]
        else:
            fixed += c

    text = fixed

    # Pola plat Indonesia
    pattern = r"([A-Z]{1,2})\s?(\d{1,4})\s?([A-Z]{1,3})"
    match = re.search(pattern, text)

    if match:
        wilayah, angka, kode = match.groups()
        # Hapus leading zero di blok angka
        angka = str(int(angka))
        return f"{wilayah} {angka} {kode}"

    return text



# ============================================================
#  PREPROCESSING
# ============================================================
def load_image(path):
    img = cv2.imread(str(path))
    if img is None:
        raise FileNotFoundError(f"Cannot load image: {path}")
    return img

def preprocess_for_detection(img):
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    clahe = cv2.createCLAHE(clipLimit=3.0, tileGridSize=(8,8))
    gray = clahe.apply(gray)
    gray = cv2.bilateralFilter(gray, 9, 75, 75)
    kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (17,3))
    tophat = cv2.morphologyEx(gray, cv2.MORPH_TOPHAT, kernel)
    edged = cv2.Canny(tophat, 50, 200)
    edged = cv2.dilate(edged, cv2.getStructuringElement(cv2.MORPH_RECT, (3,3)), 1)
    return gray, edged

def find_plate_candidates(edged, min_area=1000):
    contours, _ = cv2.findContours(edged.copy(), cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    candidates = []
    for c in contours:
        x,y,w,h = cv2.boundingRect(c)
        ar = w / float(h) if h > 0 else 0
        area = w*h
        if 1.0 < ar < 12.0 and area > min_area and h > 20:
            candidates.append((x,y,w,h,area,ar))
    candidates = sorted(candidates, key=lambda t: t[4], reverse=True)
    return candidates

def smooth_and_ocr_roi(roi_bgr):
    pil = Image.fromarray(cv2.cvtColor(roi_bgr, cv2.COLOR_BGR2RGB))
    W,H = pil.size
    scale = max(1, 600 / max(W,H))
    if scale > 1:
        pil = pil.resize((int(W*scale), int(H*scale)), Image.BICUBIC)
    pil = ImageEnhance.Contrast(pil).enhance(1.6)
    pil = ImageEnhance.Sharpness(pil).enhance(1.2)
    return pil



# ============================================================
#  OCR BACKENDS
# ============================================================
def ocr_google_vision(image_bytes):
    if not GOOGLE_AVAILABLE:
        return None
    try:
        client = vision.ImageAnnotatorClient()
        image = vision.Image(content=image_bytes)
        res = client.text_detection(image=image)
        if res.error.message:
            return None
        texts = res.text_annotations
        if not texts:
            return None
        return texts[0].description.strip()
    except:
        return None

def ocr_pytesseract(pil_image):
    try:
        config = r"--psm 7 -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
        txt = pytesseract.image_to_string(pil_image, config=config)
        return txt.strip() or None
    except:
        return None

def ocr_easyocr(roi_bgr):
    if not EASY_OCR_AVAILABLE:
        return None
    try:
        reader = easyocr.Reader(['en'], gpu=False)
        res = reader.readtext(roi_bgr, detail=0)
        return (" ".join(res)).strip() if res else None
    except:
        return None


def try_ocr_backends(pil_image, roi_bgr=None):
    buf = io.BytesIO()
    pil_image.save(buf, format="JPEG")
    b = buf.getvalue()

    if b and GOOGLE_AVAILABLE:
        txt = ocr_google_vision(b)
        if txt:
            return txt

    txt = ocr_pytesseract(pil_image)
    if txt:
        return txt

    if EASY_OCR_AVAILABLE and roi_bgr is not None:
        txt = ocr_easyocr(roi_bgr)
        if txt:
            return txt

    return None



# ============================================================
#  DETECT + NORMALIZE (FINAL)
# ============================================================
def detect_plate_text(img, debug_draw=None):
    gray, edged = preprocess_for_detection(img)
    candidates = find_plate_candidates(edged)
    tried = []

    # Fallback: central band
    if not candidates:
        h,w = img.shape[:2]
        x = int(w * 0.08)
        y = int(h * 0.42)
        band = img[y:y+int(h*0.18), x:x+int(w*0.84)]
        pil = smooth_and_ocr_roi(band)
        txt = try_ocr_backends(pil, band)
        normalized = normalize_plate_indonesia(txt)
        return {"text": normalized, "method": "band", "tried": tried, "debug_roi": band}

    # ROI candidates
    for (x,y,w,h,area,ar) in candidates:
        roi = img[y:y+h, x:x+w]
        pil = smooth_and_ocr_roi(roi)
        raw = try_ocr_backends(pil, roi)
        tried.append(raw)
        normalized = normalize_plate_indonesia(raw)

        if normalized and len(normalized) >= 6:
            if debug_draw is not None:
                cv2.rectangle(debug_draw, (x,y),(x+w,y+h),(0,255,0),2)
            return {"text": normalized, "method": "candidate", "rect": (x,y,w,h), "tried": tried}

    # Full Fallback
    pil_full = smooth_and_ocr_roi(img)
    raw = try_ocr_backends(pil_full, img)
    normalized = normalize_plate_indonesia(raw)
    return {"text": normalized, "method": "full", "tried": tried}



# ============================================================
#  SEND TO API + MAIN RUNNER
# ============================================================
def send_entry_to_api(api_base, token, plate, slot_id=None, image_path=None):
    url = api_base.rstrip("/") + "/entry"
    headers = {"Authorization": token, "Content-Type": "application/json"}
    payload = {"plate": plate}
    if slot_id:
        payload["slot_id"] = slot_id
    if image_path:
        payload["image_path"] = image_path

    try:
        r = requests.post(url, json=payload, headers=headers, timeout=8)
        return r.status_code, r.text
    except Exception as e:
        return None, str(e)


def main(argv=None):
    p = argparse.ArgumentParser()
    p.add_argument("--input", "-i", default="./anpr_test_images")
    p.add_argument("--out", "-o", default="./anpr_debug")
    p.add_argument("--send", action="store_true")
    p.add_argument("--slot", type=int, default=None)
    args = p.parse_args(argv)

    inp = Path(args.input)
    out = Path(args.out)
    out.mkdir(exist_ok=True)

    LARAVEL_API = os.environ.get("LARAVEL_API", "http://127.0.0.1:8000/api")
    TOKEN = os.environ.get("API_TOKEN")

    files = sorted(f for f in inp.glob("*") if f.suffix.lower() in [".jpg",".jpeg",".png"])
    print(f"Found {len(files)} images.")

    results = []

    for i, img_path in enumerate(files, 1):
        img = load_image(img_path)
        debug_img = img.copy()

        res = detect_plate_text(img, debug_draw=debug_img)
        plate = res["text"]
        method = res["method"]

        dbgfile = out / f"{img_path.stem}_debug.jpg"
        cv2.putText(debug_img, f"Plate: {plate}", (10,30), cv2.FONT_HERSHEY_SIMPLEX, 0.9, (255,0,0), 2)
        cv2.putText(debug_img, f"Method: {method}", (10,60), cv2.FONT_HERSHEY_SIMPLEX, 0.8, (0,128,255), 2)
        cv2.imwrite(str(dbgfile), debug_img)

        print(f"[{i}] {img_path.name} => {plate}  ({method})")

        results.append({
            "image": str(img_path),
            "plate": plate,
            "method": method,
            "tried": res.get("tried")
        })

    with open(out / "results.json", "w") as f:
        json.dump(results, f, indent=2)

    print("\nSaved all debug images + results.json")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
