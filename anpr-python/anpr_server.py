from flask import Flask, request, jsonify
import cv2
import numpy as np
from anpr_model import detect_plate, read_text  # contoh

app = Flask(__name__)

@app.route("/anpr", methods=["POST"])
def anpr():
    if "image" not in request.files:
        return jsonify({"error": "image file required"}), 400

    file = request.files["image"]
    img_bytes = file.read()

    # Decode bytes → OpenCV image
    img_array = np.frombuffer(img_bytes, np.uint8)
    img = cv2.imdecode(img_array, cv2.IMREAD_COLOR)

    if img is None:
        return jsonify({"error": "Failed to decode image"}), 500

    # YOLO detect license plate
    plate_img = detect_plate(img)

    # PaddleOCR read text
    plate_number = read_text(plate_img)

    return jsonify({
        "plate_number": plate_number
    })

@app.route("/read_plate", methods=["POST"])
def read_plate():
    if "image" not in request.files:
        return jsonify({"error": "image file required"}), 400

    file = request.files["image"]
    img_bytes = file.read()

    # Decode bytes → OpenCV image   
    img_array = np.frombuffer(img_bytes, np.uint8)
    img = cv2.imdecode(img_array, cv2.IMREAD_COLOR)

    if img is None:
        return jsonify({"error": "Failed to decode image"}), 500

    # YOLO detect license plate
    plate_img = detect_plate(img)

    # PaddleOCR read text
    plate_number = read_text(plate_img)

    return jsonify({
        "plate_number": plate_number
    })

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000)
