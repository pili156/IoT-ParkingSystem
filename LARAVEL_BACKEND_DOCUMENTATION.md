# Dokumentasi Backend Laravel (`IoT_Parkiran/`)

## Gambaran Umum
Backend Laravel menyediakan manajemen data sentral dan layanan API untuk sistem parkir pintar. Ini menangani catatan masuk/keluar kendaraan, manajemen slot parkir, perhitungan tagihan, dan komunikasi antara perangkat keras ESP32 dan sistem ANPR.

## Komponen Utama

### Dependensi composer.json
```json
{
  "require": {
    "php": "^8.2",
    "laravel/framework": "^12.0",
    "laravel/sanctum": "^4.2",
    "laravel/tinker": "^2.10.1"
  }
}
```

## Model Database

### `ParkingSlot`
- **Tabel**: `parking_slots`
- **Field**: `id`, `slot_name`, `status` (Full/Empty), `updated_at`
- **Fungsi**: Melacak status real-time dari setiap slot parkir

### `IncomingCar`
- **Tabel**: `incoming_cars`
- **Field**: `id`, `car_no` (nomor plat), `datetime`, `image_path`, `status`
- **Fungsi**: Mencatat event masuk kendaraan dengan timestamp dan gambar

### `OutgoingCar`
- **Tabel**: `outgoing_cars`
- **Field**: `id`, `car_no`, `entry_time`, `exit_time`, `total_time`, `total_hours`, `bill`, `image_path`
- **Fungsi**: Mencatat event keluar kendaraan dengan durasi dan perhitungan tagihan

### `EspCommand`
- **Tabel**: `esp_commands`
- **Field**: `id`, `command`, `is_executed` (boolean), `bill` (decimal), `execution_result`, `consumed` (boolean)
- **Fungsi**: Menyimpan perintah untuk eksekusi ESP32 (operasi gerbang)

## Controller API

### `ANPRController`
Menangani integrasi sistem ANPR:

#### `storeResult(Request $r)`
- Menerima hasil rekognisi plat dari sistem Python ANPR
- Menangani mode masuk dan keluar berdasarkan parameter `mode`
- Memproses gambar dalam format base64 dan menyimpan ke penyimpanan
- **Logika Masuk**:
  - Membuat catatan di `incoming_cars`
  - Mengatur status ke 'in'
  - Membuat perintah `OPEN_GATE_ENTER` untuk ESP32
- **Logika Keluar**:
  - Menemukan catatan masuk yang cocok berdasarkan nomor plat
  - Menghitung durasi dan tagihan (Rp 5000 per jam, dibulatkan ke atas)
  - Membuat catatan di `outgoing_cars`
  - Memperbarui status catatan masuk ke 'out'
  - Membuat perintah `OPEN_GATE_EXIT` dengan info tagihan opsional

### `IoTController`
Menangani integrasi ESP32:

#### `event(Request $r)`
- Menerima event sensor dari ESP32 melalui `/api/iot-event`
- Memproses event `ARRIVAL` (kendaraan masuk slot) dan `DEPARTURE` (kendaraan keluar slot)
- Memperbarui status `parking_slots` berdasarkan tipe event
- Mengembalikan konfirmasi status

#### `getParkingInfo()`
- Menyediakan jumlah slot parkir yang tersedia melalui `/api/parking-info`
- Menghitung slot dengan status 'Empty'
- Mengembalikan JSON dengan slot gratis, total slot, dan pesan untuk tampilan LCD

### `API\IoTController` (Versi Lanjutan)
Komunikasi IoT lanjutan dengan manajemen antrian perintah:

#### `handleEvent(Request $request)`
- Validasi lanjutan untuk tipe event dan nama slot
- Penanganan khusus untuk event gerbang (GateEntry/GateExit)
- Untuk GateEntry: memeriksa slot yang tersedia sebelum mengizinkan masuk
- Untuk GateExit: memproses keluar kendaraan dan tagihan

#### `getCommand(Request $request)`
- Menyediakan antrian perintah untuk perangkat ESP32
- Mengembalikan perintah tertua yang belum dikonsumsi atau 'WAIT' jika tidak ada
- Menandai perintah sebagai dikonsumsi saat diambil
- Mendukung perintah khusus perangkat dengan `device_id`

#### `consumeCommand(Request $request)`
- Mengonfirmasi eksekusi perintah oleh ESP32
- Memperbarui status perintah untuk mencegah eksekusi duplikat
- Mencatat hasil eksekusi dan timestamp

## Controller Web

### `ParkingSlotController`
- Antarmuka web untuk monitoring slot parkir real-time
- Menampilkan status slot dalam format dashboard

### `IncomingCarController`
- Antarmuka web untuk catatan masuk kendaraan
- Menampilkan riwayat masuk dan kendaraan saat ini di tempat parkir

### `OutgoingCarController`
- Antarmuka web untuk catatan keluar kendaraan dan tagihan
- Menampilkan riwayat keluar, durasi, dan informasi tagihan

## Rute API (`routes/api.php`)

### Rute IoT ESP32
- `POST /api/iot-event` - Pembaruan status sensor dari ESP32
- `GET /api/parking-info` - Ketersediaan parkir untuk tampilan LCD
- `GET /api/get-command` - Polling perintah untuk ESP32 (versi lanjutan)

### Rute ANPR
- `POST /api/incoming-car` - Perekaman masuk kendaraan
- `POST /api/outgoing-car` - Keluar kendaraan dan tagihan
- `POST /api/anpr/result` - Hasil rekognisi plat ANPR

### Rute Utilitas
- `GET /api/ping` - Endpoint uji konektivitas

## Rute Web (`routes/web.php`)

### Halaman Dashboard
- `/` - Mengarahkan ke dashboard slot parkir
- `/parking-slot` - Monitoring slot real-time
- `/incoming-car` - Catatan masuk dan monitoring
- `/outgoing-car` - Catatan keluar dan informasi tagihan

## Sistem Tagihan
- **Tarif**: Rp 5000 per jam (dibulatkan ke atas)
- **Perhitungan**: Durasi dari waktu masuk ke waktu keluar
- **Pembulatan**: Fungsi ceil untuk membulatkan ke jam berikutnya
- **Penyimpanan**: Tagihan disimpan di field `outgoing_cars.bill`

## Fitur Keamanan
- Otentikasi token Sanctum API
- Validasi input untuk semua endpoint
- Konsumsi perintah untuk mencegah eksekusi duplikat
- Manajemen status untuk mencegah pemrosesan ganda

## Integrasi Alur Data
1. **ESP32 ke Backend**: Sensor IR melaporkan perubahan status melalui `/iot-event`
2. **ANPR ke Backend**: Hasil rekognisi plat melalui `/anpr/result`
3. **Backend ke ESP32**: Perintah gerbang melalui sistem antrian perintah
4. **Antarmuka Web**: Dashboard real-time menunjukkan semua data parkir

## Relasi Database
- `IncomingCar` dan `OutgoingCar` terhubung oleh `car_no` untuk pencocokan masuk/keluar
- Status `ParkingSlot` diperbarui berdasarkan event sensor
- Antrian `EspCommand` memastikan eksekusi perintah yang terurut

## Penanganan Kesalahan
- Validasi komprehensif untuk semua permintaan API
- Penanganan anggun untuk catatan masuk yang hilang
- Manajemen status untuk mencegah kesalahan pemrosesan
- Logging terperinci untuk debugging

Backend Laravel ini menyediakan fondasi yang robust dan skalabel untuk sistem parkir IoT dengan manajemen data komprehensif, perhitungan tagihan, dan kemampuan monitoring real-time.