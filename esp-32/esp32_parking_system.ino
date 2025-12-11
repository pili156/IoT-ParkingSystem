#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <ESP32Servo.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>

// --- KONFIGURASI WIFI & SERVER ---
#define WIFI_SSID "Raflii"
#define WIFI_PASS "77777777"
#define API_BASE "http://10.218.100.27:8000/api"

// --- PIN DEFINITIONS ---
// 1. Sensor IR Slot (4 Buah)
#define IR_SLOT1 34  // LOW when occupied, HIGH when empty
#define IR_SLOT2 35
#define IR_SLOT3 32
#define IR_SLOT4 33

// 2. Sensor IR Gerbang (2 Buah)
#define IR_ENTRY_GATE 25 // Deteksi mobil mau masuk
#define IR_EXIT_GATE  26 // Deteksi mobil mau keluar

// 3. Tombol Manual (2 Buah) - Gunakan PullUp Internal (Connect ke GND saat ditekan)
#define BTN_MANUAL_IN  27
#define BTN_MANUAL_OUT 14

// 4. Servo (Gerbang)
#define SERVO_ENTER_PIN 18
#define SERVO_EXIT_PIN  19

// 5. LCD I2C (SDA=21, SCL=22)
// Both LCDs on same I2C bus with different addresses
LiquidCrystal_I2C lcdEnter(0x26, 16, 2); // LCD Pintu Masuk (Displays slot info)
LiquidCrystal_I2C lcdExit(0x27, 16, 2);  // LCD Pintu Keluar (Displays bill info)

// --- OBJEK & VARIABEL ---
Servo gateEnter;
Servo gateExit;

// Status Sensor Slot
int lastState1 = HIGH;
int lastState2 = HIGH;
int lastState3 = HIGH;
int lastState4 = HIGH;

// Timer Polling
unsigned long lastPollTime = 0;
const long pollInterval = 1000;

void setup() {
  Serial.begin(115200);

  // 1. Setup Input (Sensor & Tombol)
  pinMode(IR_SLOT1, INPUT);
  pinMode(IR_SLOT2, INPUT);
  pinMode(IR_SLOT3, INPUT);
  pinMode(IR_SLOT4, INPUT);
  pinMode(IR_ENTRY_GATE, INPUT);
  pinMode(IR_EXIT_GATE, INPUT);

  // Tombol pakai INPUT_PULLUP biar gak butuh resistor tambahan
  pinMode(BTN_MANUAL_IN, INPUT_PULLUP);
  pinMode(BTN_MANUAL_OUT, INPUT_PULLUP);

  // 2. Initialize I2C bus (Single bus for both LCDs)
  Wire.begin(21, 22); // SDA=21, SCL=22

  // 3. Setup LCD
  // LCD Masuk (0x26)
  lcdEnter.init();
  lcdEnter.backlight();
  lcdEnter.setCursor(0, 0);
  lcdEnter.print("System Starting");

  // LCD Keluar (0x27)
  lcdExit.init();
  lcdExit.backlight();
  lcdExit.setCursor(0, 0);
  lcdExit.print("Please Wait...");

  // 4. Setup Servo
  gateEnter.attach(SERVO_ENTER_PIN);
  gateExit.attach(SERVO_EXIT_PIN);
  gateEnter.write(0); // Tutup (0 Derajat)
  gateExit.write(0);  // Tutup (0 Derajat)

  // 5. Konek WiFi
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  Serial.print("Connecting");
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println("\nWiFi OK!");

  lcdEnter.clear();
  lcdEnter.print("WiFi Connected");
  delay(1000);
  updateSlotLCD(); // Tampilkan info slot awal
}

