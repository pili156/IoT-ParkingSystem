# IMPLEMENTASI SELESAI ✅

## 📌 Ringkasan Perubahan

### Problem Statement
Menghubungkan ANPR (Automatic Number Plate Recognition) dengan Laravel backend untuk:
- Menyimpan data kendaraan masuk (webcam 1) → tabel `incoming_cars`
- Menyimpan data kendaraan keluar (webcam 2) → tabel `outgoing_cars`
- Menghitung durasi parkir dan biaya otomatis
- Update kolom `datetime` / `exit_time` dengan timestamp yang akurat

---

## ✅ Perubahan yang Dilakukan

### 1. **Laravel Backend**

#### File: `app/Http/Controllers/ANPRController.php`
**Perubahan:**
- Refactor controller untuk handle `webcam_index` (1=masuk, 2=keluar) bukan `mode`
- Implementasi `handleIncomingCar()` - create/update incoming_cars dengan status tracking
- Implementasi `handleOutgoingCar()` - find matching entry, create outgoing_cars, calculate bill
- Response: Simple JSON `{"success": true, "message": "...", "car_no": "..."}`
- Trigger ESP32 commands untuk buka gerbang otomatis

**Key Features:**
- Format plat: uppercase, no spaces (`BA3242CD`)
- Support untuk update existing record (prevent duplicate)
- Timestamp dari client (Linux timestamp)
- Bill calculation: Rp 5000/jam (dibulatkan ke atas)

#### File: `routes/api.php`
**Perubahan:**
- Tambah route: `POST /api/anpr/result` → `ANPRController@storeResult`
- Import `ANPRController`

#### File: `app/Models/IncomingCar.php`
**Perubahan:**
- Tambah field ke `$fillable`: `status`

#### File: `database/migrations/2025_12_13_000000_update_incoming_cars_table.php` (BARU)
**Perubahan:**
- Tambah kolom `image_path` (string, nullable)
- Tambah kolom `status` (string, default='in')

---

### 2. **Python ANPR Scripts**

#### File: `anpr_api_server.py`
**Perubahan:**
- Update `send_to_laravel_api()` function signature:
  - Parameter: `plate_number`, `webcam_index=1`, `image_bytes=None`, `timestamp=None`
  - Payload: `{"plate": "...", "webcam_index": 1, "timestamp": xxx, "image_base64": "..."}`
- Update `/process_image` endpoint:
  - Accept query param: `?webcam_index=1&timestamp=xxx`
  - Format plat: uppercase, no spaces
  - Send ke Laravel dengan webcam_index

**Key Features:**
- Support image encode as base64 (optional, gambar tidak wajib disimpan lokal)
- Timeout: 30 detik
- Error handling lengkap dengan logging

#### File: `anpr_dual_cam.py`
**Perubahan (MAJOR REWRITE):**
- Update `extract_plate()` - return plate dengan format uppercase, no spaces
- Update `send_to_laravel()` function:
  - Parameter: `plate_text`, `webcam_index`, `frame=None`
  - Payload: `{"plate": "...", "webcam_index": 1, "timestamp": xxx, "image_base64": "..."}`
  - Include frame image as base64 (optional)
- Update main loop:
  - Webcam 0 (Camera 1) → `webcam_index=1` (entry)
  - Webcam 1 (Camera 2) → `webcam_index=2` (exit)
  - Debounce: 4 detik per plate
  - Proper logging dengan logger module
- Add error handling dan graceful shutdown

**Key Features:**
- Real-time dual camera processing
- Automatic ESP32 gate control
- Detailed logging untuk debugging

#### File: `.env` (UPDATED)
**Perubahan:**
- Add semua konfigurasi lengkap
- Add comments untuk guidance
- Add example values

---

### 3. **Testing & Documentation**

#### File: `test_integration.py` (BARU)
**Fungsi:**
- Test connectivity ke Laravel API
- Send 3 test plates (2 incoming, 1 outgoing matching)
- Show response dari Laravel
- Instructions untuk check database via Tinker

**Usage:**
```bash
python test_integration.py
```

#### File: `ANPR_INTEGRATION_GUIDE.md` (BARU)
**Konten:**
- Overview sistem & data flow diagram
- Component breakdown (Python + Laravel)
- Setup instructions (migration, pip install)
- How to run services
- Testing checklist (3 test levels)
- Troubleshooting guide
- API reference
- Next steps

---

## 🔄 Data Flow yang Sudah Terimplementasi

### Skenario 1: Kendaraan Masuk
```
[Webcam 1] 
    ↓ detect plate "BA3242CD"
[anpr_dual_cam.py]
    ↓ send to Laravel
[POST /api/anpr/result]
    ├─ plate: "BA3242CD"
    ├─ webcam_index: 1
    └─ timestamp: 1702480000.123
    ↓
[ANPRController::handleIncomingCar()]
    ├─ Check existing record (status='in')
    ├─ If exist: UPDATE dengan timestamp terbaru
    └─ If not: CREATE new record
    ↓
[incoming_cars table]
    ├─ car_no: "BA3242CD"
    ├─ datetime: 2025-12-13 14:30:45
    ├─ image_path: "plates/in_1702480000_BA3242CD.jpg"
    ├─ status: "in"
    └─ created_at/updated_at: auto
    ↓
[Response]
{
  "success": true,
  "message": "Incoming car registered",
  "car_no": "BA3242CD"
}
```

