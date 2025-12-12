# API Reference & Examples

## Endpoint: POST /api/anpr/result

### Base URL
```
http://localhost:8000/api/anpr/result
```

Ubah `localhost:8000` dengan IP & port Laravel Anda jika testing dari mesin lain.

---

## Example 1: Incoming Car (Webcam Index = 1)

### Request
```bash
curl -X POST http://localhost:8000/api/anpr/result \
  -H "Content-Type: application/json" \
  -d '{
    "plate": "BA 3242 CD",
    "webcam_index": 1,
    "timestamp": 1702480245.123
  }'
```

### Payload Breakdown
| Field | Type | Required | Example | Notes |
|-------|------|----------|---------|-------|
| `plate` | string | Yes | `BA 3242 CD` atau `BA3242CD` | Format akan di-normalize otomatis (uppercase, no spaces) |
| `webcam_index` | integer | Yes | `1` | 1 = masuk, 2 = keluar |
| `timestamp` | float | No | `1702480245.123` | Unix timestamp. Jika tidak dikirim, akan use server time |
| `image_base64` | string | No | `iVBORw0KGgo...` | Base64 encoded image. Optional |

### Success Response (201 Created)
```json
{
  "success": true,
  "message": "Incoming car registered",
  "car_no": "BA3242CD"
}
```

### When Updating Existing (200 OK)
Jika plate `BA3242CD` sudah ada di database dengan status `in`, akan UPDATE timestamp:
```json
{
  "success": true,
  "message": "Incoming car updated",
  "car_no": "BA3242CD"
}
```

### Database Record Created
```sql
SELECT * FROM incoming_cars WHERE car_no = 'BA3242CD';

-- Output:
-- id: 1
-- car_no: BA3242CD
-- datetime: 2025-12-13 14:30:45
-- image_path: plates/in_1702480245_BA3242CD.jpg (jika image_base64 dikirim)
-- status: in
-- created_at: 2025-12-13 14:30:45
-- updated_at: 2025-12-13 14:30:45
```

---

## Example 2: Outgoing Car (Webcam Index = 2)

### Request
```bash
curl -X POST http://localhost:8000/api/anpr/result \
  -H "Content-Type: application/json" \
  -d '{
    "plate": "BA3242CD",
    "webcam_index": 2,
    "timestamp": 1702480545.789
  }'
```

### Success Response (201 Created)
```json
{
  "success": true,
  "message": "Outgoing car registered",
  "car_no": "BA3242CD",
  "bill": 5000
}
```

### Database Records Created/Updated

**incoming_cars (UPDATE status):**
```sql
-- status updated from 'in' to 'out'
UPDATE incoming_cars SET status='out' WHERE car_no='BA3242CD';
```

**outgoing_cars (CREATE new):**
```sql
INSERT INTO outgoing_cars VALUES (
  id: 1,
  car_no: BA3242CD,
  entry_time: 2025-12-13 14:30:45,    -- dari incoming_cars.datetime
  exit_time: 2025-12-13 14:35:45,     -- timestamp dari request
  total_time: "00:05:00",              -- formatted duration
  total_hours: 1,                      -- ceil(300 seconds / 3600) = 1 jam
  bill: 5000,                          -- 1 jam × Rp 5000
  image_path: plates/out_1702480545_BA3242CD.jpg,
  created_at: 2025-12-13 14:35:45,
  updated_at: 2025-12-13 14:35:45
);
```

---

## Example 3: Error Case - Unknown Car (No Incoming Record)

### Request
```bash
curl -X POST http://localhost:8000/api/anpr/result \
  -H "Content-Type: application/json" \
  -d '{
    "plate": "XX9999YY",
    "webcam_index": 2,
    "timestamp": 1702480545.789
  }'
```

### Error Response (404 Not Found)
```json
{
  "success": false,
  "message": "No incoming car record found"
}
```

**Explanation**: Kendaraan dengan plat `XX9999YY` tidak pernah masuk, jadi tidak bisa diproses exit.

---

## Example 4: Complete Request with Image

### Request
```bash
curl -X POST http://localhost:8000/api/anpr/result \
  -H "Content-Type: application/json" \
  -d '{
    "plate": "BA3242CD",
    "webcam_index": 1,
    "timestamp": 1702480245.123,
    "image_base64": "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+P+/HgAFhAJ/wlseKgAAAABJRU5ErkJggg=="
  }'
```

