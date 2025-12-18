# Panduan Integrasi Gemini API untuk Sistem ANPR

## Gambaran Umum
Dokumen ini menjelaskan bagaimana integrasi Google Gemini API ditambahkan ke sistem ANPR (Automatic Number Plate Recognition) yang sudah ada. Integrasi ini menyediakan fallback otomatis ketika OCR lokal (PaddleOCR) gagal mendeteksi nomor plat dengan akurasi yang memadai.

## Arsitektur Integrasi

### Sistem ANPR Sebelum Integrasi
1. Kamera mengambil gambar
2. YOLO mendeteksi kotak plat nomor
3. PaddleOCR membaca teks dari kotak yang terdeteksi
4. Hasil diproses dan dikirim ke backend

### Sistem ANPR Setelah Integrasi
1. Kamera mengambil gambar
2. YOLO mendeteksi kotak plat nomor
3. PaddleOCR membaca teks dari kotak (sebagai metode utama)
4. **Jika PaddleOCR gagal atau hasil rendah: Gemini API digunakan sebagai fallback**
5. Hasil terbaik diproses dan dikirim ke backend

## Komponen Baru

### Fungsi Utama yang Ditambahkan

#### `extract_text_with_gemini(image, fallback_text=None, fallback_conf=0.0)`
- Fungsi untuk mengekstrak teks dari gambar menggunakan Gemini API
- Menerima gambar OpenCV dan mengembalikan teks plat serta tingkat kepercayaan
- Jika tidak ada API key atau error, kembali ke teks fallback

#### `process_image_from_array_with_fallback(img, yolo_model, ocr_model)`
- Versi yang mencoba OCR lokal dulu, baru pakai Gemini API jika gagal
- Membandingkan hasil dari metode lokal dan Gemini, lalu memilih yang terbaik

## Konfigurasi

### File Konfigurasi
File `.env` sekarang menyertakan:
```env
GEMINI_API_KEY=your_gemini_api_key_here
GEMINI_MODEL_NAME=gemini-pro-vision
```

### Variabel Lingkungan
- `GEMINI_API_KEY`: Kunci API dari Google AI Studio
- `GEMINI_MODEL_NAME`: Model Gemini yang digunakan (default: gemini-pro-vision)

## Cara Mendapatkan API Key

### 1. Kunjungi Google AI Studio
- Buka https://makersuite.google.com/app/apikey
- Login dengan akun Google

### 2. Buat API Key
- Klik "Create API Key"
- Pilih project (atau buat yang baru)
- Salin API Key yang dihasilkan

### 3. Atur di File .env
```env
GEMINI_API_KEY=isi_dengan_api_key_mu_disini
```

## Instalasi Dependencies

Untuk menggunakan Gemini API, instal library yang diperlukan:
```bash
pip install google-generativeai
```

Atau tambahkan ke `requirements.txt`:
```
google-generativeai
```

## Mekanisme Fallback

### Alur Kerja
1. Sistem mencoba proses gambar dengan OCR lokal (PaddleOCR)
2. Jika tidak ada hasil atau kepercayaan < 0.6, sistem mencoba dengan Gemini API
3. Jika Gemini API mengembalikan hasil dengan kepercayaan > 0.5, hasil tersebut digunakan
4. Sistem melaporkan metode yang digunakan (lokal atau Gemini) untuk debugging

### Penilaian Hasil
- Menggunakan fungsi `calculate_plate_pattern_score` untuk menilai pola plat Indonesia
- Menggabungkan kepercayaan OCR dan skor pola
- Memilih hasil dengan skor tertinggi

## Keuntungan Integrasi

1. **Akurasi Lebih Tinggi**: Google Gemini adalah model AI canggih yang mungkin lebih akurat
2. **Fallback Otomatis**: Sistem tetap berjalan bahkan jika OCR lokal gagal
3. **Pemeliharaan Mudah**: Google yang mengurus update model
4. **Kompatibilitas**: Tidak merusak sistem yang sudah ada

## Pengaruh terhadap Sistem yang Ada

### Positif
- Meningkatkan akurasi deteksi plat nomor
- Menyediakan cadangan jika OCR lokal bermasalah
- Tidak mengubah alur kerja utama sistem

### Kekhawatiran
- Bergantung pada koneksi internet untuk Gemini API
- Biaya penggunaan API (Google mengenakan biaya per permintaan)
- Potensi latensi tambahan saat memanggil API

## Implementasi di Kode

### Di `anpr_bisa.py`
- Fungsi `process_image_from_array_with_fallback` menggantikan fungsi sebelumnya
- Memastikan sistem mencoba Gemini API hanya jika diperlukan
- Menyimpan informasi metode yang digunakan untuk debugging

### Di `anpr_api_server.py`
- Fungsi `process_camera_image` sekarang menggunakan versi dengan fallback Gemini

## Contoh Hasil

Sistem sekarang akan mencatat metode yang digunakan:
```
[ENTRY] Plate detected: B 1234 ABC (method: gemini_api)
```

Atau:
```
[EXIT] Plate detected: N 5678 XY (method: multi_method_optimized)
```

## Troubleshooting

### Jika Gemini API Tidak Bekerja
1. Periksa apakah `GEMINI_API_KEY` sudah diisi dengan benar
2. Pastikan library `google-generativeai` sudah terinstal
3. Cek koneksi internet saat runtime

### Jika Tidak Ada Perubahan
1. Sistem mungkin masih menggunakan OCR lokal karena hasilnya bagus
2. Coba dengan gambar yang sebelumnya tidak terdeteksi oleh OCR lokal

## Kesimpulan

Integrasi Gemini API menyediakan peningkatan signifikan pada sistem ANPR dengan menyediakan fallback pintar ketika OCR lokal tidak dapat membaca plat nomor secara akurat. Pendekatan ini mempertahankan fungsionalitas sistem yang ada sambil menambahkan kemampuan tambahan yang kuat.