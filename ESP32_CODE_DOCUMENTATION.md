# Dokumentasi Sistem Parkir ESP32 (`esp32_parking_system.ino`)

## Gambaran Umum
Firmware ESP32 mengontrol lapisan perangkat keras dari sistem parkir pintar IoT, mengelola input sensor, output servo, tampilan LCD, dan komunikasi API dengan backend Laravel.

## Bagian Konfigurasi
```cpp
// Pengaturan WiFi
#define WIFI_SSID "Raflii"
#define WIFI_PASS "77777777"
#define API_BASE "http://10.218.100.27:8000/api"

// Definisi Pin
#define IR_SLOT1 34  // LOW saat terisi, HIGH saat kosong
#define IR_SLOT2 35
#define IR_SLOT3 32
#define IR_SLOT4 33
#define IR_ENTRY_GATE 25 // Mendeteksi kendaraan masuk
#define IR_EXIT_GATE  26 // Mendeteksi kendaraan keluar
#define BTN_MANUAL_IN  27
#define BTN_MANUAL_OUT 14
#define SERVO_ENTER_PIN 18
#define SERVO_EXIT_PIN  19

// Alamat LCD I2C
LiquidCrystal_I2C lcdEnter(0x26, 16, 2); // Tampilan gerbang masuk
LiquidCrystal_I2C lcdExit(0x27, 16, 2);  // Tampilan gerbang keluar
```

## Fungsi Utama Dijelaskan

### Fungsi Setup
- Inisialisasi komunikasi Serial untuk debugging
- Konfigurasi semua pin sensor sebagai input
- Konfigurasi tombol dengan pull-up internal
- Setup komunikasi I2C untuk tampilan LCD
- Inisialisasi tampilan LCD dengan pesan awal
- Penempelan servo dan pengaturan ke posisi tertutup (0°)
- Koneksi ke jaringan WiFi

### Fungsi Loop
Loop utama berjalan terus-menerus dengan prioritas berikut:

1. **Override Manual (Prioritas 1)**
   - Memeriksa tombol manual untuk operasi gerbang darurat
   - Melewati semua logika otomatis untuk keamanan

2. **Monitoring Slot (Prioritas 2)**
   - Memeriksa semua sensor IR secara terus-menerus
   - Mendeteksi kedatangan/keberangkatan kendaraan di slot parkir
   - Melaporkan perubahan status ke backend

3. **Polling Perintah (Prioritas 3)**
   - Mempoll backend setiap detik untuk perintah gerbang
   - Menerapkan timing tanpa blocking dengan millis()

### Fungsi Pemeriksaan Sensor (`checkSlot`)
- Memantau slot parkir individu untuk perubahan status
- Mengirim pembaruan event ke backend Laravel
- Memperbarui tampilan LCD menunjukkan jumlah slot kosong

### Fungsi Komunikasi API

#### `sendEventToLaravel(slotName, eventType)`
- Mengirim perubahan status slot ke `/api/iot-event`
- Melaporkan event ARRIVAL (sinyal LOW) dan DEPARTURE (sinyal HIGH)
- Menggunakan payload JSON dengan nama slot dan tipe event

#### `getCommandFromLaravel()`
- Mempoll backend untuk perintah gerbang melalui `/api/get-command`
- Menangani perintah OPEN_GATE_ENTER (gerbang masuk) dan OPEN_GATE_EXIT (gerbang keluar)
- Memperbarui tampilan LCD dengan pesan yang sesuai
- Menyertakan informasi tagihan opsional untuk keluar

### Fungsi Kontrol Gerbang (`openGate`)
- Memutar servo ke 90° (posisi terbuka)
- Menjaga posisi terbuka selama 3 detik
- Mengembalikan servo ke 0° (posisi tertutup)

### Fungsi Manajemen LCD

#### `updateSlotLCD()`
- Menghitung slot parkir yang tersedia
- Menampilkan "PARKING INFO" dan jumlah slot kosong di LCD masuk
- Memperbarui secara real-time berdasarkan pembacaan sensor

## Fitur-fitur Utama

### Monitoring Real-time
- Polling sensor kontinu untuk status parkir terkini
- Pelaporan langsung perubahan status ke backend
- Pembaruan tampilan LCD real-time

### Keamanan & Override Manual
- Penanganan prioritas tombol manual
- Kapabilitas operasi gerbang darurat
- Timing tanpa blocking untuk mencegah sistem macet

### Integrasi API
- Manajemen koneksi WiFi yang robust
- Komunikasi API tahan terhadap kesalahan
- Mekanisme polling untuk pengambilan perintah

### Antarmuka Pengguna
- Sistem tampilan LCD ganda
- LCD masuk menunjukkan ketersediaan parkir
- LCD keluar menunjukkan informasi tagihan

## Integrasi Hardware
- Sensor IR untuk deteksi parkir yang presisi
- Motor servo untuk kontrol gerbang otomatis
- Tampilan LCD I2C untuk informasi pengguna
- Tombol manual untuk override keamanan

## Protokol Komunikasi
- Komunikasi API REST dengan backend Laravel
- Format payload JSON untuk pelaporan event
- Polling berkala untuk pengambilan perintah
- Penanganan kesalahan untuk masalah jaringan

Firmware ini menciptakan endpoint IoT yang handal yang mengelola fasilitas parkir dengan kontrol otomatis dan manual.