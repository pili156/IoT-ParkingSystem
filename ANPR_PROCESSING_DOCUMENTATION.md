# Dokumentasi Logika Pemrosesan ANPR (`anpr_bisa.py`)

## Gambaran Umum
Modul ini berisi fungsionalitas pengenalan plat nomor inti menggunakan YOLO untuk deteksi dan PaddleOCR untuk rekognisi teks. Menerapkan teknik pra-pemrosesan, pencocokan pola, dan pasca-pemrosesan yang canggih secara khusus dioptimalkan untuk plat nomor Indonesia.

## Komponen Utama

### Import dan Setup
```python
import os
import json
import cv2
from glob import glob
from ultralytics import YOLO
from paddleocr import PaddleOCR
import logging
```

### `setup_models()`
- Memuat model YOLO dari file `best.pt` untuk deteksi plat nomor
- Menginisialisasi PaddleOCR dengan dukungan bahasa Inggris
- Mengkonfigurasi OCR untuk menggunakan klasifikasi sudut untuk akurasi yang lebih baik
- Mengembalikan kedua model untuk digunakan dalam pemrosesan gambar

## Fungsi Pemrosesan Utama

### `process_image(image_path, yolo_model, ocr_model)`
Ini adalah fungsi pemrosesan inti yang menangani seluruh gambar:

1. **Pemuatan Gambar**: Memuat gambar menggunakan OpenCV
2. **Deteksi YOLO**: Menemukan plat nomor dengan ambang kepercayaan 0.5
3. **Ekstraksi Wilayah**: Memotong wilayah plat nomor terdeteksi
4. **Pra-pemrosesan**: Menerapkan teknik peningkatan ganda
5. **Pemrosesan OCR**: Menjalankan rekognisi teks pada gambar yang ditingkatkan
6. **Seleksi Hasil**: Menggunakan penilaian pola untuk memilih hasil terbaik
7. **Output**: Mengembalikan teks plat yang diformat dengan metadata

Untuk setiap wilayah plat terdeteksi:
- Mengekstrak koordinat kotak pembatas (x1, y1, x2, y2)
- Memastikan koordinat berada dalam batas gambar
- Memotong wilayah plat nomor
- Menerapkan konversi grayscale dan thresholding
- Mengujicoba 9 teknik pra-pemrosesan yang berbeda
- Memilih hasil terbaik berdasarkan penilaian

### Teknik Pra-pemrosesan Ganda
Sistem menerapkan 9 pendekatan berbeda untuk meningkatkan gambar plat nomor:

1. **Threshold Blur**: Menggunakan blur median + thresholding OTSU
2. **Threshold Adaptif**: Menerapkan threshold adaptif Gaussian
3. **Pembersihan Morfologis**: Menggunakan operasi morfologis untuk membersihkan gambar
4. **Dilasi-Erosi**: Pendekatan kombinasi untuk membersihkan teks
5. **Threshold Blur Gaussian**: Menerapkan blur Gaussian sebelum thresholding
6. **Peningkatan Kontras**: Meningkatkan kontras sebelum thresholding
7. **Filter Bilateral**: Mengurangi noise sambil mempertahankan tepi
8. **CLAHE**: Contrast Limited Adaptive Histogram Equalization
9. **Peningkatan**: Filter peningkatan Laplacian

### Fungsi Rekognisi Pola

#### `calculate_plate_pattern_score(text)`
- Menilai teks terdeteksi berdasarkan pola plat nomor Indonesia
- Menangani huruf tunggal + angka + huruf (mis. B 1387 DKC)
- Menangani dua huruf + angka + huruf (mis. CC 1234 EF)
- Penilaian khusus untuk plat target: B 1387 DKC, B 1656 SPW, L 1389 DJ, K 141 KU
- Contoh pola:
  - Wilayah tunggal: `^[A-Z]\s+\d{1,4}\s+[A-Z]{1,3}$` (mis. B 1387 DKC)
  - Wilayah ganda: `^[A-Z]{2}\s+\d{1,4}\s+[A-Z]{1,3}$` (mis. CC 1234 EF)
  - Variasi tanpa spasi untuk kedua format
