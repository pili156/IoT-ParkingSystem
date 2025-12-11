import cv2
import requests
import numpy as np
import time
import logging
import threading
from queue import Queue
import os

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Configuration constants
CAMERA_INDEX_1 = 0  # Kamera masuk
CAMERA_INDEX_2 = 1  # Kamera keluar
CAMERA_RESOLUTION = (1280, 720)  # 720p resolution, optimal for ANPR
CAMERA_FPS = 15  # Frame rate untuk menghindari beban berlebihan
ANPR_SERVER_URL = "http://localhost:5000/process_image"  # Default ANPR server URL

class DualCameraANPR:
    def __init__(self, camera_index_1=CAMERA_INDEX_1, camera_index_2=CAMERA_INDEX_2, resolution=CAMERA_RESOLUTION, fps=CAMERA_FPS):
        self.camera_index_1 = camera_index_1
        self.camera_index_2 = camera_index_2
        self.resolution = resolution
        self.fps = fps
        
        # Setup cameras
        self.cap1 = None
        self.cap2 = None
        self.running = False
        
        # Thread and queues
        self.frame_queue_1 = Queue(maxsize=2)  # Limit queue size to prevent memory issues
        self.frame_queue_2 = Queue(maxsize=2)
        self.display_queue_1 = Queue(maxsize=1)  # For display
        self.display_queue_2 = Queue(maxsize=1)
        
        # Detection results
        self.last_plate_1 = None
        self.last_plate_2 = None
        self.last_time_1 = 0
        self.last_time_2 = 0
        
        # Capture intervals (in seconds) to avoid overwhelming the server
        self.capture_interval = 1.0  # 1 second between captures per camera
        
    def initialize_cameras(self):
        """Initialize both cameras with optimal settings"""
        # Initialize camera 1 (Entry)
        try:
            self.cap1 = cv2.VideoCapture(self.camera_index_1)
            if self.cap1.isOpened():
                # Set camera properties
                self.cap1.set(cv2.CAP_PROP_FRAME_WIDTH, self.resolution[0])
                self.cap1.set(cv2.CAP_PROP_FRAME_HEIGHT, self.resolution[1])
                self.cap1.set(cv2.CAP_PROP_FPS, self.fps)
                self.cap1.set(cv2.CAP_PROP_BUFFERSIZE, 1)
                
                # Try to set MJPEG format if available
                try:
                    self.cap1.set(cv2.CAP_PROP_FOURCC, cv2.VideoWriter_fourcc('M', 'J', 'P', 'G'))
                except:
                    pass  # Format tidak didukung, lanjutkan dengan default
                
                logger.info(f"Kamera masuk (Index {self.camera_index_1}) berhasil diinisialisasi")
            else:
                logger.warning(f"Kamera masuk (Index {self.camera_index_1}) tidak dapat diakses")
                self.cap1 = None
        except Exception as e:
            logger.error(f"Error saat menginisialisasi kamera masuk: {e}")
            self.cap1 = None
        
        # Initialize camera 2 (Exit)
        try:
            self.cap2 = cv2.VideoCapture(self.camera_index_2)
            if self.cap2.isOpened():
                # Set camera properties
                self.cap2.set(cv2.CAP_PROP_FRAME_WIDTH, self.resolution[0])
                self.cap2.set(cv2.CAP_PROP_FRAME_HEIGHT, self.resolution[1])
                self.cap2.set(cv2.CAP_PROP_FPS, self.fps)
                self.cap2.set(cv2.CAP_PROP_BUFFERSIZE, 1)
                
                # Try to set MJPEG format if available
                try:
                    self.cap2.set(cv2.CAP_PROP_FOURCC, cv2.VideoWriter_fourcc('M', 'J', 'P', 'G'))
                except:
                    pass  # Format tidak didukung, lanjutkan dengan default
                
                logger.info(f"Kamera keluar (Index {self.camera_index_2}) berhasil diinisialisasi")
            else:
                logger.warning(f"Kamera keluar (Index {self.camera_index_2}) tidak dapat diakses")
                self.cap2 = None
        except Exception as e:
            logger.error(f"Error saat menginisialisasi kamera keluar: {e}")
            self.cap2 = None
        
        if self.cap1 is None and self.cap2 is None:
            logger.error("Tidak ada kamera yang berhasil diinisialisasi")
            return False
        
        return True
    
    def capture_frames_thread(self):
        """Thread untuk menangkap frame dari kamera"""
        last_capture_time_1 = time.time()
        last_capture_time_2 = time.time()
        
        while self.running:
            current_time = time.time()
            
            # Capture from camera 1 (entry) if available
            if self.cap1 is not None:
                ret1, frame1 = self.cap1.read()
                if ret1:
                    try:
                        # Update display queue for camera 1
                        if not self.display_queue_1.full():
                            self.display_queue_1.put((frame1.copy(), "entry"))
                        
                        # Send to processing queue if not full and interval passed
                        if (not self.frame_queue_1.full() and 
                            current_time - last_capture_time_1 >= self.capture_interval):
                            self.frame_queue_1.put(frame1.copy())
                            last_capture_time_1 = current_time
                    except:
                        pass  # Skip if queues are full or error occurs
            
            # Capture from camera 2 (exit) if available
            if self.cap2 is not None:
                ret2, frame2 = self.cap2.read()
                if ret2:
                    try:
                        # Update display queue for camera 2
                        if not self.display_queue_2.full():
                            self.display_queue_2.put((frame2.copy(), "exit"))
                        
                        # Send to processing queue if not full and interval passed
                        if (not self.frame_queue_2.full() and 
                            current_time - last_capture_time_2 >= self.capture_interval):
                            self.frame_queue_2.put(frame2.copy())
                            last_capture_time_2 = current_time
                    except:
                        pass  # Skip if queues are full or error occurs
            
            time.sleep(0.03)  # ~30 FPS capture rate
    
    def process_anpr_thread(self, frame_queue, camera_name, last_plate_var, last_time_var):
        """Thread untuk memproses ANPR dari antrian frame"""
        while self.running:
            if not frame_queue.empty():
                try:
                    frame = frame_queue.get(timeout=0.1)
                    
                    # Process frame via API
                    plate_number, success = self.send_frame_to_anpr(frame, camera_name)
                    
                    if success and plate_number:
                        # Update last results based on camera
                        if camera_name == "entry":
                            self.last_plate_1 = plate_number
                            self.last_time_1 = time.time()
                            logger.info(f"PLAT TERDETEKSI [MASUK]: {plate_number}")
                        else:  # exit
                            self.last_plate_2 = plate_number
                            self.last_time_2 = time.time()
                            logger.info(f"PLAT TERDETEKSI [KELUAR]: {plate_number}")
                    elif not success:
                        logger.warning(f"Gagal memproses frame dari kamera {camera_name}")
                        
                except Exception as e:
                    logger.error(f"Error dalam proses ANPR {camera_name}: {e}")
                    time.sleep(0.1)
            else:
                time.sleep(0.1)  # Sleep if queue is empty
    
    def send_frame_to_anpr(self, frame, camera_name):
        """Send a frame to the ANPR server for processing"""
        try:
            # Encode frame as JPEG with high quality
            encode_param = [int(cv2.IMWRITE_JPEG_QUALITY), 90]
            _, img_encoded = cv2.imencode('.jpg', frame, encode_param)
            
            # Send image to ANPR server
            response = requests.post(
                ANPR_SERVER_URL,
                data=img_encoded.tobytes(),
                headers={'Content-Type': 'application/octet-stream'},
                timeout=10  # 10 second timeout
            )
            
            if response.status_code == 200:
                result = response.json()
                
                # Extract plate number if available
                plate_number = None
                if result.get('success') and result.get('data', {}).get('plate_number'):
                    plate_number = result['data']['plate_number']
                
                return plate_number, True
            else:
                logger.error(f"Server returned status {response.status_code}: {response.text}")
                return None, False
                
        except requests.exceptions.RequestException as e:
            logger.error(f"Error sending frame to ANPR server: {e}")
            return None, False
        except Exception as e:
            logger.error(f"Unexpected error in send_frame_to_anpr: {e}")
            return None, False
    
    def display_thread(self):
        """Thread untuk menampilkan hasil dari kedua kamera"""
        cv2.namedWindow('Kamera Masuk (Gerbang 1)', cv2.WINDOW_AUTOSIZE)
        cv2.namedWindow('Kamera Keluar (Gerbang 2)', cv2.WINDOW_AUTOSIZE)
        
        while self.running:
            # Display camera 1 feed
            if not self.display_queue_1.empty():
                try:
                    frame1, _ = self.display_queue_1.get(timeout=0.1)
                    
                    # Add text overlay
                    h, w = frame1.shape[:2]
                    # Add camera label
                    cv2.putText(frame1, 'KAMERA MASUK', (10, 30), cv2.FONT_HERSHEY_SIMPLEX, 1, (0, 255, 0), 2)
                    # Add last detected plate
                    if self.last_plate_1:
                        elapsed = time.time() - self.last_time_1
                        cv2.putText(frame1, f'PLAT: {self.last_plate_1}', (10, h-60), cv2.FONT_HERSHEY_SIMPLEX, 1, (0, 255, 0), 2)
                        cv2.putText(frame1, f'Waktu: {elapsed:.1f}s lalu', (10, h-30), cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 255, 0), 1)
                    
                    cv2.imshow('Kamera Masuk (Gerbang 1)', frame1)
                except:
                    pass  # Skip if no frame available
            
            # Display camera 2 feed
            if not self.display_queue_2.empty():
                try:
                    frame2, _ = self.display_queue_2.get(timeout=0.1)
                    
                    # Add text overlay
                    h, w = frame2.shape[:2]
                    # Add camera label
                    cv2.putText(frame2, 'KAMERA KELUAR', (10, 30), cv2.FONT_HERSHEY_SIMPLEX, 1, (0, 255, 255), 2)
                    # Add last detected plate
                    if self.last_plate_2:
                        elapsed = time.time() - self.last_time_2
                        cv2.putText(frame2, f'PLAT: {self.last_plate_2}', (10, h-60), cv2.FONT_HERSHEY_SIMPLEX, 1, (0, 255, 255), 2)
                        cv2.putText(frame2, f'Waktu: {elapsed:.1f}s lalu', (10, h-30), cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 255, 255), 1)
                    
                    cv2.imshow('Kamera Keluar (Gerbang 2)', frame2)
                except:
                    pass  # Skip if no frame available
            
            # Check for quit key
            key = cv2.waitKey(1) & 0xFF
            if key == ord('q'):
                self.running = False
                break
            
            time.sleep(0.03)  # Display at ~30 FPS
        
        cv2.destroyAllWindows()
    
    def start(self):
        """Start the dual camera ANPR system"""
        if not self.initialize_cameras():
            logger.error("Gagal menginisialisasi kamera. Sistem tidak dapat dijalankan.")
            return
        
        self.running = True
        logger.info("Memulai sistem dual camera ANPR. Tekan 'q' untuk berhenti.")
        
        # Start capture thread
        capture_thread = threading.Thread(target=self.capture_frames_thread, daemon=True)
        capture_thread.start()
        
        # Start ANPR processing threads
        anpr_thread_1 = threading.Thread(target=self.process_anpr_thread, 
                                        args=(self.frame_queue_1, "entry", 
                                              self.last_plate_1, self.last_time_1), 
                                        daemon=True)
        anpr_thread_1.start()
        
        anpr_thread_2 = threading.Thread(target=self.process_anpr_thread, 
                                        args=(self.frame_queue_2, "exit", 
                                              self.last_plate_2, self.last_time_2), 
                                        daemon=True)
        anpr_thread_2.start()
        
        # Start display thread (this will block until 'q' is pressed)
        self.display_thread()
        
        # Stop all threads
        self.running = False
        logger.info("Sistem dual camera ANPR dihentikan.")
    
    def stop(self):
        """Stop the dual camera ANPR system"""
        self.running = False
        if self.cap1:
            self.cap1.release()
        if self.cap2:
            self.cap2.release()
        cv2.destroyAllWindows()

