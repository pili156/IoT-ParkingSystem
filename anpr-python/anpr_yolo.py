#!/usr/bin/env python3

import os
import cv2
import io
import json
import argparse
import numpy as np
import requests
from pathlib import Path
from PIL import Image, ImageEnhance
import pytesseract
import re

# YOLOv8
from ultralytics import YOLO

# Optional OCR
try:
    import easyocr
    EASY_AVAILABLE = True
except:
    EASY_AVAILABLE = False

try:
    from google.cloud import vision
    GOOGLE_AVAILABLE = True
except:
    GOOGLE_AVAILABLE = False


# ============================================================
# NORMALIZER PLAT NOMOR INDONESIA
# ============================================================
def normalize_plate(text):
    if not text:
        return None

    text = text.upper()
    text = re.sub(r"[^A-Z0-9 ]", " ", text)
    text = re.sub(r"\s+", " ", text).strip()

    # Common OCR corrections
    swap = {
        "0": "O",
        "1": "I",
        "2": "Z",
        "4": "A",
        "5": "S",
        "6": "G",
        "8": "B"
    }

    cleaned = ""
    for c in text:
        cleaned += swap.get(c, c)

    text = cleaned

    # Pattern Indonesia
    pattern = r"^([A-Z]{1,2})\s?(\d{1,4})\s?([A-Z]{1,3})$"
    m = re.search(pattern, text)
    if m:
        wil, num, kode = m.groups()
        num = str(int(num))  # remove leading zero
        return f"{wil} {num} {kode}"

    return text.strip()


# ============================================================
# OCR Functions
# ============================================================
def ocr_google(pil_img):
    if not GOOGLE_AVAILABLE:
        return None
    try:
        buf = io.BytesIO()
        pil_img.save(buf, format="JPEG")
        data = buf.getvalue()

        client = vision.ImageAnnotatorClient()
        image = vision.Image(content=data)
        response = client.text_detection(image=image)

        if response.error.message:
            return None

        texts = response.text_annotations
        if not texts:
            return None

        return texts[0].description.strip()

    except:
        return None


def ocr_tesseract(pil_img):
    try:
        config = r"--psm 7 -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
        txt = pytesseract.image_to_string(pil_img, config=config)
        return txt.strip()
    except:
        return None


def ocr_easy(roi_bgr):
    if not EASY_AVAILABLE:
        return None
    try:
        reader = easyocr.Reader(['en'], gpu=False)
        results = reader.readtext(roi_bgr, detail=0)
        if results:
            return " ".join(results)
        return None
    except:
        return None


def smart_ocr(roi_bgr):
    pil = Image.fromarray(cv2.cvtColor(roi_bgr, cv2.COLOR_BGR2RGB))

    W, H = pil.size
    if max(W, H) < 600:
        scale = 600 / max(W, H)
        pil = pil.resize((int(W * scale), int(H * scale)), Image.BICUBIC)

    pil = ImageEnhance.Contrast(pil).enhance(1.6)
    pil = ImageEnhance.Sharpness(pil).enhance(1.3)

    text = ocr_google(pil)
    if text:
        return text

    text = ocr_tesseract(pil)
    if text:
        return text

    text = ocr_easy(roi_bgr)
    if text:
        return text

    return None


# ============================================================
# YOLOv8 DETECTOR + OCR PIPELINE
# ============================================================
def detect_plate_yolo(model, img):
    results = model(img)[0]

    plates = []
    for box in results.boxes:
        x1, y1, x2, y2 = map(int, box.xyxy[0])
        cls = int(box.cls)
        conf = float(box.conf)

        if conf < 0.45:
            continue

        roi = img[y1:y2, x1:x2]

        plates.append({
            "bbox": (x1, y1, x2, y2),
            "conf": conf,
            "roi": roi
        })

    return plates


# ============================================================
# MAIN RUNNER
# ============================================================
def main(argv=None):
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", "-i", default="./images")
    parser.add_argument("--out", "-o", default="./out")
    parser.add_argument("--model", "-m", default="./yolo_model/lp_detector.pt")
    parser.add_argument("--send", action="store_true")
    parser.add_argument("--slot", type=int, default=None)
    args = parser.parse_args(argv)

    inp = Path(args.input)
    out = Path(args.out)
    out.mkdir(exist_ok=True)

    print("Loading YOLO model...")
    model = YOLO(args.model)

    files = sorted([f for f in inp.glob("*") if f.suffix.lower() in [".jpg", ".png", ".jpeg"]])

    print(f"Found {len(files)} images.")

    results = []

    for idx, img_path in enumerate(files, 1):
        img = cv2.imread(str(img_path))
        debug_img = img.copy()

        plates = detect_plate_yolo(model, img)

        best_text = None
        used_method = None

        for p in plates:
            (x1, y1, x2, y2) = p["bbox"]

            roi = p["roi"]
            raw = smart_ocr(roi)
            normalized = normalize_plate(raw)

            if normalized:
                best_text = normalized
                used_method = "YOLO + OCR"
                cv2.rectangle(debug_img, (x1, y1), (x2, y2), (0,255,0), 2)
                break

        # Save debug image
        dbg = out / f"{img_path.stem}_debug.jpg"
        cv2.putText(debug_img, f"{best_text}", (10,40),
                    cv2.FONT_HERSHEY_SIMPLEX, 1, (255,0,0), 2)
        cv2.imwrite(str(dbg), debug_img)

        print(f"[{idx}] {img_path.name} -> {best_text}")

        results.append({
            "image": str(img_path),
            "plate": best_text,
            "method": used_method
        })

    with open(out / "results.json", "w") as f:
        json.dump(results, f, indent=2)

    print("Done.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
