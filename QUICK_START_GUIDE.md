# ⚡ QUICK START GUIDE

## 📋 Pre-flight Checklist

### Environment Setup
- [ ] PHP 8.1+ installed
- [ ] Python 3.8+ installed (or use Conda environment)
- [ ] MySQL/MariaDB running
- [ ] Laravel installed (`composer install` done)
- [ ] Python packages installed (`pip install -r requirements.txt`) *or* use the Conda environment below
- [ ] YOLO & OCR models exist in `anpr-python/models/`

**Using Conda (recommended):**
If you prefer Conda, create and activate the provided environment to install the dependencies reproducibly:

```bash
cd anpr-python
conda env create -f environment.yml
conda activate anpr
```

Then run the ANPR server:

```bash
python anpr_api_server.py
```

For systems with GPUs, follow PaddlePaddle and Ultralytics documentation to install GPU-enabled builds as needed.

### Configuration Files
- [ ] `IoT_Parkiran/.env` configured (DB credentials, etc)
- [ ] `anpr-python/.env` configured (LARAVEL_API_URL, ANPR_TOKEN)
- [ ] Model paths correct in `.env`

---

## 🚀 Start in 5 Steps

### Step 1: Database Migration
```bash
cd IoT_Parkiran
php artisan migrate
```

Output should show:
```
Migrated: 2025_12_13_000000_update_incoming_cars_table
```

### Step 2: Start Laravel Server
```bash
cd IoT_Parkiran
php artisan serve --host=0.0.0.0 --port=8000
```

Output should show:
```
Laravel development server started: http://127.0.0.1:8000
```

### Step 3: Test Connection
In new terminal:
```bash
curl http://localhost:8000/api/ping
```

Expected:
```json
{"message":"Pong! Server is Alive","time":"2025-12-13 14:30:45"}
```

### Step 4: Run ANPR Integration Test
```bash
cd anpr-python
python test_integration.py
```

This will send 3 test plates and show responses.

### Step 5: Start Live ANPR (with Webcam)
```bash
cd anpr-python
python anpr_dual_cam.py
```

Now point cameras at license plates and watch them get recorded!

---

## 📊 Verify Data

### In Laravel Tinker
```bash
cd IoT_Parkiran
php artisan tinker
```

Then:
```php
# Check incoming cars
\App\Models\IncomingCar::latest('created_at')->limit(10)->get();

# Check outgoing cars
\App\Models\OutgoingCar::latest('created_at')->limit(10)->get();

# Check specific car
\App\Models\IncomingCar::where('car_no', 'BA3242CD')->get();
\App\Models\OutgoingCar::where('car_no', 'BA3242CD')->get();
```

### In MySQL
```sql
USE parking_system;  -- (ubah dengan DB name Anda)
SELECT * FROM incoming_cars ORDER BY created_at DESC LIMIT 5;
SELECT * FROM outgoing_cars ORDER BY created_at DESC LIMIT 5;
```

---

## 🔧 Configuration Quick Reference

### LARAVEL_API_URL Setting
**Local Testing (same PC):**
```env
LARAVEL_API_URL=http://localhost:8000/api
```

**Network Testing (different PC):**
```env
LARAVEL_API_URL=http://192.168.1.100:8000/api
```
Replace `192.168.1.100` with actual laptop IP.

Find your IP:
```bash
# Windows
ipconfig | findstr IPv4

# Linux/Mac
ifconfig | grep inet
```

### Model Paths
Verify these files exist:
```
anpr-python/
├── models/
│   ├── yolo/
│   │   └── best.pt          ✓ Must exist
│   └── ocr/
│       ├── inference.pdmodel ✓ Must exist
│       ├── inference.pdiparams
│       └── inference.yml
```

If missing, download or train new models.

---

## 🐛 Troubleshooting Quick Fixes

### Problem: "Connection refused" to Laravel
**Check:**
1. Laravel server running? → `php artisan serve`
2. Correct URL? → Check `LARAVEL_API_URL` in `.env`
3. Firewall blocking? → Allow port 8000

**Solution:**
```bash
# Kill old Laravel process
lsof -ti:8000 | xargs kill -9

# Restart
php artisan serve --host=0.0.0.0 --port=8000
```

### Problem: "ModuleNotFoundError" in Python
**Solution:**
```bash
cd anpr-python
pip install -r requirements.txt
```

