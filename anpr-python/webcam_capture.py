import cv2
import requests
import numpy as np
import time
import logging
from anpr_bisa import setup_models, process_image

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Configuration constants
CAMERA_INDEX = 0  # Change to 1 or other numbers if multiple cameras exist
CAMERA_RESOLUTION = (1280, 720)  # 720p resolution, optimal for ANPR
CAMERA_FPS = 15  # Frame rate to avoid overwhelming the system
ANPR_SERVER_URL = "http://localhost:5000/process_image"  # Default ANPR server URL

def initialize_camera(camera_index=CAMERA_INDEX, resolution=CAMERA_RESOLUTION, fps=CAMERA_FPS):
    """
    Initialize the Logitech Webcam with optimal settings
    """
    cap = cv2.VideoCapture(camera_index)

    if not cap.isOpened():
        logger.error(f"Cannot open camera at index {camera_index}")
        return None

    # Set camera properties for Logitech Webcam
    cap.set(cv2.CAP_PROP_FRAME_WIDTH, resolution[0])
    cap.set(cv2.CAP_PROP_FRAME_HEIGHT, resolution[1])
    cap.set(cv2.CAP_PROP_FPS, fps)

    # Additional settings for better image quality
    cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)  # Reduce buffering for more real-time behavior
    cap.set(cv2.CAP_PROP_FOURCC, cv2.VideoWriter_fourcc('M', 'J', 'P', 'G'))  # Use MJPEG format if available

    logger.info(f"Camera initialized successfully at index {camera_index}")
    logger.info(f"Camera resolution: {resolution[0]}x{resolution[1]}")
    logger.info(f"Camera FPS: {fps}")

    return cap

def capture_and_send_frame(cap, server_url=ANPR_SERVER_URL):
    """
    Capture a frame from the camera and send it to the ANPR server
    """
    ret, frame = cap.read()

    if not ret:
        logger.error("Failed to capture frame from camera")
        return False, None

    # Encode frame as JPEG with high quality
    encode_param = [int(cv2.IMWRITE_JPEG_QUALITY), 90]
    _, img_encoded = cv2.imencode('.jpg', frame, encode_param)

    try:
        # Send image to ANPR server
        response = requests.post(
            server_url,
            data=img_encoded.tobytes(),
            headers={'Content-Type': 'application/octet-stream'},
            timeout=10  # Add timeout to prevent hanging
        )

        if response.status_code == 200:
            result = response.json()
            logger.info(f"ANPR Result: {result}")

            # Extract plate number if available
            plate_number = None
            if result.get('success') and result.get('data', {}).get('plate_number'):
                plate_number = result['data']['plate_number']
                logger.info(f"Detected plate: {plate_number}")

            return True, plate_number
        else:
            logger.error(f"Server returned status {response.status_code}: {response.text}")
            return False, None

    except requests.exceptions.RequestException as e:
        logger.error(f"Error sending frame to ANPR server: {e}")
        return False, None

def run_webcam_anpr(cap, server_url=ANPR_SERVER_URL, capture_interval=2.0):
    """
    Main loop to continuously capture frames and run ANPR
    capture_interval: Time between captures in seconds (to avoid overwhelming the server)
    """
    logger.info("Starting webcam ANPR system. Press 'q' to quit.")

    frame_count = 0
    last_capture_time = time.time()

    while True:
        ret, frame = cap.read()

        if not ret:
            logger.error("Failed to read frame from camera")
            break

        # Display the frame
        cv2.imshow('Webcam ANPR - Press q to quit', frame)

        # Process frame at specified intervals
        current_time = time.time()
        if current_time - last_capture_time >= capture_interval:
            last_capture_time = current_time

            success, plate_number = capture_and_send_frame(cap, server_url)

            if success and plate_number:
                logger.info(f"PLATE DETECTED: {plate_number}")

                # You could add additional logic here, like:
                # - Saving the detection to a database
                # - Sending notifications
                # - Logging to a file
            elif success:
                logger.info("No plate detected in this frame")
            else:
                logger.error("Failed to process frame")

        # Check for quit key
        key = cv2.waitKey(1) & 0xFF
        if key == ord('q'):
            break

        frame_count += 1

    # Cleanup
    cap.release()
    cv2.destroyAllWindows()
    logger.info("Webcam ANPR system stopped.")

def test_camera_configurations():
    """
    Test different camera configurations to find the optimal one for Logitech Webcam
    """
    # Try different camera indexes
    for i in range(4):
        cap = cv2.VideoCapture(i)
        if cap.isOpened():
            logger.info(f"Camera found at index {i}")

            # Test different resolutions
            for width, height in [(640, 480), (1280, 720), (1920, 1080)]:
                cap.set(cv2.CAP_PROP_FRAME_WIDTH, width)
                cap.set(cv2.CAP_PROP_FRAME_HEIGHT, height)

                actual_width = cap.get(cv2.CAP_PROP_FRAME_WIDTH)
                actual_height = cap.get(cv2.CAP_PROP_FRAME_HEIGHT)

                logger.info(f"  Requested: {width}x{height}, Actual: {int(actual_width)}x{int(actual_height)}")

                ret, frame = cap.read()
                if ret:
                    logger.info(f"    Successfully captured frame at {int(actual_width)}x{int(actual_height)}")

                    # Show a frame briefly to verify
                    cv2.imshow('Test', frame)
                    cv2.waitKey(500)
                    cv2.destroyWindow('Test')
                else:
                    logger.info(f"    Failed to capture frame at {width}x{height}")

            cap.release()
        else:
            logger.info(f"No camera found at index {i}")

    logger.info("Camera testing complete.")

if __name__ == "__main__":
    # You can run in test mode or normal mode
    import sys

    if len(sys.argv) > 1 and sys.argv[1] == "test":
        test_camera_configurations()
    else:
        # Initialize camera
        cap = initialize_camera()

        if cap is not None:
            # Start the main ANPR loop
            run_webcam_anpr(cap)
        else:
            logger.error("Failed to initialize camera. Exiting.")