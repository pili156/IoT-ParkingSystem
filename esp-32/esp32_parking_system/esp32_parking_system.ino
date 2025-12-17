/*
 * SISTEM PARKIR OTOMATIS - FINAL VERSION (ESP32)
 * - Entry langsung buka gate
 * - Exit pakai billing dari Laravel
 * - LCD masuk & keluar
 * - Safety interlock
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <ESP32Servo.h> 
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <ArduinoJson.h>

// ==================================================
// FUNCTION PROTOTYPES (WAJIB ADA)
// ==================================================
void sendEventToLaravel(String type, String value, String slotName = "");
bool checkCommandFromServer();
void acknowledgeCommandOnServer(int commandId);
void handleEntryGate();
void handleExitGate();
void updateSlotStatus(bool forceUpdate);
void handleManualButtons();
void handleServoTimers();

// ==================================================
// KONFIGURASI JARINGAN
// ==================================================
const char* ssid = "Raflii";
const char* password = "88888883";
const char* baseUrl = "http://10.239.9.27:8000/api";

// ==================================================
// IDENTITAS DEVICE
// ==================================================
String DEVICE_ID = "esp-1";
int lastCommandIdReceived = -1;

// ==================================================
// PIN CONFIG
// ==================================================
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

// ==================================================
// OBJECTS
// ==================================================
LiquidCrystal_I2C lcdMasuk(0x26, 16, 2);
LiquidCrystal_I2C lcdKeluar(0x27, 16, 2);

Servo servoMasuk;
Servo servoKeluar;

// ==================================================
// GLOBAL VARIABLES
// ==================================================
int slotStatus[4] = {HIGH, HIGH, HIGH, HIGH};
int freeSlots = 4;

unsigned long servoMasukTimer = 0;
unsigned long servoKeluarTimer = 0;
bool servoMasukAktif = false;
bool servoKeluarAktif = false;
const int SERVO_DURATION = 5000;

bool exitRequestActive = false;
unsigned long lastBillingCheck = 0;
const int BILLING_CHECK_INTERVAL = 1000;

String parkingTime = "";
String parkingCost = "";

const String slotNames[4] = {"Slot-1", "Slot-2", "Slot-3", "Slot-4"};

// ==================================================
// SETUP
// ==================================================
void setup() {
  Serial.begin(115200);

  pinMode(PIN_SENSOR_SLOT_1, INPUT);
  pinMode(PIN_SENSOR_SLOT_2, INPUT);
  pinMode(PIN_SENSOR_SLOT_3, INPUT);
  pinMode(PIN_SENSOR_SLOT_4, INPUT);
  pinMode(PIN_SENSOR_MASUK, INPUT);
  pinMode(PIN_SENSOR_KELUAR, INPUT);
  pinMode(PIN_TOMBOL_MASUK, INPUT_PULLUP);
  pinMode(PIN_TOMBOL_KELUAR, INPUT_PULLUP);

  servoMasuk.attach(PIN_SERVO_MASUK);
  servoKeluar.attach(PIN_SERVO_KELUAR);
  servoMasuk.write(0);
  servoKeluar.write(0);

  lcdMasuk.init();
  lcdMasuk.backlight();
  lcdKeluar.init();
  lcdKeluar.backlight();

  lcdMasuk.print("Booting...");

  WiFi.begin(ssid, password);
  int retry = 0;
  while (WiFi.status() != WL_CONNECTED && retry < 20) {
    delay(500);
    Serial.print(".");
    retry++;
  }

  lcdMasuk.clear();
  if (WiFi.status() == WL_CONNECTED) {
    lcdMasuk.print("WiFi Connected");
    Serial.println("\nWiFi OK");
  } else {
    lcdMasuk.print("WiFi Failed");
  }

  delay(1000);
  updateSlotStatus(true);
}

// ==================================================
// LOOP
// ==================================================
void loop() {
  handleManualButtons();
  handleEntryGate();
  handleExitGate();
  updateSlotStatus(false);
  handleServoTimers();

  if (WiFi.status() != WL_CONNECTED) {
    WiFi.reconnect();
  }
}

// ==================================================
// ENTRY GATE
// ==================================================
void handleEntryGate() {
  if (digitalRead(PIN_SENSOR_MASUK) == LOW && !servoMasukAktif) {
    servoMasuk.write(90);
    servoMasukAktif = true;
    servoMasukTimer = millis();

    lcdMasuk.setCursor(0,1);
    lcdMasuk.print(freeSlots > 0 ? "Silahkan Masuk " : "PARKIR PENUH ");

    sendEventToLaravel("ENTRY", "1", slotNames[0]);

    if (lastCommandIdReceived > 0) {
      acknowledgeCommandOnServer(lastCommandIdReceived);
      lastCommandIdReceived = -1;
    }
    delay(1000);
  }
}

// ==================================================
// EXIT GATE
// ==================================================
void handleExitGate() {
  if (digitalRead(PIN_SENSOR_KELUAR) == LOW && !exitRequestActive && !servoKeluarAktif) {
    exitRequestActive = true;
    lcdKeluar.clear();
    lcdKeluar.print("Hitung Biaya...");
    sendEventToLaravel("EXIT_BILLING_REQUEST", "1", slotNames[0]);
    lastBillingCheck = millis();
  }

  if (exitRequestActive && millis() - lastBillingCheck >= BILLING_CHECK_INTERVAL) {
    if (checkCommandFromServer()) {
      lcdKeluar.clear();
      lcdKeluar.setCursor(0,0); lcdKeluar.print("Waktu: " + parkingTime);
      lcdKeluar.setCursor(0,1); lcdKeluar.print("Biaya: " + parkingCost);

      servoKeluar.write(90);
      servoKeluarAktif = true;
      servoKeluarTimer = millis();

      if (lastCommandIdReceived > 0) {
        acknowledgeCommandOnServer(lastCommandIdReceived);
        lastCommandIdReceived = -1;
      }

      exitRequestActive = false;
      parkingTime = "";
      parkingCost = "";
    }
    lastBillingCheck = millis();
  }
}

// ==================================================
// SLOT STATUS
// ==================================================
void updateSlotStatus(bool forceUpdate) {
  int currentStatus[4] = {
    digitalRead(PIN_SENSOR_SLOT_1),
    digitalRead(PIN_SENSOR_SLOT_2),
    digitalRead(PIN_SENSOR_SLOT_3),
    digitalRead(PIN_SENSOR_SLOT_4)
  };

  bool changed = false;
  freeSlots = 0;

  for (int i = 0; i < 4; i++) {
    if (slotStatus[i] != currentStatus[i]) changed = true;
    slotStatus[i] = currentStatus[i];
    if (slotStatus[i] == HIGH) freeSlots++;
  }

  if (changed || forceUpdate) {
    if (!servoMasukAktif) {
      lcdMasuk.clear();
      lcdMasuk.setCursor(0,0);
      lcdMasuk.print(freeSlots == 0 ? "PARKIR PENUH!" : "Slot: " + String(freeSlots));
      lcdMasuk.setCursor(0,1);
      lcdMasuk.print("Tap / Masuk");
    }

    if (changed) {
      for (int i = 0; i < 4; i++) {
        sendEventToLaravel("SLOT_UPDATE", slotStatus[i] == HIGH ? "1" : "0", slotNames[i]);
      }
    }
  }
}

// ==================================================
// MANUAL BUTTON
// ==================================================
void handleManualButtons() {
  if (digitalRead(PIN_TOMBOL_MASUK) == LOW) {
    servoMasuk.write(90);
    servoMasukAktif = true;
    servoMasukTimer = millis();
    delay(500);
  }

  if (digitalRead(PIN_TOMBOL_KELUAR) == LOW) {
    servoKeluar.write(90);
    servoKeluarAktif = true;
    servoKeluarTimer = millis();
    delay(500);
  }
}

// ==================================================
// SERVO TIMER + SAFETY
// ==================================================
void handleServoTimers() {
  if (servoMasukAktif && millis() - servoMasukTimer >= SERVO_DURATION) {
    if (digitalRead(PIN_SENSOR_MASUK) == HIGH) {
      servoMasuk.write(0);
      servoMasukAktif = false;
      updateSlotStatus(true);
    }
  }

  if (servoKeluarAktif && millis() - servoKeluarTimer >= SERVO_DURATION) {
    if (digitalRead(PIN_SENSOR_KELUAR) == HIGH) {
      servoKeluar.write(0);
      servoKeluarAktif = false;
      lcdKeluar.clear();
      lcdKeluar.print("Siap Digunakan");
    }
  }
}

// ==================================================
// SERVER COMMUNICATION
// ==================================================
void sendEventToLaravel(String type, String value, String slotName) {
  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  http.begin(String(baseUrl) + "/iot-event");
  http.addHeader("Content-Type", "application/json");

  StaticJsonDocument<256> doc;
  doc["type"] = type;
  doc["value"] = value;
  doc["device_id"] = DEVICE_ID;
  if (slotName.length()) doc["slot_name"] = slotName;

  String body;
  serializeJson(doc, body);
  http.POST(body);
  http.end();
}

bool checkCommandFromServer() {
  if (WiFi.status() != WL_CONNECTED) return false;

  HTTPClient http;
  http.begin(String(baseUrl) + "/get-command");
  http.addHeader("Content-Type", "application/json");

  StaticJsonDocument<128> doc;
  doc["type"] = "CHECK_BILLING_STATUS";
  doc["device_id"] = DEVICE_ID;

  String body;
  serializeJson(doc, body);
  int code = http.POST(body);

  if (code > 0) {
    StaticJsonDocument<512> resp;
    deserializeJson(resp, http.getString());

    if (resp.containsKey("data")) {
      parkingTime = resp["data"]["time"].as<String>();
      parkingCost = resp["data"]["cost"].as<String>();
      lastCommandIdReceived = resp["command_id"] | -1;
      http.end();
      return true;
    }
  }
  http.end();
  return false;
}

void acknowledgeCommandOnServer(int commandId) {
  if (commandId <= 0 || WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  http.begin(String(baseUrl) + "/consume-command");
  http.addHeader("Content-Type", "application/json");

  StaticJsonDocument<128> doc;
  doc["command_id"] = commandId;
  doc["device_id"] = DEVICE_ID;
  doc["result"] = "OK";

  String body;
  serializeJson(doc, body);
  http.POST(body);
  http.end();
}
