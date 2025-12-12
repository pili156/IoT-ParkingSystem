import cv2
import time
import requests
from ultralytics import YOLO
from paddleocr import PaddleOCR

# ==========================
# KONFIGURASI
# ==========================

LARAVEL_API = "http://10.218.100.27:8000/api/anpr"

YOLO_MODEL_PATH = "models/yolo/best.pt"
OCR_MODEL_DIR = "models/ocr"

CAMERA_1_ID = 0   # Pintu Masuk
CAMERA_2_ID = 1   # Pintu Keluar

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
                return text, (x1, y1, x2, y2)

    return None, None


def send_to_laravel(plate_text, gate_type):
    """Kirim hasil ANPR ke Laravel API."""
    try:
        data = {
            "plate": plate_text,
            "gate": gate_type  # "ENTRY" atau "EXIT"
        }

        res = requests.post(LARAVEL_API, json=data, timeout=3)
        print("Laravel Response:", res.text)

    except Exception as e:
        print("Error sending to Laravel:", e)


# ==========================
# MAIN LOOP
# ==========================

def main():
    cam1 = cv2.VideoCapture(CAMERA_1_ID)
    cam2 = cv2.VideoCapture(CAMERA_2_ID)

    if not cam1.isOpened() or not cam2.isOpened():
        print("Kamera tidak ditemukan!")
        return

    print("\n=== ANPR Dual Camera RUNNING ===")
    print("Camera 1 = Pintu MASUK")
    print("Camera 2 = Pintu KELUAR\n")

    last_detect_time_in = 0
    last_detect_time_out = 0

    while True:
        ret1, frame1 = cam1.read()
        ret2, frame2 = cam2.read()

        if not ret1 or not ret2:
            print("Gagal membaca kamera!")
            break

        # -------------------------
        # Kamera Pintu Masuk
        # -------------------------
        plate_in, box_in = extract_plate(frame1)
        if plate_in and time.time() - last_detect_time_in > 4:
            print("[MASUK] Plat:", plate_in)
            send_to_laravel(plate_in, "ENTRY")
            last_detect_time_in = time.time()

        # -------------------------
        # Kamera Pintu Keluar
        # -------------------------
        plate_out, box_out = extract_plate(frame2)
        if plate_out and time.time() - last_detect_time_out > 4:
            print("[KELUAR] Plat:", plate_out)
            send_to_laravel(plate_out, "EXIT")
            last_detect_time_out = time.time()

        # Tampilkan feed
        cv2.imshow("ANPR ENTRY CAM", frame1)
        cv2.imshow("ANPR EXIT CAM", frame2)

        if cv2.waitKey(1) & 0xFF == ord('q'):
            break

    cam1.release()
    cam2.release()
    cv2.destroyAllWindows()


if __name__ == "__main__":
    main()
