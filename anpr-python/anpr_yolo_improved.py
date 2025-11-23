#!/usr/bin/env python3
"""
anpr_yolo_improved.py

Improved ANPR pipeline using YOLOv8 license plate detector with:
 - preprocessing before YOLO (CLAHE, denoise, sharpen)
 - optional ensemble (list of models)
 - YOLOv8 detection for plates
 - ROI upscaling (super-resolution-like) before OCR
 - OCR fallback chain: Google Vision -> Tesseract -> EasyOCR
 - strong Indonesia plate normalizer/corrector
 - optional send to Laravel API /entry
 - debug images with bbox + text and results.json

Usage:
  python anpr_yolo_improved.py --input images --out out --model /mnt/data/lp_detector.pt --send --slot 1

Requirements:
  pip install ultralytics opencv-python pillow numpy requests easyocr pytesseract
  (optional) pip install google-cloud-vision and set GOOGLE_APPLICATION_CREDENTIALS
"""

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
import time

# YOLOv8
from torch import device
from ultralytics import YOLO

# Optional OCR libs
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

# -------------------------
# Configuration defaults
# -------------------------
DEFAULT_MODEL_PATH = "/mnt/data/lp_detector.pt"  # your uploaded model
YOLO_CONF_THRESHOLD = 0.45
OCR_SCALE_MIN = 600  # upscale smallest dimension to this before OCR
TESSERACT_CONFIG = r"--oem 3 --psm 7 -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"

# -------------------------
# Utilities: preprocessing
# -------------------------
def improve_before_yolo(img):
    """Apply color balance, CLAHE, denoise and sharpen BEFORE running YOLO."""
    # convert to LAB and apply CLAHE on L channel
    lab = cv2.cvtColor(img, cv2.COLOR_BGR2LAB)
    l, a, b = cv2.split(lab)
    clahe = cv2.createCLAHE(clipLimit=3.0, tileGridSize=(8,8))
    l = clahe.apply(l)
    lab = cv2.merge((l, a, b))
    img2 = cv2.cvtColor(lab, cv2.COLOR_LAB2BGR)

    # denoise colored image
    img2 = cv2.fastNlMeansDenoisingColored(img2, None, h=6, hColor=6, templateWindowSize=7, searchWindowSize=21)

    # mild sharpening
    kernel = np.array([[0,-1,0], [-1,5,-1], [0,-1,0]])
    img2 = cv2.filter2D(img2, -1, kernel)

    return img2

def upscale_for_ocr(roi_bgr, min_size=OCR_SCALE_MIN):
    """Upscale ROI so the largest dimension >= min_size using bicubic."""
    h, w = roi_bgr.shape[:2]
    max_dim = max(w, h)
    if max_dim >= min_size:
        return roi_bgr
    scale = min_size / float(max_dim)
    new_w = int(w * scale)
    new_h = int(h * scale)
    return cv2.resize(roi_bgr, (new_w, new_h), interpolation=cv2.INTER_CUBIC)

def pil_enhance_from_bgr(roi_bgr):
    """Return PIL Image with enhanced contrast/sharpness."""
    pil = Image.fromarray(cv2.cvtColor(roi_bgr, cv2.COLOR_BGR2RGB))
    pil = ImageEnhance.Contrast(pil).enhance(1.6)
    pil = ImageEnhance.Sharpness(pil).enhance(1.3)
    return pil

# -------------------------
# OCR backends
# -------------------------
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
    except Exception:
        return None

def ocr_tesseract(pil_img):
    try:
        txt = pytesseract.image_to_string(pil_img, config=TESSERACT_CONFIG)
        return txt.strip() or None
    except Exception:
        return None

def ocr_easy(roi_bgr):
    if not EASY_AVAILABLE:
        return None
    try:
        reader = easyocr.Reader(["en"], gpu=False)
        res = reader.readtext(roi_bgr, detail=0)
        if res:
            return " ".join(res).strip()
        return None
    except Exception:
        return None

def smart_ocr(roi_bgr):
    """Run multi-backend OCR on ROI (upscale + enhance). Returns first non-empty text."""
    roi_up = upscale_for_ocr(roi_bgr, min_size=OCR_SCALE_MIN)
    pil = pil_enhance_from_bgr(roi_up)

    # Try Google Vision first (if available)
    if GOOGLE_AVAILABLE:
        t = ocr_google(pil)
        if t:
            return t

    # Tesseract next
    t = ocr_tesseract(pil)
    if t:
        return t

    # EasyOCR fallback
    if EASY_AVAILABLE:
        t = ocr_easy(roi_up)
        if t:
            return t

    return None

