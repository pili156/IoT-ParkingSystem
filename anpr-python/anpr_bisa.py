# anpr_bisa.py
import os
import time
import logging
import cv2
import numpy as np
import requests
from ultralytics import YOLO
from paddleocr import PaddleOCR
from datetime import datetime, timezone
import json
import threading

# Retry / queue settings
ANPR_RETRIES = int(os.getenv('ANPR_RETRIES', 3))
ANPR_RETRY_BACKOFF = int(os.getenv('ANPR_RETRY_BACKOFF', 1))  # seconds multiply
ANPR_RETRY_INTERVAL = int(os.getenv('ANPR_RETRY_INTERVAL', 30))  # background flush interval
ANPR_QUEUE_FILE = os.getenv('ANPR_QUEUE_FILE', 'anpr_failed_queue.jsonl')
ANPR_TOKEN = os.getenv('ANPR_TOKEN', None)

logger = logging.getLogger(__name__)
logging.basicConfig(level=logging.INFO)

# ===================================================
# CONFIG
# ===================================================
YOLO_MODEL_PATH = os.getenv("YOLO_MODEL_PATH", "models/yolo/best.pt")
PADDLE_OCR_DIR = os.getenv("PADDLE_OCR_DIR", "models/ocr")
YOLO_CONF_THRESH = float(os.getenv("YOLO_CONF_THRESH", 0.5))
OCR_MIN_CONF = float(os.getenv("OCR_MIN_CONF", 0.35))

# Laravel (default points to Laravel API endpoint)
LARAVEL_API_URL = os.getenv("LARAVEL_API_URL", "http://localhost:8000/api/anpr/result")
ANPR_TOKEN = os.getenv("ANPR_TOKEN", "your_anpr_token_here")
# Default mode for this camera (entry/exit). You can override with env DEFAULT_MODE
DEFAULT_MODE = os.getenv("DEFAULT_MODE", "entry")

# Dual camera configuration: Entry camera index and Exit camera index
ENTRY_CAMERA_INDEX = int(os.getenv("ENTRY_CAMERA_INDEX", 1))
EXIT_CAMERA_INDEX = int(os.getenv("EXIT_CAMERA_INDEX", 2))

# Legacy single-camera index (kept for compatibility)
WEBCAM_INDEX = int(os.getenv("WEBCAM_INDEX", 0))  # USB cam index
COOLDOWN = int(os.getenv("COOLDOWN", 2))  # detik sebelum kirim plat yang sama lagi

# ===================================================
# HELPERS
# ===================================================
def post_process_license_plate(text):
    import re
    if not text:
        return ""
    txt = text.upper()
    subs = {'@':'0','O':'0','Q':'0','D':'0','I':'1','L':'1','|':'1','!':'1','S':'5','Z':'2'}
    for k,v in subs.items():
        txt = txt.replace(k,v)
    txt = re.sub(r'[^A-Z0-9 ]+', ' ', txt)
    txt = ' '.join(txt.split())
    no_space = txt.replace(" ","")
    m = re.match(r'^([A-Z]{1,2})(\d{1,4})([A-Z]{0,3})$', no_space)
    if m:
        parts = [m.group(1), m.group(2)]
        if m.group(3):
            parts.append(m.group(3))
        return " ".join(parts)
    return txt

def calculate_plate_pattern_score(text):
    import re
    if not text:
        return 0.0
    t = text.replace(" ","")
    score = 1.0
    if re.match(r'^[A-Z]\d{1,4}[A-Z]{0,3}$', t):
        score *= 10.0
    elif re.match(r'^[A-Z]{2}\d{1,4}[A-Z]{0,3}$', t):
        score *= 9.0
    else:
        if any(c.isdigit() for c in t) and any(c.isalpha() for c in t):
            score *= 4.0
        else:
            score *= 0.5
    if len(t)<4 or len(t)>10:
        score *= 0.5
    return score

# ===================================================
# DUMMY OCR
# ===================================================
class DummyOCR:
    def __init__(self):
        self._warned = False
    def ocr(self, *args, **kwargs):
        if not self._warned:
            logger.error("PaddleOCR not available — OCR disabled.")
            self._warned = True
        return []

