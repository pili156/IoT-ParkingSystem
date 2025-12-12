import cv2
import time
import requests
import base64
import logging
from ultralytics import YOLO
from paddleocr import PaddleOCR

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# ==========================
# KONFIGURASI
# ==========================

# Laravel API endpoint untuk ANPR result
LARAVEL_API = "http://10.218.100.27:8000/api/anpr/result"

YOLO_MODEL_PATH = "models/yolo/best.pt"
OCR_MODEL_DIR = "models/ocr"

CAMERA_1_ID = 0   # Pintu Masuk (webcam_index=1)
CAMERA_2_ID = 1   # Pintu Keluar (webcam_index=2)

# Debounce untuk avoid duplicate detection
DEBOUNCE_SECONDS = 4

# ==========================
# LOAD MODEL
# ==========================

print("Loading YOLO model...")
yolo = YOLO(YOLO_MODEL_PATH)

print("Loading OCR model...")
ocr = PaddleOCR(
    use_angle_cls=False,
    lang="en",
    det=False,
    rec=True,
    rec_model_dir=OCR_MODEL_DIR
)

# ==========================
# FUNGSI ANPR
# ==========================

def extract_plate(frame):
    """Deteksi plat lalu OCR."""
    try:
        results = yolo(frame)[0]

        for box in results.boxes:
            cls = int(box.cls[0])
            if cls == 0:  # class 'plate'
                x1, y1, x2, y2 = map(int, box.xyxy[0])
                crop = frame[y1:y2, x1:x2]

                # OCR
                ocr_result = ocr.ocr(crop)
                if ocr_result and ocr_result[0]:
                    text = ocr_result[0][0][0]
                    # Clean format: uppercase, no spaces
                    text = text.upper().replace(' ', '')
                    return text, (x1, y1, x2, y2)

        return None, None
    except Exception as e:
        logger.error(f"Error in extract_plate: {e}")
        return None, None


def send_to_laravel(plate_text, webcam_index, frame=None):
    """
    Kirim hasil ANPR ke Laravel API.
    Args:
        plate_text: Nomor plat (format: BA3242CD)
        webcam_index: 1 untuk masuk, 2 untuk keluar
        frame: Frame gambar (optional)
    """
    try:
        payload = {
            "plate": plate_text,
            "webcam_index": webcam_index,
            "timestamp": time.time()
        }

        # Include image jika tersedia
        if frame is not None:
            _, buffer = cv2.imencode('.jpg', frame)
            img_bytes = buffer.tobytes()
            payload["image_base64"] = base64.b64encode(img_bytes).decode('utf-8')

        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json"
        }

        response = requests.post(LARAVEL_API, json=payload, headers=headers, timeout=10)
        
        if response.status_code in (200, 201):
            logger.info(f"[WEBCAM {webcam_index}] Plate {plate_text} sent to Laravel: {response.json()}")
            return True
        else:
            logger.warning(f"[WEBCAM {webcam_index}] Laravel error {response.status_code}: {response.text}")
            return False
            
    except Exception as e:
        logger.error(f"[WEBCAM {webcam_index}] Error sending to Laravel: {e}")
        return False


# ==========================
# MAIN LOOP
# ==========================

def main():
    cam1 = cv2.VideoCapture(CAMERA_1_ID)
    cam2 = cv2.VideoCapture(CAMERA_2_ID)

    if not cam1.isOpened() or not cam2.isOpened():
        logger.error("Kamera tidak ditemukan!")
        return

    print("\n=== ANPR Dual Camera RUNNING ===")
    print("Camera 1 (Webcam Index 1) = Pintu MASUK")
    print("Camera 2 (Webcam Index 2) = Pintu KELUAR\n")

    last_detect_time_in = 0
    last_detect_time_out = 0

    while True:
        ret1, frame1 = cam1.read()
        ret2, frame2 = cam2.read()

        if not ret1 or not ret2:
            logger.error("Gagal membaca kamera!")
            break

        # -------------------------
        # Kamera Pintu Masuk (Webcam Index = 1)
        # -------------------------
        plate_in, box_in = extract_plate(frame1)
        if plate_in and time.time() - last_detect_time_in > DEBOUNCE_SECONDS:
            logger.info(f"[MASUK] Plat: {plate_in}")
            send_to_laravel(plate_in, webcam_index=1, frame=frame1)
            last_detect_time_in = time.time()

        # -------------------------
        # Kamera Pintu Keluar (Webcam Index = 2)
        # -------------------------
        plate_out, box_out = extract_plate(frame2)
        if plate_out and time.time() - last_detect_time_out > DEBOUNCE_SECONDS:
            logger.info(f"[KELUAR] Plat: {plate_out}")
            send_to_laravel(plate_out, webcam_index=2, frame=frame2)
            last_detect_time_out = time.time()

        # Tampilkan feed
        cv2.imshow("ANPR ENTRY CAM (Webcam 1)", frame1)
        cv2.imshow("ANPR EXIT CAM (Webcam 2)", frame2)

        if cv2.waitKey(1) & 0xFF == ord('q'):
            break

    cam1.release()
    cam2.release()
    cv2.destroyAllWindows()
    logger.info("ANPR system stopped")


if __name__ == "__main__":
    main()
