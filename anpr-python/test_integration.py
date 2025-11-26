#!/usr/bin/env python3
"""
Test script to verify the complete flow from ESP32 camera to Laravel database storage.

This script provides functions to:
1. Test the Python ANPR server
2. Test the Laravel API endpoint
3. Verify the complete flow

Before running this test:
1. Make sure your Laravel server is running (typically on http://localhost:8000)
2. Make sure the Python ANPR server is running (typically on http://localhost:5000)
3. Have an image file ready for testing ANPR functionality
"""

import requests
import base64
import json
import os
import sys

# Configuration
LARAVEL_API_URL = "http://localhost:8000/api/anpr/result"
PYTHON_ANPR_SERVER = "http://localhost:5000"
TEST_IMAGE_PATH = os.getenv('TEST_IMAGE_PATH', 'test_plate_image.jpg')

def test_laravel_api():
    """Test if the Laravel API is accessible"""
    print("\n--- Testing Laravel API ---")
    try:
        # Test with a simple request to verify endpoint is accessible
        payload = {
            'plate': 'TEST PLATE',
            'mode': 'entry',
            'image_base64': None
        }
        response = requests.post(LARAVEL_API_URL, json=payload, timeout=10)
        print(f"Laravel API test: Status {response.status_code}")
        if response.status_code == 200:
            print("✓ Laravel API is accessible")
            return True
        else:
            print(f"✗ Laravel API returned status {response.status_code}")
            print(f"Response: {response.text}")
            return False
    except Exception as e:
        print(f"✗ Laravel API test failed: {str(e)}")
        return False

def test_python_anpr_server():
    """Test if the Python ANPR server is accessible"""
    print("\n--- Testing Python ANPR Server ---")
    try:
        response = requests.get(f"{PYTHON_ANPR_SERVER}/health", timeout=10)
        print(f"Python ANPR Server test: Status {response.status_code}")
        if response.status_code == 200:
            health_data = response.json()
            print(f"✓ Python ANPR Server is accessible")
            print(f"Server status: {health_data}")
            return True
        else:
            print(f"✗ Python ANPR Server returned status {response.status_code}")
            print(f"Response: {response.text}")
            return False
    except Exception as e:
        print(f"✗ Python ANPR Server test failed: {str(e)}")
        return False

def test_anpr_processing(image_path):
    """Test the ANPR processing with a sample image"""
    print(f"\n--- Testing ANPR Processing with {image_path} ---")
    try:
        if not os.path.exists(image_path):
            print(f"✗ Test image not found: {image_path}")
            return False
            
        # Read and encode the image
        with open(image_path, 'rb') as image_file:
            image_data = image_file.read()
        
        # Send image to Python ANPR server
        response = requests.post(
            f"{PYTHON_ANPR_SERVER}/process_image",
            data=image_data,
            headers={'Content-Type': 'application/octet-stream'},
            timeout=30
        )
        
        if response.status_code == 200:
            result = response.json()
            print("✓ ANPR processing successful")
            print(f"Detected plate: {result.get('plate_number', 'None')}")
            print(f"Laravel API success: {result.get('laravel_api_success', False)}")
            return True
        else:
            print(f"✗ ANPR processing failed: Status {response.status_code}")
            print(f"Response: {response.text}")
            return False
    except Exception as e:
        print(f"✗ ANPR processing test failed: {str(e)}")
        return False

def run_complete_flow_test():
    """Run the complete flow test if a test image is provided"""
    print("\n--- Complete Flow Test ---")
    
    # Check if test image exists
    if not os.path.exists(TEST_IMAGE_PATH):
        print(f"Note: Test image not found at {TEST_IMAGE_PATH}")
        print("Please provide a license plate image to test the full flow.")
        print("You can set the TEST_IMAGE_PATH environment variable to point to your test image.")
        return False
    
    # Step 1: Test if both servers are accessible
    laravel_ok = test_laravel_api()
    python_ok = test_python_anpr_server()
    
    if not (laravel_ok and python_ok):
        print("\n✗ Cannot proceed with complete flow test - one or more services are not accessible")
        return False
    
    # Step 2: Test ANPR processing and Laravel storage
    anpr_ok = test_anpr_processing(TEST_IMAGE_PATH)
    
    if anpr_ok:
        print("\n✓ Complete flow test successful!")
        print("The system can now process images from ESP32, perform ANPR, and store results in Laravel.")
        return True
    else:
        print("\n✗ Complete flow test failed")
        return False

def print_setup_instructions():
    """Print setup instructions for the complete system"""
    print("=== IoT Parking System Integration Setup ===")
    print("\n1. ESP32 Setup:")
    print("   - Upload the OV_Fifo.h code to your ESP32-CAM")
    print("   - Update WiFi credentials in the code")
    print("   - Change serverUrl to point to your Python ANPR server IP")
    
    print("\n2. Python ANPR Server Setup:")
    print("   - Install requirements: pip install flask opencv-python ultralytics paddleocr")
    print("   - Run: python anpr_api_server.py")
    print("   - Make sure 'best.pt' YOLO model is in the same directory")
    
    print("\n3. Laravel Setup:")
    print("   - Make sure Laravel server is running: php artisan serve")
    print("   - Ensure the database is migrated: php artisan migrate")
    print("   - The ANPRController should be accessible at /api/anpr/result")
    
    print("\n4. Environment Variables (optional but recommended):")
    print("   - Set LARAVEL_API_URL=http://your-laravel-server:port/api/anpr/result")
    print("   - Set TEST_IMAGE_PATH=path/to/your/test/image.jpg")

if __name__ == "__main__":
    print("IoT Parking System - Complete Flow Test")
    
    if len(sys.argv) > 1 and sys.argv[1] == "--setup":
        print_setup_instructions()
    else:
        success = run_complete_flow_test()
        if not success:
            print("\nTo see setup instructions: python test_integration.py --setup")
            sys.exit(1)
        else:
            print("\n🎉 All tests passed! The system is properly integrated.")