# Sistem Parkir Pintar IoT - Dokumentasi Komprehensif

## Gambaran Umum Proyek

Sistem Parkir Pintar Berbasis IoT ini terdiri dari tiga komponen utama:
1. **Kontroler IoT ESP32** - Lapisan perangkat keras untuk sensor dan kontrol gerbang
2. **ANPR (Automatic Number Plate Recognition)** - Sistem berbasis Python untuk pengenalan plat nomor
3. **Backend Web Laravel** - Aplikasi web lengkap untuk manajemen data dan dashboard admin

## Arsitektur Sistem

### 1. Kontroler ESP32 (`esp-32/`)

#### File Utama:
- `esp32_parking_system.ino` - Firmware utama Arduino
- `api_config.h` - Header konfigurasi API

#### Fitur-fitur:
- **Konektivitas WiFi**: Terhubung ke jaringan lokal dengan SSID "Raflii" dan password "77777777"
- **Manajemen Sensor**: 
  - 4 sensor IR untuk deteksi slot parkir (pin 34, 35, 32, 33)
  - 2 sensor IR untuk deteksi gerbang masuk/keluar (pin 25, 26)
- **Override Manual**: 2 tombol untuk kontrol darurat gerbang (pin 27, 14)
- **Kontrol Aktuator**: 2 servo untuk gerbang masuk/keluar (pin 18, 19)
- **Sistem Tampilan**: 2 LCD I2C (alamat 0x26 untuk masuk, 0x27 untuk keluar)
- **Komunikasi API**: Pembaruan status real-time ke backend Laravel

#### Fungsi Utama:
- Monitor slot dengan pembaruan status real-time
- Kontrol gerbang berdasarkan perintah backend
- Pengelolaan tampilan LCD untuk ketersediaan parkir dan informasi tagihan
- Kapabilitas override manual untuk situasi darurat

### 2. Sistem ANPR (`anpr-python/`)

#### File Utama:
- `anpr_api_server.py` - Server API berbasis Flask
- `anpr_bisa.py` - Fungsionalitas inti pemrosesan dan OCR gambar
- `best.pt` - Model YOLO untuk deteksi plat nomor

#### Fitur-fitur:
- **Server API**: Layanan berbasis Flask berjalan di port 5000
- **Pra-pemrosesan Ganda**: 9 teknik peningkatan gambar berbeda
- **Deteksi Plat**: Model YOLO untuk lokasi plat yang akurat
- **Rekognisi OCR**: PaddleOCR dengan deteksi dan rekognisi
- **Penilaian Pola**: Khusus untuk format plat nomor Indonesia
- **Integrasi API**: Komunikasi dengan backend Laravel

#### Komponen Inti:
- **Pipa Pemrosesan Gambar**: Banyak teknik pra-pemrosesan untuk OCR optimal
- **Rekognisi Pola**: Sistem penilaian untuk format plat Indonesia
- **Pasca-pemrosesan**: Substitusi karakter dan pemformatan
- **Komunikasi API**: Otentikasi dan transmisi data yang aman

### 3. Backend Web Laravel (`IoT_Parkiran/`)

#### File Utama:
- `composer.json` - Manajemen dependensi PHP
- Controller API: ANPR, IoT, IncomingCar, OutgoingCar, ParkingSlot
- Model: IncomingCar, OutgoingCar, ParkingSlot, EspCommand
- Rute: api.php, web.php

#### Fitur-fitur:
- **Framework Laravel**: Versi 12 dengan arsitektur MVC penuh
- **Manajemen Database**: MySQL/MariaDB untuk semua data parkir
- **Endpoint API**: Untuk integrasi ESP32 dan ANPR
- **Dashboard Admin**: Monitor parkir real-time
- **Sistem Tagihan**: Perhitungan otomatis berdasarkan durasi parkir
- **Keamanan**: Otentikasi token Bearer

#### Endpoint API:
- `POST /api/iot-event` - Menerima pembaruan status sensor dari ESP32
- `GET /api/parking-info` - Dapatkan informasi ketersediaan slot parkir
- `POST /api/anpr/result` - Menerima hasil rekognisi plat nomor
- `GET /api/get-command` - Polling ESP32 untuk perintah gerbang
- `POST /api/incoming-car` - Merekam masuknya kendaraan
- `POST /api/outgoing-car` - Merekam keluarnya kendaraan dan tagihan

## Alur Data & Operasi

