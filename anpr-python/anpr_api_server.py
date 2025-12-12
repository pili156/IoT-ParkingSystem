# anpr_api_server.py
import os
import time
import logging
import traceback
import requests
import numpy as np
import cv2
from flask import Flask, request, jsonify

from anpr_bisa import setup_models, process_image_from_array

# Logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("anpr_api_server")

# Configs (env override)
LARAVEL_API_URL = os.getenv("LARAVEL_API_URL", "http://localhost:8000/api")
ANPR_TOKEN = os.getenv("ANPR_TOKEN", "your_anpr_token_here")
MODEL_YOLO_PATH = os.getenv("YOLO_MODEL_PATH", "models/yolo/best.pt")
MODEL_OCR_DIR = os.getenv("PADDLE_OCR_DIR", "models/ocr")

# Initialize Flask
app = Flask(__name__)

# Global models
yolo_model = None
ocr_model = None

def initialize_models():
    global yolo_model, ocr_model
    try:
        yolo_model, ocr_model = setup_models()
        if yolo_model is None:
            logger.warning("YOLO model not loaded.")
        if ocr_model is None:
            logger.warning("PaddleOCR model not loaded.")
    except Exception as e:
        logger.exception(f"Failed initialize_models: {e}")

def send_to_laravel_api(plate_number, webcam_index=1, image_bytes=None, timestamp=None):
    """
    Sends recognized plate to Laravel backend.
    Args:
        plate_number: Nomor plat (format: BA3242CD)
        webcam_index: 1 untuk masuk, 2 untuk keluar
        image_bytes: Raw image bytes (optional)
        timestamp: Unix timestamp (optional, akan use server time jika None)
    
    Returns (success_bool, response_json_or_text)
    """
    try:
        payload = {
            "plate": plate_number.upper().replace(' ', ''),
            "webcam_index": webcam_index,
            "timestamp": timestamp or time.time()
        }
        if image_bytes:
            import base64
            payload["image_base64"] = base64.b64encode(image_bytes).decode("utf-8")

        headers = {
            "Authorization": f"Bearer {ANPR_TOKEN}",
            "Content-Type": "application/json",
            "Accept": "application/json"
        }

        url = f"{LARAVEL_API_URL.rstrip('/')}/anpr/result"
        logger.info(f"Posting to Laravel {url} | plate={plate_number} | webcam={webcam_index}")
        r = requests.post(url, json=payload, headers=headers, timeout=30)
        
        if r.status_code in (200, 201):
            try:
                return True, r.json()
            except Exception:
                return True, r.text
        else:
            logger.error(f"Laravel responded {r.status_code}: {r.text}")
            return False, r.text
    except Exception as e:
        logger.exception(f"Error sending to Laravel: {e}")
        return False, str(e)


def process_camera_image(image_data):
    """
    Decode bytes from ESP32 and run ANPR pipeline. Returns (plate_text or None, details or error string)
    """
    global yolo_model, ocr_model
    try:
        nparr = np.frombuffer(image_data, np.uint8)
        img = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
        if img is None:
            return None, "cannot decode image"

        plates = process_image_from_array(img, yolo_model, ocr_model)
        if not plates:
            return None, "no plate detected"
        # choose best by combined (detection_confidence * recognition_confidence)
        best = None
        best_score = 0.0
        for p in plates:
            det = p.get("detection_confidence", 0.0)
            rec = p.get("confidence", 0.0)
            score = det * rec
            if score > best_score:
                best_score = score
                best = p
        if best:
            return best.get("text"), best
        else:
            return None, "no confident plate"
    except Exception as e:
        logger.exception("process_camera_image error")
        return None, str(e)


@app.route("/process_image", methods=["POST"])
def process_image_endpoint():
    """
    Accepts image bytes with webcam_index parameter.
    Query params or POST data:
      - webcam_index: 1 (masuk) atau 2 (keluar)
    
    Returns JSON dengan plate dan Laravel response status.
    """
    try:
        # Get webcam index (required)
        webcam_index = request.args.get('webcam_index', request.form.get('webcam_index', 1), type=int)
        if webcam_index not in (1, 2):
            return jsonify({"success": False, "message": "webcam_index harus 1 atau 2"}), 400

        # Get image bytes
        if request.content_type and request.content_type.startswith("image/"):
            img_bytes = request.get_data()
        elif "image" in request.files:
            img_bytes = request.files["image"].read()
        else:
            img_bytes = request.get_data()

        if not img_bytes or len(img_bytes) == 0:
            return jsonify({"success": False, "message": "no image data"}), 400

        # Process ANPR
        plate_text, meta = process_camera_image(img_bytes)
        if not plate_text:
            return jsonify({"success": True, "message": "no plate detected", "data": meta}), 200

        # Send to Laravel dengan webcam_index
        timestamp = request.args.get('timestamp', request.form.get('timestamp'), type=float)
        sent, r = send_to_laravel_api(plate_text, webcam_index=webcam_index, 
                                      image_bytes=img_bytes, timestamp=timestamp)
        
        result = {
            "success": sent,
            "plate": plate_text,
            "webcam_index": webcam_index,
            "laravel_response": r
        }
        status = 200 if sent else 500
        return jsonify(result), status

    except Exception as e:
        logger.exception("process_image_endpoint error")
        return jsonify({"success": False, "message": str(e)}), 500


@app.route("/health", methods=["GET"])
def health():
    return jsonify({
        "success": True,
        "models_loaded": (yolo_model is not None) and (ocr_model is not None),
        "yolo_path": MODEL_YOLO_PATH,
        "ocr_dir": MODEL_OCR_DIR,
        "timestamp": time.time()
    }), 200


if __name__ == "__main__":
    initialize_models()
    # Run Flask app
    app.run(host="0.0.0.0", port=int(os.getenv("ANPR_PORT", 5000)), debug=False)