- Mengembalikan skor tertimbang menggabungkan kepercayaan OCR dan pencocokan pola

#### `post_process_license_plate(text)`
Pembersihan dan pemformatan teks komprehensif dengan:

1. **Koreksi Kesalahan OCR**:
   - Substitusi karakter: @→0, O→0, U→0, D→0, I→1, l→1, i→1, |→1, !→1, Z→2, S→5, G→6, B→8, dll.
   - Perbaikan kebingungan umum: TJ→DJ, Q→0, {→ karakter hilang

2. **Pencocokan Pola**:
   - Mencocokkan pola huruf tunggal + angka + huruf
   - Mencocokkan pola dua huruf + angka + huruf
   - Menangani format dengan dan tanpa spasi
   - Menambahkan spasi yang tepat jika diperlukan

3. **Penanganan Khusus Indonesia**:
   - Rekognisi khusus untuk kode wilayah Indonesia yang umum
   - Rekognisi pola target untuk validasi
   - Koreksi kesalahan untuk kebingungan plat Indonesia yang umum

## Fungsi Pemrosesan Batch

### `process_all_images()`
- Menemukan semua gambar dalam direktori `images/` dan subdirektori
- Mendukung format JPG, JPEG, PNG, dan BMP
- Memproses setiap gambar dan menyimpan hasil dalam format JSON
- Menyimpan hasil ke `license_plate_results.json`
- Menyediakan logging terperinci dari kemajuan pemrosesan

## Fitur-fitur Teknis

### Integrasi YOLO
- Menggunakan model YOLO dengan ambang kepercayaan 0.5
- Menangani beberapa plat dalam satu gambar
- Menyediakan koordinat kotak pembatas untuk plat terdeteksi
- Mengembalikan kepercayaan deteksi untuk setiap plat

### Integrasi PaddleOCR
- Menggunakan model deteksi dan rekognisi (det=True, rec=True)
- Mendukung klasifikasi sudut untuk akurasi yang lebih baik
- Memproses setiap hasil pra-pemrosesan secara terpisah
- Menerapkan ambang kepercayaan 0.4 untuk hasil

### Rekognisi Pola
- Khusus untuk format plat nomor Indonesia
- Penilaian tertimbang menggabungkan kepercayaan OCR dan pencocokan pola
- Rekognisi pola target untuk validasi tujuan
- Validasi pola untuk menyaring teks non-plat

### Penanganan Kesalahan
- Penanganan pengecualian komprehensif untuk setiap langkah pemrosesan
- Degradasi anggun ketika teknik tertentu gagal
- Logging terperinci untuk debugging dan perbaikan
- Validasi gambar input dan hasil pemrosesan

### Peningkatan Gambar
- Banyak teknik pra-pemrosesan untuk menangani kondisi yang bervariasi
- Pendekatan adaptif untuk pencahayaan dan kualitas yang berbeda
- Pengurangan noise dan peningkatan kontras
- Normalisasi format untuk input OCR yang konsisten

## Penggunaan
Modul ini dapat digunakan sebagai library untuk skrip lain atau sebagai pemroses mandiri. Jika dijalankan sebagai skrip, ia memproses semua gambar dalam direktori `images/` dan menyimpan hasil ke file JSON.

Fungsi `process_image` utama dirancang untuk integrasi dengan server API, sementara `process_all_images` berguna untuk pemrosesan batch dan pengujian.

Fungsi rekognisi pola dan pasca-pemrosesan secara khusus disesuaikan untuk plat nomor Indonesia, menjadikan ini sistem ANPR yang sangat spesialisasi dan akurat untuk wilayah tersebut.