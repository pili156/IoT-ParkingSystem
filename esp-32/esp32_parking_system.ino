/*
 * SISTEM PARKIR OTOMATIS - FINAL VERSION + SAFETY INTERLOCK + SMART DISPLAY
 * * LOGIKA UTAMA:
 * 1. Masuk (Sat-Set): Servo BUKA DULU -> Baru lapor ke /api/iot-event
 * 2. Keluar (Sabar): Minta Bill ke /api/iot-event -> Polling ke /api/get-command -> Servo BUKA.
 * 3. Parkir: Update slot secara Real-time ke /api/iot-event.
 * * * FITUR TAMBAHAN:
 * - SAFETY INTERLOCK: Gerbang tidak menutup jika ada mobil di bawahnya.
 * - SMART FULL SIGN: LCD Masuk menampilkan "PARKIR PENUH!" jika slot 0.
 * - DETAILED BILLING: LCD Keluar menampilkan Waktu dan Biaya (diambil dari server).
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <ESP32Servo.h> 
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <ArduinoJson.h>

// --- KONFIGURASI JARINGAN ---
const char* ssid = "Jepri";
const char* password = "22222222";

// BASE URL API LARAVEL
const char* baseUrl = "http://10.241.6.59:8000/api"; 

// --- DEFINISI PIN ---
#define PIN_SENSOR_SLOT_1 34
#define PIN_SENSOR_SLOT_2 35
#define PIN_SENSOR_SLOT_3 32
#define PIN_SENSOR_SLOT_4 33

#define PIN_SENSOR_MASUK 25
#define PIN_SENSOR_KELUAR 26

#define PIN_SERVO_MASUK 18
#define PIN_SERVO_KELUAR 19

#define PIN_TOMBOL_MASUK 27
#define PIN_TOMBOL_KELUAR 14

// --- OBJEK LCD & SERVO ---
LiquidCrystal_I2C lcdMasuk(0x26, 16, 2); 
LiquidCrystal_I2C lcdKeluar(0x27, 16, 2); 
Servo servoMasuk;
Servo servoKeluar;

// --- VARIABEL GLOBAL ---
int slotStatus[4] = {HIGH, HIGH, HIGH, HIGH}; 
int freeSlots = 4;

// Timer Servo (Non-blocking)
unsigned long servoMasukTimer = 0;
unsigned long servoKeluarTimer = 0;
bool servoMasukAktif = false;
bool servoKeluarAktif = false;
const int SERVO_DURATION = 5000; 

// Variabel Pintu Keluar (Billing Polling)
bool exitRequestActive = false;
unsigned long lastBillingCheck = 0;
const int BILLING_CHECK_INTERVAL = 1000; // Cek server tiap 1 detik

// Variabel untuk menyimpan data billing dari server
String parkingTime = "";
String parkingCost = "";

void setup() {
  Serial.begin(115200);

  // 1. Setup Input
  pinMode(PIN_SENSOR_SLOT_1, INPUT);
  pinMode(PIN_SENSOR_SLOT_2, INPUT);
  pinMode(PIN_SENSOR_SLOT_3, INPUT);
  pinMode(PIN_SENSOR_SLOT_4, INPUT);
  pinMode(PIN_SENSOR_MASUK, INPUT); 
  pinMode(PIN_SENSOR_KELUAR, INPUT);
  pinMode(PIN_TOMBOL_MASUK, INPUT_PULLUP);
  pinMode(PIN_TOMBOL_KELUAR, INPUT_PULLUP);

  // 2. Setup Servo
  servoMasuk.attach(PIN_SERVO_MASUK);
  servoKeluar.attach(PIN_SERVO_KELUAR);
  servoMasuk.write(0); 
  servoKeluar.write(0);

  // 3. Setup LCD
  lcdMasuk.init(); lcdMasuk.backlight();
  lcdKeluar.init(); lcdKeluar.backlight();

  lcdMasuk.print("System Booting..");
  
  // 4. Koneksi WiFi
  WiFi.begin(ssid, password);
  int retry = 0;
  while (WiFi.status() != WL_CONNECTED && retry < 20) {
    delay(500); Serial.print(".");
    retry++;
  }
  
  lcdMasuk.clear();
  if(WiFi.status() == WL_CONNECTED){
    lcdMasuk.print("WiFi Connected");
    Serial.println("\nWiFi OK!");
  } else {
    lcdMasuk.print("WiFi Failed!"); 
  }
  delay(1000);
  
  updateSlotStatus(true); // Tampilkan slot awal
}

void loop() {
  // A. Tombol Manual (Darurat)
  handleManualButtons();

  // B. Pintu Masuk (Langsung Buka)
  handleEntryGate();

  // C. Pintu Keluar (Nunggu Tagihan)
  handleExitGate();

  // D. Update Slot Parkir (Termasuk Smart Full Sign)
  updateSlotStatus(false);

  // E. Timer Servo (Termasuk Safety Interlock)
  handleServoTimers();

  // F. Reconnect WiFi jika putus
  if (WiFi.status() != WL_CONNECTED) {
    WiFi.reconnect(); 
  }
}

// --- FUNGSI LOGIKA UTAMA (KOMBISASI ONLINE & SAFETY) ---

void handleEntryGate() {
  // Jika Sensor Masuk Mendeteksi Mobil (LOW)
  if (digitalRead(PIN_SENSOR_MASUK) == LOW) {
    if (!servoMasukAktif) { 
      Serial.println("ENTRY: Mobil Terdeteksi -> Buka Gerbang!");
      
      // AKSI CEPAT: Buka Servo Dulu!
      servoMasuk.write(90);
      servoMasukAktif = true;
      servoMasukTimer = millis();

      // LCD sementara saat gerbang buka
      lcdMasuk.setCursor(0, 1);
      if(freeSlots > 0) {
        lcdMasuk.print("Silahkan Masuk  ");
      } else {
        lcdMasuk.print("Hati2 (PENUH)   ");
      }

      // Lapor Server (Belakangan) ke /iot-event
      sendEventToLaravel("ENTRY", "0"); 
      
      delay(1000); // Debounce
    }
  }
}

void handleExitGate() {
  // 1. Deteksi Mobil Mau Keluar
  if (digitalRead(PIN_SENSOR_KELUAR) == LOW && !exitRequestActive && !servoKeluarAktif) {
    Serial.println("EXIT: Mobil Terdeteksi -> Minta Tagihan...");
    
    exitRequestActive = true; 
    
    lcdKeluar.clear();
    lcdKeluar.setCursor(0, 0); lcdKeluar.print("Mohon Tunggu...");
    lcdKeluar.setCursor(0, 1); lcdKeluar.print("Hitung Biaya...");

    // Kirim Request ke /iot-event
    sendEventToLaravel("EXIT_BILLING_REQUEST", "0");
    lastBillingCheck = millis();
  }

  // 2. Polling (Nanya Terus ke Server)
  if (exitRequestActive) {
    if (millis() - lastBillingCheck >= BILLING_CHECK_INTERVAL) {
      // Cek ke /get-command
      bool dataReceived = checkCommandFromServer(); 
      
      if (dataReceived) { // Jika data (time dan cost) sudah diterima
        Serial.println("EXIT: Tagihan Diterima -> Waktu: " + parkingTime + ", Biaya: " + parkingCost);
        
        // --- TAMPILAN BIAYA & WAKTU DI LCD (Diambil dari Server) ---
        lcdKeluar.clear();
        lcdKeluar.setCursor(0, 0); lcdKeluar.print("Waktu: " + parkingTime); 
        lcdKeluar.setCursor(0, 1); lcdKeluar.print("Biaya: " + parkingCost); 

        // Buka Gerbang
        servoKeluar.write(90);
        servoKeluarAktif = true;
        servoKeluarTimer = millis();

        exitRequestActive = false;
        parkingTime = ""; // Reset variabel
        parkingCost = ""; // Reset variabel
      }
      lastBillingCheck = millis();
    }
  }
}

void updateSlotStatus(bool forceUpdate) {
  int currentStatus[4];
  currentStatus[0] = digitalRead(PIN_SENSOR_SLOT_1);
  currentStatus[1] = digitalRead(PIN_SENSOR_SLOT_2);
  currentStatus[2] = digitalRead(PIN_SENSOR_SLOT_3);
  currentStatus[3] = digitalRead(PIN_SENSOR_SLOT_4);

  bool changed = false;
  int tempFree = 0;

  for(int i=0; i<4; i++){
    if(slotStatus[i] != currentStatus[i]) changed = true;
    slotStatus[i] = currentStatus[i];
    if(slotStatus[i] == HIGH) tempFree++; // Asumsi HIGH = Kosong
  }
  freeSlots = tempFree;

  if (changed || forceUpdate) {
    // --- SMART FULL SIGN LOGIC ---
    if (!servoMasukAktif) {
      lcdMasuk.clear(); 
      if (freeSlots == 0) {
        lcdMasuk.setCursor(0, 0); lcdMasuk.print("PARKIR PENUH!");
        lcdMasuk.setCursor(0, 1); lcdMasuk.print("Mohon Maaf...   ");
      } else {
        lcdMasuk.setCursor(0, 0); 
        lcdMasuk.print("Slot: " + String(freeSlots) + "/4 Kosong");
        lcdMasuk.setCursor(0, 1); 
        lcdMasuk.print("Tap Kartu/Masuk");
      }
    }
    // Kirim Update Slot ke /iot-event
    if(changed) sendEventToLaravel("SLOT_UPDATE", String(freeSlots));
  }
}

void handleManualButtons() {
  // Tombol Masuk
  if (digitalRead(PIN_TOMBOL_MASUK) == LOW) {
    servoMasuk.write(90);
    servoMasukAktif = true;
    servoMasukTimer = millis(); 
    lcdMasuk.setCursor(0,1); lcdMasuk.print("MANUAL OPEN     ");
    delay(500);
  }
  // Tombol Keluar
  if (digitalRead(PIN_TOMBOL_KELUAR) == LOW) {
    servoKeluar.write(90);
    servoKeluarAktif = true;
    servoKeluarTimer = millis();
    lcdKeluar.setCursor(0,1); lcdKeluar.print("MANUAL OPEN     ");
    delay(500);
  }
}

// --- SAFETY INTERLOCK LOGIC ---
void handleServoTimers() {
  // 1. Logika Tutup Pintu Masuk
  if (servoMasukAktif && (millis() - servoMasukTimer >= SERVO_DURATION)) {
    
    // SAFETY CHECK: Apakah masih ada mobil di sensor masuk?
    if (digitalRead(PIN_SENSOR_MASUK) == LOW) {
      Serial.println("SAFETY: Mobil masih di Entry Gate. Menunda tutup.");
      servoMasukTimer = millis(); // Reset timer
    } else {
      // Aman, tutup gerbang
      servoMasuk.write(0);
      servoMasukAktif = false;
      updateSlotStatus(true); // Balikin tampilan slot
      Serial.println("Gate Masuk Ditutup.");
    }
  }

  // 2. Logika Tutup Pintu Keluar
  if (servoKeluarAktif && (millis() - servoKeluarTimer >= SERVO_DURATION)) {
    
    // SAFETY CHECK: Apakah masih ada mobil di sensor keluar?
    if (digitalRead(PIN_SENSOR_KELUAR) == LOW) {
      Serial.println("SAFETY: Mobil masih di Exit Gate. Menunda tutup.");
      servoKeluarTimer = millis(); // Reset timer
    } else {
      servoKeluar.write(0);
      servoKeluarAktif = false;
      // Reset LCD Keluar ke default
      lcdKeluar.clear();
      lcdKeluar.print("Sistem Parkir");
      lcdKeluar.setCursor(0,1); lcdKeluar.print("Siap Digunakan");
      Serial.println("Gate Keluar Ditutup.");
    }
  }
}

// --- FUNGSI KOMUNIKASI SERVER (SESUAI QWEN) ---

// 1. Kirim Event Laporan
void sendEventToLaravel(String type, String value) {
  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;
    http.begin(String(baseUrl) + "/iot-event"); 
    http.addHeader("Content-Type", "application/json");
    
    StaticJsonDocument<200> doc;
    doc["type"] = type; 
    doc["value"] = value; 
    
    String requestBody;
    serializeJson(doc, requestBody);
    
    // Tanpa cek response, cukup kirim saja
    http.POST(requestBody);
    http.end();
  }
}

// 2. Cek Command/Tagihan - Diubah untuk mengambil Waktu & Biaya
bool checkCommandFromServer() { 
  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;
    http.begin(String(baseUrl) + "/get-command");
    http.addHeader("Content-Type", "application/json");
    
    // Kirim type: CHECK_BILLING_STATUS
    StaticJsonDocument<200> doc;
    doc["type"] = "CHECK_BILLING_STATUS"; 
    String requestBody;
    serializeJson(doc, requestBody);
    
    int httpCode = http.POST(requestBody); 
    
    if (httpCode > 0) {
      String payload = http.getString();
      StaticJsonDocument<512> respDoc;
      // Parsing JSON
      DeserializationError error = deserializeJson(respDoc, payload);

      if (error) {
        Serial.print(F("deserializeJson() failed: "));
        Serial.println(error.f_str());
        return false;
      }
      
      // Cek apakah server mengirimkan data 'cost' dan 'time'
      if (respDoc.containsKey("data") && respDoc["data"].containsKey("cost") && respDoc["data"].containsKey("time")) {
        // Ambil data dan simpan ke variabel global
        parkingTime = respDoc["data"]["time"].as<String>();
        parkingCost = respDoc["data"]["cost"].as<String>();
        return true; // Data ditemukan
      }
    }
    http.end();
  }
  return false; // Data belum ditemukan atau WiFi terputus
}