### Notes
- `image_base64` optional, untuk simpan foto kendaraan
- Image akan disimpan ke storage: `storage/app/public/plates/in_TIMESTAMP_PLATNOMOR.jpg`
- Akses via: `http://localhost:8000/storage/plates/in_1702480245_BA3242CD.jpg`

---

## Python Request Examples

### Using anpr_dual_cam.py
Automatic sending:
```python
payload = {
    "plate": plate_text,
    "webcam_index": webcam_index,
    "timestamp": time.time()
}

response = requests.post(
    "http://localhost:8000/api/anpr/result",
    json=payload,
    headers={"Content-Type": "application/json"},
    timeout=10
)
```

### Using anpr_api_server.py
Flask server receiving image:
```bash
curl -X POST http://localhost:5000/process_image?webcam_index=1 \
  -H "Content-Type: image/jpeg" \
  --data-binary @photo.jpg
```

---

## Response Status Codes

| Code | Meaning | Scenario |
|------|---------|----------|
| 201 | Created | Incoming car registered (new) |
| 200 | OK | Incoming/Outgoing car updated |
| 404 | Not Found | Trying to process exit, but no matching entry found |
| 500 | Server Error | Exception occurred in processing |
| 400 | Bad Request | Invalid webcam_index or missing required fields |

---

## Bill Calculation Logic

### Formula
```
Duration (seconds) = exit_time - entry_time
Total Hours (rounded up) = CEIL(duration_seconds / 3600)
Bill = total_hours × 5000  // Rp 5000 per jam

Example:
- Entry: 14:30:45
- Exit: 14:35:45
- Duration: 5 minutes = 300 seconds
- Total Hours: CEIL(300/3600) = CEIL(0.083) = 1
- Bill: 1 × 5000 = Rp 5000
```

### Another Example
```
- Entry: 14:30:00
- Exit: 16:45:00
- Duration: 2 hours 15 minutes = 8100 seconds
- Total Hours: CEIL(8100/3600) = CEIL(2.25) = 3
- Bill: 3 × 5000 = Rp 15000
```

---

## Testing with Postman/Insomnia

### 1. Create New Environment Variable
```
LARAVEL_URL = http://localhost:8000/api
```

### 2. Create Test Request
- **Method**: POST
- **URL**: `{{LARAVEL_URL}}/anpr/result`
- **Headers**:
  ```
  Content-Type: application/json
  ```
- **Body** (raw JSON):
  ```json
  {
    "plate": "BA3242CD",
    "webcam_index": 1,
    "timestamp": {{$timestamp}}
  }
  ```

### 3. Send Test Sequence
1. Send Entry (webcam_index=1) → Should return 201
2. Send Exit (webcam_index=2, same plate) → Should return 201 with bill
3. Send Exit again (webcam_index=2, different plate) → Should return 404

---

## Debugging Tips

### 1. Enable Laravel Logging
Edit `config/logging.php`:
```php
'level' => env('LOG_LEVEL', 'debug'),
```

Then check: `storage/logs/laravel.log`

### 2. Monitor Python Logs
```python
import logging
logging.basicConfig(level=logging.DEBUG)
```

### 3. Check Database Directly
```sql
-- In MySQL
SELECT * FROM incoming_cars ORDER BY created_at DESC LIMIT 5;
SELECT * FROM outgoing_cars ORDER BY created_at DESC LIMIT 5;

-- Or in Laravel Tinker
\App\Models\IncomingCar::orderByDesc('created_at')->limit(5)->get();
\App\Models\OutgoingCar::orderByDesc('created_at')->limit(5)->get();
```

### 4. Test with cURL
```bash
# Test connectivity
curl http://localhost:8000/api/ping

# Test ANPR endpoint
curl -X POST http://localhost:8000/api/anpr/result \
  -H "Content-Type: application/json" \
  -d '{"plate":"TEST1234","webcam_index":1}'
```

---

## Rate Limiting

Currently, there's **no rate limiting** on the endpoint. If you want to add:

```php
// In routes/api.php
Route::post('/anpr/result', [ANPRController::class, 'storeResult'])
    ->middleware('throttle:60,1'); // 60 requests per minute
```

---

Last Updated: 2025-12-13
