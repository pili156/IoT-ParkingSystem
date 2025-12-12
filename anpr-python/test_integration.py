#!/usr/bin/env python3
"""
Test script untuk verify ANPR → Laravel integration
Mengirim test data ke Laravel API tanpa perlu camera
"""

import requests
import time
import json
from datetime import datetime

# Konfigurasi
LARAVEL_URL = "http://localhost:8000/api"  # Ubah ke IP laptop kamu
ANPR_ENDPOINT = f"{LARAVEL_URL}/anpr/result"

# Test data
TEST_PLATES = [
    {"plate": "BA1234CD", "webcam": 1, "label": "Incoming Car 1"},
    {"plate": "BA5678EF", "webcam": 1, "label": "Incoming Car 2"},
    {"plate": "BA1234CD", "webcam": 2, "label": "Outgoing Car (matching entry)"},
]

def test_anpr_api():
    """Test ANPR API endpoint"""
    print("="*60)
    print("Testing ANPR → Laravel Integration")
    print("="*60)
    print(f"Target: {ANPR_ENDPOINT}\n")

    for i, test in enumerate(TEST_PLATES, 1):
        plate = test["plate"]
        webcam = test["webcam"]
        label = test["label"]

        payload = {
            "plate": plate,
            "webcam_index": webcam,
            "timestamp": time.time()
        }
        # include slot_name for test scenario
        payload['slot_name'] = 'Slot-1'

        print(f"[Test {i}] {label}")
        print(f"  Plate: {plate}")
        print(f"  Webcam Index: {webcam}")
        print(f"  Payload: {json.dumps(payload, indent=2)}")

        try:
            response = requests.post(
                ANPR_ENDPOINT,
                json=payload,
                headers={"Content-Type": "application/json"},
                timeout=10
            )

            print(f"  Status: {response.status_code}")
            print(f"  Response: {response.json()}")
            
            if response.status_code in (200, 201):
                print("  ✓ SUCCESS")
            else:
                print("  ✗ FAILED")

        except requests.exceptions.ConnectionError:
            print(f"  ✗ CONNECTION ERROR - Laravel not responding")
            print(f"    Check if Laravel is running at {LARAVEL_URL}")
        except Exception as e:
            print(f"  ✗ ERROR: {e}")

        print()
        time.sleep(1)  # Delay antara test

    print("="*60)
    print("Test completed!")
    print("="*60)


def check_database():
    """Check data di database via Laravel API (jika ada endpoint)"""
    print("\n" + "="*60)
    print("Checking Database Records")
    print("="*60)

    # Coba ambil data incoming cars (jika endpoint ada)
    try:
        response = requests.get(f"{LARAVEL_URL}/incoming-car", timeout=10)
        if response.status_code == 200:
            cars = response.json()
            print(f"Incoming Cars: {len(cars)} records")
            for car in cars:
                print(f"  - {car}")
    except Exception as e:
        print(f"Could not fetch incoming cars: {e}")

    # Coba ambil data outgoing cars
    try:
        response = requests.get(f"{LARAVEL_URL}/outgoing-car", timeout=10)
        if response.status_code == 200:
            cars = response.json()
            print(f"Outgoing Cars: {len(cars)} records")
            for car in cars:
                print(f"  - {car}")
    except Exception as e:
        print(f"Could not fetch outgoing cars: {e}")

    print("="*60)


def check_connectivity():
    """Check Laravel API connectivity"""
    print("Checking Laravel API Connectivity...")
    try:
        response = requests.get(f"{LARAVEL_URL}/ping", timeout=5)
        if response.status_code == 200:
            print(f"✓ Laravel is running")
            print(f"  Response: {response.json()}")
            return True
        else:
            print(f"✗ Laravel responded with {response.status_code}")
            return False
    except requests.exceptions.ConnectionError:
        print(f"✗ Cannot connect to Laravel at {LARAVEL_URL}")
        print(f"  Make sure Laravel is running and accessible")
        return False
    except Exception as e:
        print(f"✗ Error checking connectivity: {e}")
        return False


if __name__ == "__main__":
    print("\n")
    
    # Check connectivity dulu
    if not check_connectivity():
        print("\nCannot proceed without Laravel connection!")
        exit(1)

    print()
    
    # Run tests
    test_anpr_api()
    
    # Check database
    # check_database()

    print("\nTIP: Use 'php artisan tinker' to check database records:")
    print("  > \\App\\Models\\IncomingCar::all()")
    print("  > \\App\\Models\\OutgoingCar::all()")