# ===================================================
# SETUP MODELS
# ===================================================
def setup_models():
    yolo_model = None
    ocr_model = None

    # YOLO
    if os.path.exists(YOLO_MODEL_PATH):
        logger.info(f"Loading YOLO model from {YOLO_MODEL_PATH}")
        yolo_model = YOLO(YOLO_MODEL_PATH)
    else:
        logger.warning(f"YOLO model not found at {YOLO_MODEL_PATH}")

    # PaddleOCR
    try:
        if os.path.isdir(PADDLE_OCR_DIR):
            ocr_model = PaddleOCR(use_angle_cls=False, det=True, rec=True, rec_model_dir=PADDLE_OCR_DIR, show_log=False)
            logger.info("PaddleOCR loaded successfully")
        else:
            ocr_model = PaddleOCR(use_angle_cls=False, lang='en')
            logger.info("PaddleOCR default model loaded")
    except Exception as e:
        logger.exception("Failed to load PaddleOCR, using DummyOCR")
        ocr_model = DummyOCR()

    return yolo_model, ocr_model

# ===================================================
# PROCESS IMAGE
# ===================================================
def process_image_from_array(img, yolo_model, ocr_model):
    if yolo_model is None or ocr_model is None:
        return []

    try:
        results = yolo_model(img, conf=YOLO_CONF_THRESH)
        plates = []

        for res in results:
            boxes = getattr(res, "boxes", None)
            if boxes is None or len(boxes)==0:
                continue

            xyxy = boxes.xyxy.cpu().numpy().astype(int)
            confs = boxes.conf.cpu().numpy()
            for i, box in enumerate(xyxy):
                x1,y1,x2,y2 = box.tolist()
                det_conf = float(confs[i])
                # Pastikan koordinat dalam batas gambar
                x1, y1 = max(0, x1), max(0, y1)
                x2, y2 = min(img.shape[1], x2), min(img.shape[0], y2)
                plate_img = img[y1:y2, x1:x2]

                if plate_img.size==0:
                    continue

                # Coba beberapa teknik pra-pemrosesan untuk meningkatkan OCR
                candidate_texts = []

                # Teknik 1: Threshold biasa
                proc1 = cv2.cvtColor(plate_img, cv2.COLOR_BGR2GRAY)
                _, proc1 = cv2.threshold(proc1,0,255,cv2.THRESH_BINARY + cv2.THRESH_OTSU)
                proc1_3ch = cv2.cvtColor(proc1, cv2.COLOR_GRAY2BGR)

                # Teknik 2: Contrast enhancement
                proc2 = cv2.cvtColor(plate_img, cv2.COLOR_BGR2GRAY)
                proc2 = cv2.equalizeHist(proc2)
                proc2 = cv2.cvtColor(proc2, cv2.COLOR_GRAY2BGR)

                # Teknik 3: CLAHE
                proc3 = cv2.cvtColor(plate_img, cv2.COLOR_BGR2GRAY)
                clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8,8))
                proc3 = clahe.apply(proc3)
                proc3 = cv2.cvtColor(proc3, cv2.COLOR_GRAY2BGR)

                # Teknik 4: Gaussian blur + threshold
                proc4 = cv2.cvtColor(plate_img, cv2.COLOR_BGR2GRAY)
                proc4 = cv2.GaussianBlur(proc4, (5, 5), 0)
                _, proc4 = cv2.threshold(proc4, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
                proc4_3ch = cv2.cvtColor(proc4, cv2.COLOR_GRAY2BGR)

                # Teknik 5: Bilateral filter untuk mengurangi noise
                proc5 = cv2.bilateralFilter(plate_img, 9, 75, 75)
                proc5 = cv2.cvtColor(proc5, cv2.COLOR_BGR2GRAY)
                _, proc5 = cv2.threshold(proc5, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
                proc5_3ch = cv2.cvtColor(proc5, cv2.COLOR_GRAY2BGR)

                # Proses semua teknik pra-pemrosesan
                for idx, processed_img in enumerate([proc1_3ch, proc2, proc3, proc4_3ch, proc5_3ch]):
                    try:
                        ocr_res = ocr_model.ocr(processed_img, det=False, rec=True)
                        if ocr_res and len(ocr_res) > 0 and ocr_res[0]:
                            for item in ocr_res[0]:
                                if isinstance(item, (tuple, list)) and len(item) >= 2:
                                    txt = str(item[0])
                                    conf_val = float(item[1])
                                    candidate_texts.append((txt, conf_val, f"method_{idx+1}"))
                    except Exception as ocr_e:
                        logger.warning(f"OCR error with method {idx+1}: {ocr_e}")
                        continue

                # Pilih teks terbaik berdasarkan kepercayaan dan skor pola
                best_text = ""
                best_conf = 0.0
                best_score = 0.0

                for text, conf, method in candidate_texts:
                    if text.strip():
                        cleaned = post_process_license_plate(text)
                        pattern_score = calculate_plate_pattern_score(cleaned)
                        weighted_score = pattern_score * conf

                        if weighted_score > best_score:
                            best_score = weighted_score
                            best_text = cleaned
                            best_conf = conf

                # Jika ada teks yang terdeteksi dengan keyakinan cukup
                if best_text and best_conf > OCR_MIN_CONF:
                    plates.append({
                        "text": best_text,
                        "confidence": best_conf,
                        "bbox": [int(x1), int(y1), int(x2), int(y2)],
                        "detection_confidence": det_conf,
                        "processing_method": "multi_method_optimized"
                    })

        return plates

    except Exception as e:
        logger.exception(f"process_image_from_array error: {e}")
        return []

# ===================================================
# SEND TO LARAVEL
# ===================================================
def send_plate_to_laravel(plate_text, frame=None, webcam_index=1, slot_name=None, mode=None, timestamp=None):
    """Send OCR result to Laravel API including mode/webcam_index and optional image.
    Returns (success_bool, response_text_or_json)."""
    if not LARAVEL_API_URL:
        return False, "Laravel URL not configured"

    mode_to_send = mode or ("entry" if int(webcam_index) == 1 else "exit")

    # Normalize timestamp to ISO8601 (no microseconds) because Laravel 'date' validator
    # is more reliable with standard formats like 'YYYY-MM-DDTHH:MM:SSZ'
    if timestamp is None:
        ts_str = datetime.utcnow().replace(microsecond=0).isoformat() + 'Z'
    else:
        try:
            # numeric timestamp (seconds): convert to UTC ISO8601
            if isinstance(timestamp, (int, float)):
                ts_str = datetime.fromtimestamp(float(timestamp), timezone.utc).replace(microsecond=0).isoformat()
                if ts_str.endswith('+00:00'):
                    ts_str = ts_str.replace('+00:00', 'Z')
            else:
                # assume it's already a date string; strip microseconds if present
                try:
                    parsed = datetime.fromisoformat(str(timestamp))
                    ts_str = parsed.replace(microsecond=0).isoformat()
                    if ts_str.endswith('+00:00'):
                        ts_str = ts_str.replace('+00:00', 'Z')
                except Exception:
                    # fallback to current time
                    ts_str = datetime.utcnow().replace(microsecond=0).isoformat() + 'Z'
        except Exception:
            ts_str = datetime.utcnow().replace(microsecond=0).isoformat() + 'Z'

    payload = {
        "plate": plate_text,
        "mode": mode_to_send,
        "webcam_index": int(webcam_index),
        "timestamp": ts_str
    }

    if slot_name:
        payload["slot_name"] = slot_name

    if frame is not None:
        try:
            import base64
            image_bytes = cv2.imencode(".jpg", frame)[1].tobytes()
            payload["image_base64"] = base64.b64encode(image_bytes).decode("utf-8")
        except Exception as e:
            logger.debug(f"Failed to encode image: {e}")

    headers = {"Content-Type": "application/json"}
    if ANPR_TOKEN:
        # attach optional token header if set
        headers['Authorization'] = f"Bearer {ANPR_TOKEN}"

    last_err = None
    for attempt in range(1, ANPR_RETRIES + 1):
        try:
            r = requests.post(LARAVEL_API_URL, json=payload, headers=headers, timeout=15)
            try:
                j = r.json()
            except Exception:
                j = r.text
            if r.status_code in (200, 201):
                logger.info(f"Laravel stored plate {plate_text}: {r.status_code}")
                return True, j
            else:
                last_err = (r.status_code, j)
                logger.error(f"Laravel responded {r.status_code}: {j} (attempt {attempt}/{ANPR_RETRIES})")
        except Exception as e:
            last_err = str(e)
            logger.exception(f"Failed send to Laravel (attempt {attempt}/{ANPR_RETRIES}): {e}")

        # backoff
        time.sleep(ANPR_RETRY_BACKOFF * (2 ** (attempt - 1)))

    # all attempts failed — persist to queue for later retry
    try:
        with open(ANPR_QUEUE_FILE, 'a', encoding='utf-8') as fh:
            fh.write(json.dumps(payload, ensure_ascii=False) + '\n')
        logger.warning(f"Payload queued to {ANPR_QUEUE_FILE} for retry later")
    except Exception as e:
        logger.exception(f"Failed to write to queue file: {e}")

    return False, last_err


# Queue flush helpers

def retry_queued_messages():
    if not os.path.exists(ANPR_QUEUE_FILE):
        return
    temp_file = ANPR_QUEUE_FILE + '.tmp'
    any_left = False
    try:
        with open(ANPR_QUEUE_FILE, 'r', encoding='utf-8') as fh_in, open(temp_file, 'w', encoding='utf-8') as fh_out:
            for line in fh_in:
                line = line.strip()
                if not line:
                    continue
                try:
                    payload = json.loads(line)
                except Exception:
                    fh_out.write(line + '\n')
                    any_left = True
                    continue
                # attempt to resend
                try:
                    headers = {"Content-Type": "application/json"}
                    if ANPR_TOKEN:
                        headers['Authorization'] = f"Bearer {ANPR_TOKEN}"
                    r = requests.post(LARAVEL_API_URL, json=payload, headers=headers, timeout=15)
                    if r.status_code in (200, 201):
                        logger.info("Queued payload successfully resent")
                        continue
                    else:
                        logger.error(f"Queued payload failed with status {r.status_code}: {r.text}")
                        fh_out.write(json.dumps(payload, ensure_ascii=False) + '\n')
                        any_left = True
                except Exception as e:
                    logger.exception(f"Error resending queued payload: {e}")
                    fh_out.write(json.dumps(payload, ensure_ascii=False) + '\n')
                    any_left = True
        # replace original queue
        os.replace(temp_file, ANPR_QUEUE_FILE)
    except FileNotFoundError:
        if os.path.exists(temp_file):
            os.remove(temp_file)
    except Exception as e:
        logger.exception(f"Error during queue retry: {e}")


def queued_retry_worker():
    while True:
        try:
            retry_queued_messages()
        except Exception:
            logger.exception("queued_retry_worker failed")
        time.sleep(ANPR_RETRY_INTERVAL)

# ===================================================
# GEMINI API INTEGRATION
# ===================================================

# Import tambahan untuk Gemini API
try:
    import google.generativeai as genai
    GEMINI_AVAILABLE = True
except ImportError:
    logger.warning("Google Generative AI library not installed. Install with: pip install google-generativeai")
    GEMINI_AVAILABLE = False

GEMINI_API_KEY = os.getenv("GEMINI_API_KEY", None)
GEMINI_MODEL_NAME = os.getenv("GEMINI_MODEL_NAME", "gemini-pro-vision")

def extract_text_with_gemini(image, fallback_text=None, fallback_conf=0.0):
    """
    Fungsi untuk mengekstrak teks dari gambar menggunakan Gemini API.
    Jika teks dari fallback tersedia, gunakan itu sebagai referensi tambahan.
    """
    if not GEMINI_AVAILABLE:
        logger.warning("Gemini API not available - Google Generative AI library missing")
        return fallback_text, fallback_conf

    if not GEMINI_API_KEY:
        logger.warning("GEMINI_API_KEY not set in environment")
        return fallback_text, fallback_conf

    try:
        genai.configure(api_key=GEMINI_API_KEY)
        model = genai.GenerativeModel(GEMINI_MODEL_NAME)

        # Konversi gambar OpenCV ke format yang bisa diproses Gemini
        _, img_encoded = cv2.imencode('.jpg', image)
        img_bytes = img_encoded.tobytes()

        # Buat prompt khusus untuk membaca plat nomor
        prompt = """
        Ekstrak nomor plat mobil dari gambar ini. Hanya kembalikan nomor platnya saja, tanpa penjelasan tambahan.
        Contoh format nomor plat Indonesia: B 1234 ABC, CC 1234 AB, D 5678 XY
        Jika tidak ada plat nomor yang terlihat dengan jelas, kembalikan pesan "TIDAK ADA PLAT".
        """

        response = model.generate_content([
            prompt,
            {
                "mime_type": "image/jpeg",
                "data": img_bytes
            }
        ])

        if response.text:
            plate_text = response.text.strip()
            # Hapus pesan standar jika tidak ada plat
            if "TIDAK ADA PLAT" in plate_text.upper():
                return None, 0.0
            # Bersihkan teks plat
            cleaned_plate = post_process_license_plate(plate_text)
            return cleaned_plate, 0.8  # Kita asumsikan kepercayaan 0.8 untuk hasil Gemini
    except Exception as e:
        logger.error(f"Gemini API error: {e}")

    return fallback_text, fallback_conf


def process_image_from_array_with_fallback(img, yolo_model, ocr_model):
    """
    Versi yang mencoba OCR lokal dulu, baru pakai Gemini API jika gagal
    """
    # Coba dulu dengan OCR lokal
    plates = process_image_from_array(img, yolo_model, ocr_model)

    # Jika tidak ada hasil yang bagus dari OCR lokal, coba dengan Gemini
    if len(plates) == 0 or all(plate.get("confidence", 0) < 0.6 for plate in plates):
        logger.info("Local OCR failed or low confidence, trying Gemini API...")
        # Coba deteksi YOLO dulu untuk mendapatkan kotak plat
        try:
            results = yolo_model(img, conf=YOLO_CONF_THRESH)
            for res in results:
                boxes = getattr(res, "boxes", None)
                if boxes is None or len(boxes) == 0:
                    continue

                xyxy = boxes.xyxy.cpu().numpy().astype(int)
                confs = boxes.conf.cpu().numpy()
                for i, box in enumerate(xyxy):
                    x1, y1, x2, y2 = box.tolist()
                    det_conf = float(confs[i])
                    # Pastikan koordinat dalam batas gambar
                    x1, y1 = max(0, x1), max(0, y1)
                    x2, y2 = min(img.shape[1], x2), min(img.shape[0], y2)
                    plate_img = img[y1:y2, x1:x2]

                    if plate_img.size == 0:
                        continue

                    # Ekstrak teks dengan Gemini
                    gemini_text, gemini_conf = extract_text_with_gemini(plate_img)

                    if gemini_text and gemini_conf > 0.5:
                        plates.append({
                            "text": gemini_text,
                            "confidence": gemini_conf,
                            "bbox": [int(x1), int(y1), int(x2), int(y2)],
                            "detection_confidence": det_conf,
                            "processing_method": "gemini_api"
                        })
                        logger.info(f"Gemini API successfully extracted plate: {gemini_text}")
                        break  # Hanya ambil satu hasil terbaik dari Gemini
        except Exception as e:
            logger.error(f"Error in Gemini fallback: {e}")

    return plates


# ===================================================
# CAMERA LOOP (Dual camera: entry=1, exit=2)
# ===================================================

def run_camera_loop():
    yolo_model, ocr_model = setup_models()

    cam_in = cv2.VideoCapture(ENTRY_CAMERA_INDEX)
    cam_out = cv2.VideoCapture(EXIT_CAMERA_INDEX)

    if not cam_in.isOpened():
        logger.error(f"Cannot open entry camera {ENTRY_CAMERA_INDEX}")
        # still try to open exit camera
    if not cam_out.isOpened():
        logger.error(f"Cannot open exit camera {EXIT_CAMERA_INDEX}")

    if not cam_in.isOpened() and not cam_out.isOpened():
        logger.error("No cameras available. Exiting.")
        return

    logger.info(f"Cameras started (in={ENTRY_CAMERA_INDEX}, out={EXIT_CAMERA_INDEX}), press ESC or 'q' to exit")

    # Start background retry worker thread
    t = threading.Thread(target=queued_retry_worker, daemon=True)
    t.start()

    last_plate_in = None
    last_time_in = 0
    last_plate_out = None
    last_time_out = 0

    try:
        while True:
            # Read frames (if camera available)
            ret_in, frame_in = (False, None)
            ret_out, frame_out = (False, None)

            if cam_in.isOpened():
                ret_in, frame_in = cam_in.read()
            if cam_out.isOpened():
                ret_out, frame_out = cam_out.read()

            # Process entry camera
            if ret_in and frame_in is not None:
                # Gunakan fungsi dengan fallback Gemini API
                plates_in = process_image_from_array_with_fallback(frame_in, yolo_model, ocr_model)
                for plate in plates_in:
                    plate_text = plate.get("text")
                    if plate_text and (plate_text != last_plate_in or time.time() - last_time_in > COOLDOWN):
                        last_plate_in = plate_text
                        last_time_in = time.time()
                        logger.info(f"[ENTRY] Plate detected: {plate_text} (method: {plate.get('processing_method', 'unknown')})")
                        # send detection only (no image) and mode 'entry'
                        send_plate_to_laravel(plate_text, frame=None, webcam_index=1, slot_name=None, mode='entry')
                    x1, y1, x2, y2 = plate.get("bbox", [0,0,0,0])
                    cv2.rectangle(frame_in, (x1,y1), (x2,y2), (0,255,0), 2)
                    cv2.putText(frame_in, plate_text, (x1, max(y1-5,0)), cv2.FONT_HERSHEY_SIMPLEX, 0.8, (0,255,0), 2)

            # Process exit camera
            if ret_out and frame_out is not None:
                # Gunakan fungsi dengan fallback Gemini API
                plates_out = process_image_from_array_with_fallback(frame_out, yolo_model, ocr_model)
                for plate in plates_out:
                    plate_text = plate.get("text")
                    if plate_text and (plate_text != last_plate_out or time.time() - last_time_out > COOLDOWN):
                        last_plate_out = plate_text
                        last_time_out = time.time()
                        logger.info(f"[EXIT] Plate detected: {plate_text} (method: {plate.get('processing_method', 'unknown')})")
                        # send detection only (no image) and mode 'exit'
                        send_plate_to_laravel(plate_text, frame=None, webcam_index=2, slot_name=None, mode='exit')
                    x1, y1, x2, y2 = plate.get("bbox", [0,0,0,0])
                    cv2.rectangle(frame_out, (x1,y1), (x2,y2), (255,0,0), 2)
                    cv2.putText(frame_out, plate_text, (x1, max(y1-5,0)), cv2.FONT_HERSHEY_SIMPLEX, 0.8, (255,0,0), 2)

            # Show windows
            if ret_in and frame_in is not None:
                cv2.imshow(f"ANPR ENTRY CAM (index={ENTRY_CAMERA_INDEX})", frame_in)
            if ret_out and frame_out is not None:
                cv2.imshow(f"ANPR EXIT CAM (index={EXIT_CAMERA_INDEX})", frame_out)

            key = cv2.waitKey(1) & 0xFF
            if key == 27 or key == ord('q'):
                break

    except KeyboardInterrupt:
        logger.info("Interrupted by user")
    finally:
        if cam_in.isOpened():
            cam_in.release()
        if cam_out.isOpened():
            cam_out.release()
        cv2.destroyAllWindows()
        logger.info("Cameras stopped")

# ===================================================
# MAIN
# ===================================================
if __name__=="__main__":
    run_camera_loop()