def test_camera_availability():
    """Test ketersediaan kamera"""
    logger.info("Menguji ketersediaan kamera...")
    
    available_cameras = []
    for i in range(4):  # Test indices 0-3
        cap = cv2.VideoCapture(i)
        if cap.isOpened():
            ret, frame = cap.read()
            if ret:
                available_cameras.append(i)
                logger.info(f"Kamera ditemukan di index {i} - Resolusi: {frame.shape[1]}x{frame.shape[0]}")
            cap.release()
        else:
            logger.info(f"Tidak ada kamera di index {i}")
    
    if len(available_cameras) < 1:
        logger.warning("Tidak ditemukan kamera yang tersedia")
    else:
        logger.info(f"Kamera yang tersedia: {available_cameras}")
    
    return available_cameras

if __name__ == "__main__":
    # Parse command line arguments for camera indices
    import sys
    
    if len(sys.argv) > 1 and sys.argv[1] == "test":
        test_camera_availability()
    elif len(sys.argv) >= 3:
        # Use specified camera indices
        camera_idx_1 = int(sys.argv[1])
        camera_idx_2 = int(sys.argv[2])
        logger.info(f"Menggunakan kamera masuk: {camera_idx_1}, kamera keluar: {camera_idx_2}")
        dual_camera = DualCameraANPR(camera_index_1=camera_idx_1, camera_index_2=camera_idx_2)
        dual_camera.start()
    else:
        # Use default indices (0 and 1)
        dual_camera = DualCameraANPR()
        dual_camera.start()