### Proses Masuk Kendaraan:
1. Sensor IR di gerbang masuk mendeteksi kendaraan
2. ESP32 meminta ketersediaan parkir dari backend
3. Jika slot tersedia, backend mengirim perintah "OPEN_GATE_ENTER"
4. Kamera ANPR mengambil dan memproses plat nomor
5. Backend mencatat masuk dengan timestamp
6. Gerbang terbuka dan kendaraan masuk

### Pemantauan Parkir:
1. Sensor IR di slot mendeteksi keberadaan kendaraan
2. ESP32 melaporkan perubahan status slot ke backend
3. Backend memperbarui database slot parkir
4. Dashboard mencerminkan status slot real-time

### Proses Keluar Kendaraan:
1. Sensor IR di gerbang keluar mendeteksi kendaraan
2. Kamera ANPR mengambil plat nomor keluar
3. Backend mencocokkan dengan catatan masuk dan menghitung durasi
4. Tagihan dihitung berdasarkan waktu parkir (Rp 5000 per jam)
5. Backend mengirim perintah "OPEN_GATE_EXIT" ke ESP32
6. Gerbang keluar terbuka dan kendaraan keluar

## Spesifikasi Teknis

### Kebutuhan Hardware:
- Mikrokontroler ESP32
- 6 sensor rintangan IR (4 slot parkir, 2 gerbang)
- 2 motor servo (gerbang masuk/keluar)
- 2 tampilan LCD (informasi masuk/keluar)
- 2 tombol manual override
- Konektivitas jaringan WiFi

### Kebutuhan Software:
- Python 3.11 (dengan OpenCV, PaddleOCR, Ultralytics, Flask)
- Laravel 12 (PHP 8.2+)
- Database MySQL/MariaDB
- Arduino IDE untuk pemrograman ESP32

### Konfigurasi Jaringan:
- Server backend: `http://10.218.100.27:8000/api`
- Server ANPR: `http://0.0.0.0:5000`
- ESP32 terhubung ke jaringan WiFi "Raflii" dengan password "77777777"

## Instruksi Setup

### Setup Backend:
1. Navigasi ke direktori `IoT_Parkiran/`
2. Jalankan `composer install`
3. Salin `.env.example` ke `.env` dan konfigurasi database
4. Jalankan `php artisan key:generate`
5. Jalankan migrasi database: `php artisan migrate`
6. Mulai server: `php artisan serve`

### Setup ANPR:
1. Instal paket Python yang diperlukan (OpenCV, PaddleOCR, Ultralytics, Flask)
2. Tempatkan file model YOLO `best.pt` di direktori `anpr-python/`
3. Jalankan `python anpr_api_server.py` untuk memulai server API

### Setup ESP32:
1. Instal dukungan papan ESP32 di Arduino IDE
2. Instal library yang diperlukan: WiFi, HTTPClient, ArduinoJson, ESP32Servo, Wire, LiquidCrystal_I2C
3. Upload kode `esp32_parking_system.ino` ke ESP32
4. Hubungkan komponen hardware sesuai spesifikasi

## Ringkasan Struktur File

```
IoT-ParkingSystem/
├── carakerja.txt                # Dokumen spesifikasi teknis
├── README.md                    # README dasar proyek
├── package.json                 # Dependensi frontend
├── esp-32/                      # Kode Arduino ESP32
│   ├── esp32_parking_system.ino # Firmware utama
│   ├── api_config.h             # Konfigurasi API
│   └── ...
├── anpr-python/                 # Sistem ANPR Python
│   ├── anpr_api_server.py       # Server API Flask
│   ├── anpr_bisa.py             # Pemrosesan inti
│   ├── best.pt                  # Model YOLO
│   └── ...
└── IoT_Parkiran/                # Backend Laravel
    ├── app/                     # Controller, Model
    ├── routes/                  # Rute API dan web
    ├── composer.json            # Dependensi PHP
    └── ...
```

## Fitur Keamanan
- Otentikasi token Bearer untuk komunikasi API
- Transmisi data aman antar komponen
- Keamanan tingkat database untuk informasi sensitif
- Saluran komunikasi terenkripsi

## Fitur Lanjutan
- Pemantauan slot parkir real-time
- Perhitungan tagihan otomatis
- Override manual untuk situasi darurat
- Tampilan LCD untuk informasi pengguna
- OCR multi-metode untuk akurasi yang lebih baik
- Rekognisi pola dioptimalkan untuk plat Indonesia
- Dashboard admin komprehensif

Sistem ini merupakan solusi parkir pintar berbasis IoT lengkap dengan integrasi ANPR, dirancang untuk manajemen parkir dan otomatisasi yang efisien.