### Problem: Models not loading
**Check:**
```bash
ls -la anpr-python/models/yolo/best.pt
ls -la anpr-python/models/ocr/inference.pdmodel
```

If missing, download from your training source.

### Problem: Webcam not detected
**Check:**
```bash
python -c "import cv2; cap = cv2.VideoCapture(0); print(cap.isOpened())"
```

Should print `True`. If `False`, camera not accessible.

**Fix:**
- Verify camera physically connected
- Try different camera index (0, 1, 2...)
- Check device permissions (Linux)

### Problem: Plate not detected
**Possible causes:**
1. Poor lighting
2. Plate angle > 45 degrees
3. Model confidence too high → Adjust `YOLO_CONF_THRESH` in `.env`
4. OCR model not trained → Need better training data

**Test:**
```bash
cd anpr-python
python -c "
import cv2
from anpr_bisa import setup_models, process_image_from_array

models = setup_models()
img = cv2.imread('test_image.jpg')
plates = process_image_from_array(img, models[0], models[1])
print(plates)
"
```

---

## 📝 Log Locations

### Laravel Logs
```
IoT_Parkiran/storage/logs/laravel.log
```

### Python Logs
Printed to console by default. To save to file:
```bash
python anpr_dual_cam.py > anpr.log 2>&1 &
```

---

## 🎯 System Status Health Check

Create a simple health check:

**Python script:**
```python
import requests
import subprocess

# Check Laravel
try:
    r = requests.get('http://localhost:8000/api/ping', timeout=2)
    print(f"✓ Laravel: {r.status_code}")
except:
    print("✗ Laravel: Not responding")

# Check Models
from anpr_bisa import setup_models
yolo, ocr = setup_models()
if yolo:
    print("✓ YOLO: Loaded")
else:
    print("✗ YOLO: Failed")

if ocr:
    print("✓ OCR: Loaded")
else:
    print("✗ OCR: Failed")

# Check Database
try:
    from app import db  # Laravel connection
    db.connection().select(1)
    print("✓ Database: Connected")
except:
    print("✗ Database: Not responding")
```

---

## 📚 Documentation Files

- **[IMPLEMENTATION_SUMMARY.md](IMPLEMENTATION_SUMMARY.md)** - What changed & why
- **[ANPR_INTEGRATION_GUIDE.md](ANPR_INTEGRATION_GUIDE.md)** - Detailed implementation guide
- **[API_EXAMPLES.md](API_EXAMPLES.md)** - API requests/responses examples
- **[QUICK_START_GUIDE.md](QUICK_START_GUIDE.md)** - This file!

---

## 🆘 Support & Debug Commands

### See full debug info
```bash
# Python
python -c "
import cv2, torch
print('OpenCV:', cv2.__version__)
print('Torch:', torch.__version__)
print('CUDA:', torch.cuda.is_available())
"

# Laravel
php artisan env

# Database
php artisan tinker
> DB::connection()->getPDO()
```

### Full test sequence
```bash
# Terminal 1
cd IoT_Parkiran
php artisan serve

# Terminal 2
cd anpr-python
python test_integration.py

# Terminal 3
cd anpr-python
python anpr_dual_cam.py

# Terminal 4
cd IoT_Parkiran
php artisan tinker
# Then run tinker commands to view data
```

---

## 🚨 Common Mistakes

❌ **Don't:**
- Run `python` from Windows cmd with spaces in path
- Forget to run `php artisan migrate` before starting
- Use wrong `LARAVEL_API_URL` (localhost vs actual IP)
- Mix up webcam_index (must be 1 or 2, not 0 or 3)
- Deploy without testing with `test_integration.py` first

✅ **Do:**
- Use full paths in config
- Test each component separately first
- Check logs when things fail
- Restart services after config changes
- Keep models updated & models folder clean

---

## 📞 Still Stuck?

1. Check **ANPR_INTEGRATION_GUIDE.md** → Troubleshooting section
2. Review logs: `IoT_Parkiran/storage/logs/laravel.log`
3. Test connectivity: `curl http://localhost:8000/api/ping`
4. Test ANPR: `python test_integration.py`
5. Check database: `php artisan tinker` → `\App\Models\IncomingCar::all()`

---

**Last Updated:** 2025-12-13  
**Version:** 1.0  
**Status:** Production Ready ✅
