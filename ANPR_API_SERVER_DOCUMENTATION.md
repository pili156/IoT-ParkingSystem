# Dokumentasi Server API ANPR (`anpr_api_server.py`)

## Gambaran Umum
Server API berbasis Flask ini menyediakan layanan Pengenalan Plat Nomor Otomatis, memproses gambar dari kamera untuk mengekstrak nomor plat dan mengkomunikasikan hasil ke backend Laravel. Sistem dioptimalkan khusus untuk pengenalan plat nomor Indonesia.

## Komponen Utama

### Import dan Setup
```python
from flask import Flask, request, jsonify
import cv2
import numpy as np
import logging
from anpr_bisa import setup_models, process_image
import requests
import os
import time
```

### Konfigurasi Global
```python
# Konfigurasi Endpoint API
LARAVEL_API_URL = os.getenv('LARAVEL_API_URL', 'http://localhost:8000/api')
ANPR_TOKEN = os.getenv('ANPR_TOKEN', 'your_anpr_token_here')
```

## Fungsi Inti

### `initialize_models()`
- Memuat model YOLO dan OCR saat startup aplikasi
- Mencegah pemuatan ulang untuk setiap permintaan demi efisiensi
- Menyiapkan logging untuk proses pemuatan model

### `process_camera_image(image_data)`
- Menerima data gambar biner dari kamera ESP32
- Mengkonversi data biner ke format OpenCV
- Memproses gambar menggunakan fungsionalitas ANPR
- Mengembalikan teks plat terdeteksi atau pesan kesalahan
- Menerapkan penanganan kesalahan komprehensif

### `process_image_from_array(img)`
- Versi modifikasi dari process_image untuk bekerja dengan array gambar
- Menjalankan deteksi YOLO untuk menemukan plat nomor dalam gambar
- Mengekstrak wilayah plat dan menerapkan teknik pra-pemrosesan ganda
- Menerapkan 9 teknik pra-pemrosesan yang berbeda:
  1. Threshold blur (blur median + threshold OTSU)
  2. Threshold adaptif
  3. Operasi morfologis untuk pembersihan
  4. Kombinasi dilasi dan erosi
  5. Threshold blur Gaussian
  6. Peningkatan kontras
  7. Filter bilateral untuk pengurangan noise
  8. CLAHE (Contrast Limited Adaptive Histogram Equalization)
  9. Peningkatan Laplacian

Untuk setiap teknik pra-pemrosesan:
- Menerapkan teknik ke gambar plat
- Menjalankan OCR menggunakan PaddleOCR
- Memproses hasil dengan ambang kepercayaan (>0.4)
- Menerapkan pembersihan teks dan pemformatan
- Menilai hasil menggunakan pencocokan pola

### Fungsi Rekognisi Pola

#### `calculate_plate_pattern_score(text)`
- Menilai teks terdeteksi berdasarkan pola plat nomor Indonesia
- Menangani huruf tunggal + angka + huruf (mis. B 1387 DKC)
- Menangani dua huruf + angka + huruf (mis. CC 1234 EF)
- Penilaian khusus untuk plat target: B 1387 DKC, B 1656 SPW, L 1389 DJ, K 141 KU
- Memberikan penalti pada format tidak mungkin berdasarkan panjang dan pola karakter
- Mengembalikan skor tertimbang menggabungkan kepercayaan OCR dan pencocokan pola

#### `post_process_license_plate(text)`
- Menerapkan koreksi kesalahan OCR untuk plat Indonesia
- Substitusi karakter: @→0, O→0, U→0, D→0, I→1, l→1, i→1, |→1, !→1, Z→2, S→5, G→6, dll.
- Pencocokan pola untuk memformat hasil dengan benar (mis. "B1387DKC" → "B 1387 DKC")
- Penanganan khusus untuk kebingungan umum dalam plat Indonesia
- Membersihkan dan memformat output sesuai format plat standar

### Komunikasi API

#### `send_to_laravel_api(plate_number, image_data=None, mode="entry")`
- Mengirim hasil ke backend Laravel dengan otentikasi yang benar
- Mengkonversi gambar ke base64 untuk transmisi
- Menyiapkan payload dengan nomor plat, mode (masuk/keluar), gambar, dan timestamp
- Menggunakan otentikasi token Bearer
- Menerapkan penanganan kesalahan dan logika retry

## Endpoint API

### `POST /process_image`
- Endpoint utama untuk pemrosesan ANPR
- Menerima data gambar dalam berbagai format (biner mentah, data form multipart)
- Mengembalikan respons JSON dengan status keberhasilan, nomor plat, dan metadata
- Menangani data kamera ESP32 dan unggahan file
- Menerapkan penanganan kesalahan dan logging komprehensif

Format respons:
```json
{
  "success": true/false,
  "message": "Pesan hasil pemrosesan",
  "data": {
    "plate_number": "nomor plat terdeteksi",
    "laravel_api_success": true/false,
    "mode": "entry/exit",
    "laravel_error": "pesan kesalahan jika panggilan API gagal"
  },
  "timestamp": timestamp
}
```

### `GET /health`
- Endpoint cek kesehatan untuk monitoring layanan
- Mengembalikan status sistem dan informasi pemuatan model
- Berguna untuk monitoring dan penemuan layanan

Format respons:
```json
{
  "success": true,
  "message": "Layanan ANPR sehat",
  "data": {
    "status": "sehat",
    "models_loaded": true/false,
    "token_configured": true/false
  },
  "timestamp": timestamp
}
```

## Fitur-fitur Utama

### Pemrosesan Multi-metode
- Menerapkan 9 teknik pra-pemrosesan gambar yang berbeda ke setiap wilayah plat
- Memilih hasil terbaik berdasarkan kepercayaan OCR dan pencocokan pola
- Memaksimalkan akurasi rekognisi dalam kondisi pencahayaan dan kualitas plat yang berbeda

### Optimisasi Pencocokan Pola
- Khusus disesuaikan untuk format plat nomor Indonesia
- Penanganan khusus untuk plat target yang diketahui
- Sistem penilaian pola memberikan bobot pada hasil berdasarkan kemungkinan format plat

### Integrasi API
- Komunikasi aman dengan backend Laravel menggunakan token Bearer
- Transmisi data gambar dengan pengkodean base64
- Penanganan kesalahan jaringan yang kuat

### Logging Komprehensif
- Logging terperinci untuk debugging dan monitoring
- Metrik kinerja untuk waktu pemrosesan
- Pelacakan kesalahan untuk perbaikan kualitas

### Ketahanan Kesalahan
- Penanganan anggun terhadap gambar rusak
- Pemrosesan fallback ketika metode utama gagal
- Validasi komprehensif input dan output

## Penggunaan
Server berjalan di port 5000 dan menerima permintaan POST ke `/process_image` dengan data gambar. Sistem dirancang untuk diintegrasikan dengan sistem kamera ESP32 dan alur kerja pemrosesan gambar mandiri.

Aplikasi Flask dikonfigurasi untuk penggunaan produksi dengan `debug=False` dan mendengarkan di semua antarmuka jaringan (`host="0.0.0.0"`).