### Skenario 2: Kendaraan Keluar
```
[Webcam 2]
    ↓ detect plate "BA3242CD" (same as entry)
[anpr_dual_cam.py]
    ↓ send to Laravel
[POST /api/anpr/result]
    ├─ plate: "BA3242CD"
    ├─ webcam_index: 2
    └─ timestamp: 1702480545.789
    ↓
[ANPRController::handleOutgoingCar()]
    ├─ Find matching incoming_cars record (status='in')
    ├─ Calculate: entry_time = 14:30:45, exit_time = 14:35:45
    ├─ Duration: 00:05:00 (formatted), 5 minutes (seconds)
    ├─ Total Hours: ceil(300/3600) = 1 jam
    ├─ Bill: 1 × 5000 = 5000
    ├─ Check existing outgoing_cars record
    ├─ If exist: UPDATE dengan exit_time & bill
    └─ If not: CREATE new record
    ↓
[outgoing_cars table]
    ├─ car_no: "BA3242CD"
    ├─ entry_time: 2025-12-13 14:30:45
    ├─ exit_time: 2025-12-13 14:35:45
    ├─ total_time: "00:05:00"
    ├─ total_hours: 1
    ├─ bill: 5000
    ├─ image_path: "plates/out_1702480545_BA3242CD.jpg"
    └─ created_at/updated_at: auto
    ↓
[incoming_cars table - UPDATE]
    └─ status: "out" (mark as processed)
    ↓
[ESP32 Command]
    ├─ Create record: command='OPEN_GATE_EXIT'
    └─ ESP32 akan baca dan buka gerbang
    ↓
[Response]
{
  "success": true,
  "message": "Outgoing car registered",
  "car_no": "BA3242CD",
  "bill": 5000
}
```

---

## 📋 Checklist Pre-Deployment

- [x] ANPRController implemented & tested
- [x] Routes updated (api.php)
- [x] Database models updated (IncomingCar)
- [x] Migration created (add image_path, status)
- [x] anpr_api_server.py updated
- [x] anpr_dual_cam.py updated (major rewrite)
- [x] .env configured
- [x] Test script created
- [x] Integration guide written

---

## 🚀 Next Steps untuk User

1. **Run Migration:**
   ```bash
   cd IoT_Parkiran
   php artisan migrate
   ```

2. **Update .env** di `anpr-python/.env`:
   - Change `LARAVEL_API_URL` sesuai IP laptop
   - Verify model paths ada

3. **Test Connection:**
   ```bash
   cd anpr-python
   python test_integration.py
   ```

4. **Start Services:**
   - Terminal 1: `cd IoT_Parkiran && php artisan serve`
   - Terminal 2: `cd anpr-python && python anpr_dual_cam.py`

5. **Monitor Database:**
   ```bash
   php artisan tinker
   > \App\Models\IncomingCar::latest(5)->get()
   > \App\Models\OutgoingCar::latest(5)->get()
   ```

---

## ⚠️ Important Notes

1. **Gambar tidak disimpan lokal** - hanya metadata nomor plat dikirim ke database
2. **Timestamp precision** - gunakan Unix timestamp dari Python untuk accuracy
3. **Debounce 4 detik** - prevent duplicate detection dari plat yang sama
4. **Format plat** - otomatis convert ke uppercase & remove spaces
5. **Bill calculation** - dibulatkan ke atas (ceil) per jam penuh
6. **Status tracking** - incoming_cars status berubah in→out setelah keluar
7. **Update vs Create** - jika plate sudah ada, akan UPDATE bukan CREATE

---

## 🔗 File Reference

| File | Type | Status |
|------|------|--------|
| `app/Http/Controllers/ANPRController.php` | Modified | ✅ Complete |
| `routes/api.php` | Modified | ✅ Complete |
| `app/Models/IncomingCar.php` | Modified | ✅ Complete |
| `database/migrations/2025_12_13_000000_update_incoming_cars_table.php` | New | ✅ Complete |
| `anpr-python/anpr_api_server.py` | Modified | ✅ Complete |
| `anpr-python/anpr_dual_cam.py` | Modified | ✅ Complete |
| `anpr-python/.env` | Modified | ✅ Complete |
| `anpr-python/test_integration.py` | New | ✅ Complete |
| `ANPR_INTEGRATION_GUIDE.md` | New | ✅ Complete |

---

**Status**: ✅ IMPLEMENTASI SELESAI
**Date**: 2025-12-13
**Version**: 1.0