void loop() {
  // A. Logic Tombol Manual (Prioritas Tertinggi)
  if (digitalRead(BTN_MANUAL_IN) == LOW) { // LOW artinya ditekan
    Serial.println("Manual Button: OPEN IN");
    lcdEnter.setCursor(0, 1);
    lcdEnter.print("Manual Open   ");
    openGate(gateEnter);
    updateSlotLCD(); // Balikin tampilan normal
    delay(200); // Debounce
  }

  if (digitalRead(BTN_MANUAL_OUT) == LOW) {
    Serial.println("Manual Button: OPEN OUT");
    lcdExit.setCursor(0, 1);
    lcdExit.print("Manual Open   ");
    openGate(gateExit);
    lcdExit.setCursor(0, 1);
    lcdExit.print("              "); // Clear baris bawah
    delay(200); // Debounce
  }

  // B. Cek Sensor Parkir (Real-time)
  checkSlot(IR_SLOT1, "Slot 1", lastState1);
  checkSlot(IR_SLOT2, "Slot 2", lastState2);
  checkSlot(IR_SLOT3, "Slot 3", lastState3);
  checkSlot(IR_SLOT4, "Slot 4", lastState4);

  // C. Polling Perintah dari Server
  if (millis() - lastPollTime >= pollInterval) {
    getCommandFromLaravel();
    lastPollTime = millis();
  }

  delay(50);
}

// --- FUNGSI 1: Cek Sensor & Lapor ---
void checkSlot(int pin, String slotName, int &lastState) {
  int currentState = digitalRead(pin);

  if (currentState != lastState) {
    String eventType;
    if (currentState == LOW) {
      eventType = "ARRIVAL"; // LOW means car has arrived
    } else {
      eventType = "DEPARTURE"; // HIGH means car has departed
    }

    sendEventToLaravel(slotName, eventType);
    updateSlotLCD(); // Update angka slot di LCD Masuk
    lastState = currentState;
  }
}

// --- FUNGSI 2: Kirim Data ---
void sendEventToLaravel(String slotName, String eventType) {
  if(WiFi.status() == WL_CONNECTED){
    HTTPClient http;
    http.begin(String(API_BASE) + "/iot-event");
    http.addHeader("Content-Type", "application/json");

    StaticJsonDocument<200> doc;
    doc["slot_name"] = slotName;
    doc["event_type"] = eventType;

    String requestBody;
    serializeJson(doc, requestBody);
    http.POST(requestBody);
    http.end();
  }
}

// --- FUNGSI 3: Ambil Perintah ---
void getCommandFromLaravel() {
  if(WiFi.status() == WL_CONNECTED){
    HTTPClient http;
    http.begin(String(API_BASE) + "/get-command");

    int httpCode = http.GET();
    if (httpCode > 0) {
      String payload = http.getString();
      StaticJsonDocument<200> doc;
      deserializeJson(doc, payload);

      String cmd = doc["command"].as<String>();

      if (cmd == "OPEN_GATE_ENTER") {
        lcdEnter.setCursor(0, 1);
        lcdEnter.print("Welcome!      ");
        openGate(gateEnter);
        updateSlotLCD(); // Balik ke tampilan slot
      }
      else if (cmd == "OPEN_GATE_EXIT") {
        // Tampilkan Bill (Simulasi atau Ambil dari JSON jika ada)
        lcdExit.clear();
        lcdExit.print("Goodbye!");
        lcdExit.setCursor(0, 1);

        // Kalau controller kirim bill, tampilkan. Kalau tidak, pesan standar.
        if (doc.containsKey("bill")) {
           String billAmount = doc["bill"].as<String>();
           lcdExit.print("Bill: " + billAmount);
        } else {
           lcdExit.print("Safe Trip!");
        }

        openGate(gateExit);
        lcdExit.clear(); // Bersihkan setelah mobil lewat
      }
    }
    http.end();
  }
}

// --- FUNGSI 4: Gerakkan Servo (90 Derajat) ---
void openGate(Servo &servo) {
  servo.write(90); // BUKA (90 Derajat)
  delay(3000);     // Tahan 3 detik
  servo.write(0);  // TUTUP (0 Derajat)
}

// --- FUNGSI 5: LCD Entry (Info Slot) ---
void updateSlotLCD() {
  int freeSlots = 0;
  // Sensor IR: HIGH = Kosong (Tidak ada mobil), LOW = Ada Mobil
  if (digitalRead(IR_SLOT1) == HIGH) freeSlots++;
  if (digitalRead(IR_SLOT2) == HIGH) freeSlots++;
  if (digitalRead(IR_SLOT3) == HIGH) freeSlots++;
  if (digitalRead(IR_SLOT4) == HIGH) freeSlots++;

  lcdEnter.setCursor(0, 0);
  lcdEnter.print("PARKING INFO    ");
  lcdEnter.setCursor(0, 1);
  lcdEnter.print("Empty Slots: " + String(freeSlots) + "/4 ");
}