# anpr_bisa.py
import os
import cv2
import logging
import numpy as np
from ultralytics import YOLO
from paddleocr import PaddleOCR

logger = logging.getLogger(__name__)
logging.basicConfig(level=logging.INFO)

# Config via environment (or default)
YOLO_MODEL_PATH = os.getenv("YOLO_MODEL_PATH", "models/yolo/best.pt")
PADDLE_OCR_DIR = os.getenv("PADDLE_OCR_DIR", "models/ocr")  # folder yang berisi inference.pdmodel/pdiparams/inference.yml
YOLO_CONF_THRESH = float(os.getenv("YOLO_CONF_THRESH", 0.5))
OCR_MIN_CONF = float(os.getenv("OCR_MIN_CONF", 0.35))  # min confidence untuk menerima hasil OCR

def setup_models():
    """
    Load YOLO and PaddleOCR models. Return (yolo_model, ocr_model).
    Uses paths from environment variables or defaults above.
    """
    yolo_model = None
    ocr_model = None

    # Load YOLO
    try:
        if not os.path.exists(YOLO_MODEL_PATH):
            logger.error(f"YOLO model not found at {YOLO_MODEL_PATH}")
        else:
            logger.info(f"Loading YOLO model from {YOLO_MODEL_PATH}")
            yolo_model = YOLO(YOLO_MODEL_PATH)
            logger.info("YOLO model loaded")
    except Exception as e:
        logger.exception(f"Failed to load YOLO model: {e}")
        yolo_model = None

    # Load PaddleOCR
    try:
        # Prefer user-provided inference model directory
        if os.path.isdir(PADDLE_OCR_DIR):
            logger.info(f"Loading PaddleOCR model from {PADDLE_OCR_DIR}")
            # PaddleOCR will auto-detect detection+recognition models if provided in folder
            ocr_model = PaddleOCR(
                det=True,
                rec=True,
                use_angle_cls=False,
                rec_model_dir=PADDLE_OCR_DIR,
                show_log=False
            )
            logger.info("Custom PaddleOCR model loaded")
        else:
            logger.info("PaddleOCR custom model dir not found, using default models")
            ocr_model = PaddleOCR(use_angle_cls=False, det=True, rec=True, show_log=False)
    except Exception as e:
        logger.exception(f"Failed to initialize PaddleOCR: {e}")
        ocr_model = None

    return yolo_model, ocr_model


def _xyxy_int_array_from_boxes(boxes):
    """
    Helper: converts result.boxes to numpy arrays safely
    boxes: Boxes object from ultralytics Results
    Returns arrays (xyxy_arr, conf_arr, cls_arr) or (None, None, None)
    """
    try:
        xyxy = boxes.xyxy.cpu().numpy()  # shape (N,4)
        conf = boxes.conf.cpu().numpy()  # shape (N,)
        cls = boxes.cls.cpu().numpy()    # shape (N,)
        return xyxy.astype(int), conf, cls.astype(int)
    except Exception:
        # fallback: sometimes boxes is list of Box objects; handle generic iteration
        try:
            arr = []
            confs = []
            clss = []
            for b in boxes:
                coords = b.xyxy[0].cpu().numpy().astype(int)
                arr.append(coords)
                confs.append(float(b.conf[0].cpu().numpy()))
                clss.append(int(b.cls[0].cpu().numpy()))
            if arr:
                return np.array(arr), np.array(confs), np.array(clss)
        except Exception:
            return None, None, None
    return None, None, None


def post_process_license_plate(text):
    """
    Simple post process to clean/format license plate string.
    Keep it conservative and deterministic: uppercase, remove weird chars, try add spaces.
    This function is intentionally simple — keep complex domain rules in separate helper if needed.
    """
    import re
    if not text:
        return ""
    txt = text.upper()
    # common replacements
    subs = {
        '@': '0', 'O': '0', 'Q': '0', 'D': '0',
        'I': '1', 'L': '1', '|': '1', '!': '1',
        'S': '5', 'Z': '2'
    }
    for k, v in subs.items():
        txt = txt.replace(k, v)
    # remove any non alnum or space
    txt = re.sub(r'[^A-Z0-9 ]+', ' ', txt)
    txt = ' '.join(txt.split())
    # attempt to insert spaces for common patterns: 1-2 letters + numbers + 1-3 letters
    no_space = txt.replace(" ", "")
    import re
    m = re.match(r'^([A-Z]{1,2})(\d{1,4})([A-Z]{0,3})$', no_space)
    if m:
        parts = [m.group(1), m.group(2)]
        if m.group(3):
            parts.append(m.group(3))
        return " ".join(parts)
    return txt


def calculate_plate_pattern_score(text):
    """
    Lightweight scoring that rewards patterns likely to be license plates.
    Use this to pick the best OCR candidate among preprocessing attempts.
    """
    import re
    if not text:
        return 0.0
    t = text.replace(" ", "")
    score = 1.0
    if re.match(r'^[A-Z]\d{1,4}[A-Z]{0,3}$', t):
        score *= 10.0
    elif re.match(r'^[A-Z]{2}\d{1,4}[A-Z]{0,3}$', t):
        score *= 9.0
    else:
        # some letters + digits mixture
        if any(c.isdigit() for c in t) and any(c.isalpha() for c in t):
            score *= 4.0
        else:
            score *= 0.5
    # length penalty
    if len(t) < 4 or len(t) > 10:
        score *= 0.5
    return score


