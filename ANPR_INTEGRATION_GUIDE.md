# ANPR → Laravel Integration Guide

## 📋 Overview
Sistem ANPR (Automatic Number Plate Recognition) terhubung dengan Laravel untuk mencatat data kendaraan masuk/keluar dengan otomatis berdasarkan nomor plat.

### Data Flow:
```
[Webcam 1] → [Python ANPR] → [Laravel API] → [MySQL Database]
  (Masuk)     (Detect Plate)    (anpr/result)   (incoming_cars)

[Webcam 2] → [Python ANPR] → [Laravel API] → [MySQL Database]
  (Keluar)    (Detect Plate)    (anpr/result)   (outgoing_cars)
```

---

## 🔧 Komponen yang Sudah Diimplementasikan

### 1. **Python ANPR Scripts**

#### `anpr_api_server.py`
- Flask server untuk menerima request ANPR dari external
- Endpoint: `POST /process_image?webcam_index=1&timestamp=xxx`
- Mengirim hasil ke Laravel dengan format:
  ```json
  {
    "plate": "BA3242CD",
    "webcam_index": 1,
    "timestamp": 1702480000.123,
    "image_base64": "..."
  }
  ```

#### `anpr_dual_cam.py`
- Real-time processing dari 2 webcam
- Webcam 1 (index=0) → `webcam_index=1` (masuk)
- Webcam 2 (index=1) → `webcam_index=2` (keluar)
- Format plat: uppercase, no spaces (contoh: `BA3242CD`)
- Debounce: 4 detik untuk avoid duplicate

### 2. **Laravel Backend**

#### `ANPRController@storeResult`
- Endpoint: `POST /api/anpr/result`
- Menerima: plate, webcam_index (1 atau 2), timestamp, image_base64
- Logic:
  - **webcam_index=1**: Create/Update `incoming_cars` record dengan `car_no` dan `datetime`
  - **webcam_index=2**: Find matching `incoming_cars` record, Create/Update `outgoing_cars` dengan `exit_time`, hitung biaya
- Response: Simple JSON `{"success": true, "message": "...", "car_no": "..."}`

#### Database Models & Migrations
- `IncomingCar`: car_no, datetime, image_path, status (in/out)
- `OutgoingCar`: car_no, entry_time, exit_time, total_time, total_hours, bill, image_path
- New migration: `2025_12_13_000000_update_incoming_cars_table.php`

#### API Route
```php
Route::post('/anpr/result', [ANPRController::class, 'storeResult']);
```

---

## 🚀 Cara Menggunakan

### Setup 1: Laravel Database Migration
```bash
cd IoT_Parkiran
php artisan migrate
```

Ini akan membuat/update:
- `incoming_cars` table (dengan kolom image_path, status)
- `outgoing_cars` table

### Setup 2: Python Environment
```bash
cd anpr-python
pip install -r requirements.txt
```

Pastikan `.env` file ada dengan konfigurasi:
```env
LARAVEL_API_URL=http://localhost:8000/api
ANPR_TOKEN=your_anpr_token_here
YOLO_MODEL_PATH=models/yolo/best.pt
PADDLE_OCR_DIR=models/ocr
```

### Run 3: Start Services

**Terminal 1 - Laravel Server:**
```bash
cd IoT_Parkiran
php artisan serve --host=0.0.0.0 --port=8000
```

**Terminal 2 - ANPR Dual Cam (Real-time dari webcam):**
```bash
cd anpr-python
python anpr_dual_cam.py
```

Atau **ANPR API Server (untuk external requests):**
```bash
cd anpr-python
python anpr_api_server.py
```

---

## ✅ Testing

### Test 1: Check Connectivity
```bash
curl http://localhost:8000/api/ping
```

Expected response:
```json
{
  "message": "Pong! Server is Alive",
  "time": "2025-12-13 14:30:45"
}
```

### Test 2: Test ANPR Integration
```bash
cd anpr-python
python test_integration.py
```

Script ini akan:
1. Check Laravel connectivity
2. Send 3 test plates ke Laravel API
3. Show response dari Laravel

### Test 3: Check Database
Using Laravel Tinker:
```bash
cd IoT_Parkiran
php artisan tinker
```

```php
// Check incoming cars
\App\Models\IncomingCar::latest()->get();

// Check outgoing cars
\App\Models\OutgoingCar::latest()->get();

// Check specific car
\App\Models\IncomingCar::where('car_no', 'BA3242CD')->get();
```

---

## 📊 Data Format Reference

### Incoming Car Record
```php
[
  'id' => 1,
  'car_no' => 'BA3242CD',
  'datetime' => '2025-12-13 14:30:45',
  'image_path' => 'plates/in_1702480000_BA3242CD.jpg',
  'status' => 'in',
  'created_at' => '2025-12-13 14:30:45',
  'updated_at' => '2025-12-13 14:30:45'
]
```

### Outgoing Car Record
```php
[
  'id' => 1,
  'car_no' => 'BA3242CD',
  'entry_time' => '2025-12-13 14:30:45',
  'exit_time' => '2025-12-13 14:35:30',
  'total_time' => '00:04:45',
  'total_hours' => 1,
  'bill' => 5000,
  'image_path' => 'plates/out_1702480500_BA3242CD.jpg',
  'created_at' => '2025-12-13 14:35:30',
  'updated_at' => '2025-12-13 14:35:30'
]
```

---

## 🔍 Troubleshooting

### Problem: "Connection refused"
**Solution:** Pastikan Laravel sudah running dengan `php artisan serve`

### Problem: Plat tidak terdeteksi
**Solution:** 
- Check model path di `.env` sesuai dengan file yang ada
- Test dengan `python webcam_capture.py` dulu
- Pastikan lighting dan angle camera optimal

### Problem: Image base64 terlalu besar
**Solution:** Compress image sebelum encode, atau kirim tanpa image (hanya plate text)

### Problem: Timestamp mismatch
**Solution:** Check timezone setting di Laravel (`config/app.php` → `'timezone' => 'UTC'`)

---

## 📝 API Endpoint Reference

### POST /api/anpr/result
**Request:**
```json
{
  "plate": "BA3242CD",
  "webcam_index": 1,
  "timestamp": 1702480000.123,
  "image_base64": "..."
}
```

**Response Success (201):**
```json
{
  "success": true,
  "message": "Incoming car registered",
  "car_no": "BA3242CD"
}
```

**Response Error (404):**
```json
{
  "success": false,
  "message": "No incoming car record found"
}
```

---

## 🎯 Next Steps (Optional)

1. **Add UI Dashboard**: Tampilkan incoming/outgoing cars di web
2. **Add Notification**: Kirim notifikasi ke admin saat ada kendaraan
3. **Add Image Storage**: Upload image ke cloud storage (S3, etc)
4. **Add Payment Gateway**: Integrasi sistem pembayaran untuk parking fee
5. **Add Mobile App**: Mobile app untuk admin monitoring

---

Last Updated: 2025-12-13