# -------------------------
# Plate normalization (Indonesia)
# -------------------------
def normalize_plate(text):
    """Normalize and correct OCR output to Indonesian plate format if possible."""
    if not text:
        return None
    s = text.upper()
    s = re.sub(r"[^A-Z0-9 ]", " ", s)
    s = re.sub(r"\s+", " ", s).strip()

    # common OCR confusions -> corrections (applied broadly)
    corrections = {"0":"O","1":"I","2":"Z","4":"A","5":"S","6":"G","8":"B"}
    fixed = "".join(corrections.get(ch, ch) for ch in s)

    s = fixed

    # attempt to find pattern: 1-2 letters, 1-4 digits, 1-3 letters
    m = re.search(r"([A-Z]{1,2})\s?(\d{1,4})\s?([A-Z]{1,3})", s)
    if m:
        wil, num, code = m.groups()
        try:
            num = str(int(num))  # remove leading zeros
        except:
            num = num.lstrip("0") or "0"
        return f"{wil} {num} {code}"
    # if no match, return cleaned short string (or None)
    return s if len(s) >= 3 else None

# -------------------------
# YOLO detection helper
# -------------------------
def detect_plate_yolo(model, img, conf_thresh=YOLO_CONF_THRESHOLD):
    """Run YOLO model (ultralytics) and return list of plate dicts with bbox and roi."""
    res = model(img)[0]  # run inference
    plates = []
    # results.boxes may be empty if no detection
    if not hasattr(res, "boxes"):
        return plates
    for box in res.boxes:
        try:
            xyxy = box.xyxy[0].cpu().numpy() if hasattr(box.xyxy[0], "cpu") else box.xyxy[0]
            x1, y1, x2, y2 = map(int, xyxy)
            conf = float(box.conf[0]) if hasattr(box.conf, "__len__") else float(box.conf)
        except Exception:
            # fallback simpler extraction
            bbox = box.xyxy[0]
            x1, y1, x2, y2 = map(int, bbox)
            conf = float(box.conf)
        if conf < conf_thresh:
            continue
        # clip coordinates
        h, w = img.shape[:2]
        x1, y1 = max(0, x1), max(0, y1)
        x2, y2 = min(w-1, x2), min(h-1, y2)
        if x2 <= x1 or y2 <= y1:
            continue
        roi = img[y1:y2, x1:x2].copy()
        plates.append({"bbox": (x1, y1, x2, y2), "conf": conf, "roi": roi})
    return plates

# -------------------------
# OPTIONAL: simple ensemble (multiple models)
# -------------------------
def detect_with_ensemble(models, img, conf_thresh=YOLO_CONF_THRESHOLD):
    """Run several YOLO models and merge detections (simple NMS-like by IOU)."""
    all_boxes = []
    for model in models:
        plates = detect_plate_yolo(model, img, conf_thresh)
        for p in plates:
            all_boxes.append(p)
    # if no boxes, return []
    if not all_boxes:
        return []
    # simple merge: keep highest-conf boxes, suppress if overlap > IOU_THRESH
    IOU_THRESH = 0.3
    all_boxes = sorted(all_boxes, key=lambda x: x["conf"], reverse=True)
    merged = []
    def iou(a, b):
        x1 = max(a[0], b[0]); y1 = max(a[1], b[1])
        x2 = min(a[2], b[2]); y2 = min(a[3], b[3])
        inter = max(0, x2-x1) * max(0, y2-y1)
        areaA = (a[2]-a[0])*(a[3]-a[1])
        areaB = (b[2]-b[0])*(b[3]-b[1])
        union = areaA + areaB - inter
        return inter/union if union>0 else 0
    for p in all_boxes:
        keep = True
        for q in merged:
            if iou(p["bbox"], q["bbox"]) > IOU_THRESH:
                keep = False
                break
        if keep:
            merged.append(p)
    return merged

# -------------------------
# API sender
# -------------------------
def send_entry_to_api(api_base, token, plate, slot_id=None, image_path=None):
    url = api_base.rstrip("/") + "/entry"
    headers = {"Authorization": token, "Content-Type": "application/json"} if token else {"Content-Type": "application/json"}
    payload = {"plate": plate}
    if slot_id is not None:
        payload["slot_id"] = int(slot_id)
    if image_path:
        payload["image_path"] = image_path
    try:
        r = requests.post(url, json=payload, headers=headers, timeout=8)
        return r.status_code, r.text
    except Exception as e:
        return None, str(e)