def process_image_from_array(img, yolo_model, ocr_model):
    """
    Core pipeline:
    - Run YOLO detection to get candidate plate boxes
    - For each box: crop -> try preprocessing techniques -> OCR -> choose best candidate
    Return list of dicts: [{'text':..., 'confidence':..., 'bbox':[x1,y1,x2,y2], 'method':...}, ...]
    """
    if yolo_model is None or ocr_model is None:
        logger.error("Models not loaded")
        return []

    try:
        # Run YOLO
        results = yolo_model(img, conf=YOLO_CONF_THRESH)
        plate_texts = []

        for res in results:  # iterate result per image (should be one)
            boxes = getattr(res, "boxes", None)
            if boxes is None or len(boxes) == 0:
                continue

            xyxy_arr, conf_arr, cls_arr = _xyxy_int_array_from_boxes(boxes)
            if xyxy_arr is None:
                continue

            for idx, box_coords in enumerate(xyxy_arr):
                x1, y1, x2, y2 = box_coords.tolist()
                det_conf = float(conf_arr[idx]) if conf_arr is not None else 0.0

                # clamp bbox to image bounds
                h, w = img.shape[:2]
                x1, y1 = max(0, x1), max(0, y1)
                x2, y2 = min(w, x2), min(h, y2)
                if x2 <= x1 or y2 <= y1:
                    logger.debug("Invalid bbox, skipping")
                    continue

                plate_img = img[y1:y2, x1:x2]
                if plate_img.size == 0:
                    continue

                # Preprocessing list (kept small and robust)
                preprocs = [
                    ("original", lambda im: im),
                    ("gray_otsu", lambda im: cv2.threshold(cv2.cvtColor(im, cv2.COLOR_BGR2GRAY), 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)[1]),
                    ("median_blur_otsu", lambda im: cv2.threshold(cv2.medianBlur(cv2.cvtColor(im, cv2.COLOR_BGR2GRAY), 3), 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)[1]),
                    ("adaptive", lambda im: cv2.adaptiveThreshold(cv2.cvtColor(im, cv2.COLOR_BGR2GRAY), 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY, 11, 2)),
                    ("clahe", lambda im: cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8,8)).apply(cv2.cvtColor(im, cv2.COLOR_BGR2GRAY)))
                ]

                best_text = ""
                best_score = 0.0
                best_conf = 0.0
                best_method = None

                for name, fn in preprocs:
                    try:
                        proc = fn(plate_img)
                        # ensure 3-channel BGR for OCR if needed
                        if proc.ndim == 2:
                            proc_3ch = cv2.cvtColor(proc, cv2.COLOR_GRAY2BGR)
                        else:
                            proc_3ch = proc

                        # PaddleOCR expects BGR or path; use ocr_model.ocr(image, det=True, rec=True)
                        ocr_res = ocr_model.ocr(proc_3ch, det=True, rec=True)
                        # ocr_res shape: list of [ [(box), (text, score)], ... ] for each detected text
                        # We'll take the highest-confidence recognized text for that preproc
                        candidate_text = None
                        candidate_conf = 0.0
                        if ocr_res and len(ocr_res) > 0 and ocr_res[0]:
                            # iterate detected text regions
                            for item in ocr_res[0]:
                                if len(item) >= 2:
                                    pair = item[1]
                                    # pair may be (text, confidence)
                                    if isinstance(pair, (list, tuple)) and len(pair) >= 2:
                                        txt = str(pair[0]).strip()
                                        conf_val = float(pair[1])
                                        if conf_val > candidate_conf:
                                            candidate_conf = conf_val
                                            candidate_text = txt

                        if candidate_text:
                            cleaned = post_process_license_plate(candidate_text)
                            score = calculate_plate_pattern_score(cleaned)
                            weighted = score * candidate_conf
                            if weighted > best_score:
                                best_score = weighted
                                best_text = cleaned
                                best_conf = candidate_conf
                                best_method = name
                    except Exception as e:
                        logger.debug(f"OCR preprocess {name} failed: {e}")

                if best_text:
                    plate_texts.append({
                        "text": best_text,
                        "confidence": float(best_conf),
                        "preprocessing": best_method,
                        "bbox": [int(x1), int(y1), int(x2), int(y2)],
                        "detection_confidence": float(det_conf)
                    })

        return plate_texts

    except Exception as e:
        logger.exception(f"process_image_from_array error: {e}")
        return []


if __name__ == "__main__":
    # quick local test (if run directly)
    yolo, ocr = setup_models()
    test_img_path = "test.jpg"
    if os.path.exists(test_img_path):
        img = cv2.imread(test_img_path)
        res = process_image_from_array(img, yolo, ocr)
        print(res)
    else:
        logger.info("No test.jpg found for quick local run.")