# -------------------------
# Main runner
# -------------------------
def main(argv=None):
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", "-i", default="./images", help="input folder")
    parser.add_argument("--out", "-o", default="./out", help="output folder")
    parser.add_argument("--model", "-m", default=DEFAULT_MODEL_PATH, help="path to yolo model (.pt)")
    parser.add_argument("--model2", default=None, help="optional second model path to ensemble")
    parser.add_argument("--send", action="store_true", help="send detected plate to Laravel API")
    parser.add_argument("--slot", type=int, default=None, help="slot_id when sending to API")
    parser.add_argument("--api", default=os.environ.get("LARAVEL_API", "http://127.0.0.1:8000/api"), help="Laravel API base URL")
    parser.add_argument("--token", default=os.environ.get("API_TOKEN"), help="Bearer token for API (include 'Bearer ...')")
    parser.add_argument("--conf", type=float, default=YOLO_CONF_THRESHOLD, help="YOLO confidence threshold")
    parser.add_argument("--gpu", action="store_true", help="use GPU if available")
    args = parser.parse_args(argv)

    inp = Path(args.input)
    out = Path(args.out); out.mkdir(parents=True, exist_ok=True)

    # load models
    print("Loading YOLO model(s)...")
    device = 0 if args.gpu else "cpu"
    model_primary = YOLO(args.model)
    model_primary.to(device)
    models = [model_primary]
    if args.model2:
        model2 = YOLO(args.model2, device=device)
        models.append(model2)

    image_files = sorted([p for p in inp.glob("*") if p.suffix.lower() in [".jpg",".jpeg",".png"]])
    print(f"Found {len(image_files)} images in {inp}.")

    results = []
    for idx, img_path in enumerate(image_files, start=1):
        img = cv2.imread(str(img_path))
        if img is None:
            print(f"Skipping unreadable {img_path}")
            continue

        # Preprocess before YOLO for better detection
        img_proc = improve_before_yolo(img)

        # Detect (with ensemble if more than 1 model)
        if len(models) > 1:
            plates = detect_with_ensemble(models, img_proc, conf_thresh=args.conf)
        else:
            plates = detect_plate_yolo(model_primary, img_proc, conf_thresh=args.conf)

        debug_img = img.copy()
        best_plate = None
        best_conf = 0.0
        tried_texts = []

        # If none found, try running with original image as fallback
        if not plates:
            # small fallback: try detection directly on original image (sometimes better)
            plates = detect_plate_yolo(model_primary, img, conf_thresh=args.conf*0.6)

        # Loop detected plate ROIs (sorted by conf desc)
        plates = sorted(plates, key=lambda x: x.get("conf", 0), reverse=True)
        for p in plates:
            x1,y1,x2,y2 = p["bbox"]
            roi = p["roi"]
            # perform OCR on ROI (upscale+enhance)
            raw = smart_ocr(roi)
            tried_texts.append(raw)
            normalized = normalize_plate(raw)
            if normalized:
                best_plate = normalized
                best_conf = p.get("conf", 0)
                used_roi = roi
                # draw bbox and result
                cv2.rectangle(debug_img, (x1,y1), (x2,y2), (0,255,0), 2)
                cv2.putText(debug_img, f"{normalized}", (x1, max(10,y1-8)), cv2.FONT_HERSHEY_SIMPLEX, 0.9, (0,255,0), 2)
                break

        # If still no plate, try global OCR as last resort
        if not best_plate:
            pil_full = pil_enhance_from_bgr(img)
            raw_full = None
            if GOOGLE_AVAILABLE:
                raw_full = ocr_google(pil_full)
            if not raw_full:
                raw_full = ocr_tesseract(pil_full)
            if not raw_full and EASY_AVAILABLE:
                raw_full = ocr_easy(img)
            norm_full = normalize_plate(raw_full)
            tried_texts.append(raw_full)
            if norm_full:
                best_plate = norm_full
                cv2.putText(debug_img, f"{best_plate}", (10,40), cv2.FONT_HERSHEY_SIMPLEX, 1.0, (0,0,255), 2)

        # Save debug image & write results
        out_debug = out / f"{img_path.stem}_debug.jpg"
        cv2.putText(debug_img, f"File: {img_path.name}", (10, img.shape[0]-10), cv2.FONT_HERSHEY_SIMPLEX, 0.6, (200,200,200), 1)
        cv2.imwrite(str(out_debug), debug_img)

        print(f"[{idx}/{len(image_files)}] {img_path.name} -> plate: {best_plate}  tried: {tried_texts}")

        api_resp = None
        if args.send and best_plate:
            status, text = send_entry_to_api(args.api, args.token, best_plate, slot_id=args.slot, image_path=str(img_path))
            api_resp = {"status": status, "text": text}
            print("  Sent to API:", api_resp)

        results.append({
            "image": str(img_path),
            "plate": best_plate,
            "conf": best_conf,
            "tried": tried_texts,
            "api": api_resp
        })

    # save results
    with open(out / "results.json", "w", encoding="utf-8") as f:
        json.dump(results, f, indent=2, ensure_ascii=False)

    print("Done. Results saved to", str(out / "results.json"))
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
