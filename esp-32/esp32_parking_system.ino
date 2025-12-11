/*
 * ESP32 IoT Parking System - Complete Implementation
 * Updated to include dual gates, 6 IR sensors, dual LCDs, and manual override
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>

// Include your configuration
#include "api_config.h"

// Pin definitions
#define GATE_ENTRY_SERVO_PIN 5
#define GATE_EXIT_SERVO_PIN 18
#define LCD_ENTRY_SDA 21
#define LCD_ENTRY_SCL 22
#define LCD_EXIT_SDA 19  // Using different pins for second LCD
#define LCD_EXIT_SCL 23  // Using different pins for second LCD

// IR Sensor Pins (6 sensors)
#define IR_SLOT_1_PIN 2
#define IR_SLOT_2_PIN 4
#define IR_SLOT_3_PIN 12
#define IR_SLOT_4_PIN 13
#define IR_GATE_ENTRY_PIN 14
#define IR_GATE_EXIT_PIN 15

// Manual Override Buttons
#define MANUAL_ENTRY_BUTTON_PIN 25
#define MANUAL_EXIT_BUTTON_PIN 26

// Servo control
#include <ESP32Servo.h>
Servo entryServo;
Servo exitServo;

// LCD displays
LiquidCrystal_I2C lcdEntry(0x27, 16, 2); // Address might vary
LiquidCrystal_I2C lcdExit(0x26, 16, 2);  // Different address for second LCD

// WiFi and HTTP client
WiFiClient wifiClient;
HTTPClient http;

// Device ID for this ESP32 unit
const char* DEVICE_ID = "ESP32_PARKING_GATE_001";

// State variables
int slotStatus[4]; // Status of 4 parking slots
int gateEntryTrigger = 0;
int gateExitTrigger = 0;
int availableSlots = 4; // Assuming 4 total slots initially

// Function prototypes
bool connectToWiFi();
bool sendIoTEvent(const char* eventType, const char* slotName);
bool checkForCommands();
bool sendToLcd(int lcdId, const char* message);
String formatJsonRequest(const char* eventType, const char* slotName);
String formatJsonCommandRequest(int commandId, String result);

void setup() {
  Serial.begin(115200);
  
  // Initialize servo library
  ESP32Servo::allocateTimer(0);
  ESP32Servo::allocateTimer(1);
  
  // Attach servos
  entryServo.setPeriodHertz(50);
  entryServo.attach(GATE_ENTRY_SERVO_PIN, 500, 2400);
  exitServo.setPeriodHertz(50);
  exitServo.attach(GATE_EXIT_SERVO_PIN, 500, 2400);
  
  // Set servos to closed position (90 degrees)
  entryServo.write(90);
  exitServo.write(90);
  
  // Initialize IR sensors
  pinMode(IR_SLOT_1_PIN, INPUT);
  pinMode(IR_SLOT_2_PIN, INPUT);
  pinMode(IR_SLOT_3_PIN, INPUT);
  pinMode(IR_SLOT_4_PIN, INPUT);
  pinMode(IR_GATE_ENTRY_PIN, INPUT);
  pinMode(IR_GATE_EXIT_PIN, INPUT);
  
  // Initialize manual override buttons
  pinMode(MANUAL_ENTRY_BUTTON_PIN, INPUT_PULLUP);
  pinMode(MANUAL_EXIT_BUTTON_PIN, INPUT_PULLUP);
  
  // Initialize LCDs
  Wire.begin();
  initLCDs();
  
  // Connect to WiFi
  if (!connectToWiFi()) {
    Serial.println("Failed to connect to WiFi");
    return;
  }
  
  Serial.println("ESP32 Parking System Started");
  Serial.println("Connected to WiFi");
  Serial.print("IP address: ");
  Serial.println(WiFi.localIP());
  
  updateSlotDisplay();
}

void loop() {
  // Read IR sensor states
  readSensors();
  
  // Check for vehicle entry trigger
  if (gateEntryTrigger) {
    if (availableSlots > 0) {
      // Send entry event
      if (sendIoTEvent("ARRIVAL", "GateEntry")) {
        Serial.println("Entry event sent successfully");
      } else {
        Serial.println("Failed to send entry event");
      }
      gateEntryTrigger = 0; // Reset trigger
    } else {
      Serial.println("No available slots, vehicle entry denied");
      showOnLCD(0, "Parkir Penuh!");
    }
  }
  
  // Check for vehicle exit trigger
  if (gateExitTrigger) {
    // Send exit event
    if (sendIoTEvent("DEPARTURE", "GateExit")) {
      Serial.println("Exit event sent successfully");
    } else {
      Serial.println("Failed to send exit event");
    }
    gateExitTrigger = 0; // Reset trigger
  }
  
  // Check for commands from server
  if (checkForCommands()) {
    Serial.println("Command processed");
  }
  
  // Handle manual override buttons
  handleManualOverride();
  
  delay(1000); // Check every second
}

// Initialize both LCD displays
void initLCDs() {
  // Initialize first LCD (entry)
  lcdEntry.init();
  lcdEntry.backlight();
  lcdEntry.setCursor(0, 0);
  lcdEntry.print("Welcome!");
  
  // Initialize second LCD (exit) - may need different address or I2C bus
  Wire.begin(19, 23); // Reinitialize for second LCD if using different pins
  lcdExit.init();
  lcdExit.backlight();
  lcdExit.setCursor(0, 0);
  lcdExit.print("Exit Gate");
}

// Send message to specific LCD
bool sendToLcd(int lcdId, const char* message) {
  if (lcdId == 0) { // Entry LCD
    lcdEntry.clear();
    lcdEntry.setCursor(0, 0);
    lcdEntry.print(message);
  } else if (lcdId == 1) { // Exit LCD
    lcdExit.clear();
    lcdExit.setCursor(0, 0);
    lcdExit.print(message);
  }
  return true;
}

// Show message on LCDs
void showOnLCD(int lcdId, const char* message) {
  sendToLcd(lcdId, message);
}

// Update slot display on entry LCD
void updateSlotDisplay() {
  char displayMsg[20];
  sprintf(displayMsg, "Slot: %d", availableSlots);
  showOnLCD(0, displayMsg);
}

// Read all sensor states
void readSensors() {
  // Read parking slot sensors
  int newSlot1 = !digitalRead(IR_SLOT_1_PIN); // Inverted logic (HIGH = not blocked)
  int newSlot2 = !digitalRead(IR_SLOT_2_PIN);
  int newSlot3 = !digitalRead(IR_SLOT_3_PIN);
  int newSlot4 = !digitalRead(IR_SLOT_4_PIN);
  
  // Calculate available slots
  int newAvailable = 4; // Start with all available
  if (newSlot1) newAvailable--;
  if (newSlot2) newAvailable--;
  if (newSlot3) newAvailable--;
  if (newSlot4) newAvailable--;
  
  // Check for changes in slot status
  if (newSlot1 != slotStatus[0]) {
    slotStatus[0] = newSlot1;
    // Send update to server
    sendIoTEvent(newSlot1 ? "ARRIVAL" : "DEPARTURE", "Slot_1");
  }
  
  if (newSlot2 != slotStatus[1]) {
    slotStatus[1] = newSlot2;
    sendIoTEvent(newSlot2 ? "ARRIVAL" : "DEPARTURE", "Slot_2");
  }
  
  if (newSlot3 != slotStatus[2]) {
    slotStatus[2] = newSlot3;
    sendIoTEvent(newSlot3 ? "ARRIVAL" : "DEPARTURE", "Slot_3");
  }
  
  if (newSlot4 != slotStatus[3]) {
    slotStatus[3] = newSlot4;
    sendIoTEvent(newSlot4 ? "ARRIVAL" : "DEPARTURE", "Slot_4");
  }
  
  // Update available slots count and display
  if (newAvailable != availableSlots) {
    availableSlots = newAvailable;
    updateSlotDisplay();
  }
  
  // Read gate sensors
  int newGateEntry = !digitalRead(IR_GATE_ENTRY_PIN);
  int newGateExit = !digitalRead(IR_GATE_EXIT_PIN);
  
  // Set trigger flags
  if (newGateEntry && !gateEntryTrigger) gateEntryTrigger = 1;
  if (newGateExit && !gateExitTrigger) gateExitTrigger = 1;
}

// Handle manual override buttons
void handleManualOverride() {
  // Manual entry gate override
  if (!digitalRead(MANUAL_ENTRY_BUTTON_PIN)) {
    Serial.println("Manual entry gate override");
    entryServo.write(0); // Open gate
    delay(3000); // Keep open for 3 seconds
    entryServo.write(90); // Close gate
    delay(100); // Debounce
  }
  
  // Manual exit gate override
  if (!digitalRead(MANUAL_EXIT_BUTTON_PIN)) {
    Serial.println("Manual exit gate override");
    exitServo.write(0); // Open gate
    delay(3000); // Keep open for 3 seconds
    exitServo.write(90); // Close gate
    delay(100); // Debounce
  }
}

// Connect to WiFi with credentials from config
bool connectToWiFi() {
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 20) {
    delay(500);
    Serial.print(".");
    attempts++;
  }
  
  if (WiFi.status() == WL_CONNECTED) {
    return true;
  }
  
  return false;
}

// Send IoT event to Laravel API
bool sendIoTEvent(const char* eventType, const char* slotName) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("WiFi not connected");
    return false;
  }
  
  http.begin(wifiClient, API_IOT_EVENT_ENDPOINT);
  
  // Set headers
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Accept", "application/json");
  http.addHeader("Authorization", AUTH_HEADER);
  
  // Create JSON payload
  String payload = formatJsonRequest(eventType, slotName);
  
  // Send POST request
  int httpResponseCode = http.POST(payload);
  
  if (httpResponseCode > 0) {
    String response = http.getString();
    Serial.print("HTTP Response code: ");
    Serial.println(httpResponseCode);
    Serial.print("Response: ");
    Serial.println(response);
    
    // Parse response to check if successful
    DynamicJsonDocument doc(512);
    DeserializationError error = deserializeJson(doc, response);
    
    if (error) {
      Serial.print("JSON Deserialization failed: ");
      Serial.println(error.c_str());
      return false;
    }
    
    bool success = doc["success"];
    if (success) {
      Serial.println("IoT event sent successfully");
      return true;
    } else {
      Serial.println("Server returned error in response");
      return false;
    }
  } else {
    Serial.print("Error sending HTTP request: ");
    Serial.println(httpResponseCode);
    return false;
  }
  
  http.end();
}

// Check for commands from Laravel API
bool checkForCommands() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("WiFi not connected");
    return false;
  }
  
  // Format URL with device_id parameter
  String commandUrl = String(API_IOT_COMMAND_ENDPOINT) + "?device_id=" + DEVICE_ID;
  
  http.begin(wifiClient, commandUrl.c_str());
  
  // Set headers
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Accept", "application/json");
  http.addHeader("Authorization", AUTH_HEADER);
  
  // Send GET request
  int httpResponseCode = http.GET();
  
  if (httpResponseCode > 0) {
    String response = http.getString();
    Serial.print("Command HTTP Response code: ");
    Serial.println(httpResponseCode);
    Serial.print("Command Response: ");
    Serial.println(response);
    
    // Parse response to check for commands
    DynamicJsonDocument doc(512);
    DeserializationError error = deserializeJson(doc, response);
    
    if (error) {
      Serial.print("JSON Deserialization failed: ");
      Serial.println(error.c_str());
      return false;
    }
    
    bool success = doc["success"];
    if (success) {
      String command = doc["data"]["command"];
      int commandId = doc["data"]["command_id"];
      
      Serial.print("Received command: ");
      Serial.println(command);
      
      if (command == "OPEN_GATE_ENTER") {
        // Execute entry gate command
        openGate(0); // 0 for entry gate
        markCommandAsConsumed(commandId, "executed");
        return true;
      } else if (command == "OPEN_GATE_EXIT") {
        // Execute exit gate command
        openGate(1); // 1 for exit gate
        markCommandAsConsumed(commandId, "executed");
        return true;
      } else if (command == "WAIT") {
        Serial.println("No commands to execute");
        return true;
      }
    } else {
      Serial.println("Server returned error in command response");
      return false;
    }
  } else {
    Serial.print("Error getting commands: ");
    Serial.println(httpResponseCode);
    return false;
  }
  
  http.end();
  return false;
}

// Open the appropriate gate
void openGate(int gateId) {
  Serial.print("Opening gate: ");
  Serial.println(gateId == 0 ? "ENTRY" : "EXIT");
  
  if (gateId == 0) { // Entry gate
    entryServo.write(0); // Open gate
    delay(3000); // Keep open for 3 seconds
    entryServo.write(90); // Close gate
    showOnLCD(0, "Selamat Datang!");
    delay(2000);
    updateSlotDisplay(); // Update slot info after vehicle entry
  } else { // Exit gate
    exitServo.write(0); // Open gate
    delay(3000); // Keep open for 3 seconds
    exitServo.write(90); // Close gate
    showOnLCD(1, "Terima Kasih!");
    delay(2000);
    lcdExit.clear();
    lcdExit.setCursor(0, 0);
    lcdExit.print("Exit Gate");
  }
}

// Mark command as consumed
bool markCommandAsConsumed(int commandId, String result) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("WiFi not connected");
    return false;
  }
  
  http.begin(wifiClient, API_BASE "/iot/command/consume");
  
  // Set headers
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Accept", "application/json");
  http.addHeader("Authorization", AUTH_HEADER);
  
  // Create JSON payload for consuming command
  String payload = formatJsonCommandRequest(commandId, result);
  
  // Send POST request to consume command
  int httpResponseCode = http.POST(payload);
  
  if (httpResponseCode > 0) {
    String response = http.getString();
    Serial.print("Consume Command HTTP Response code: ");
    Serial.println(httpResponseCode);
    Serial.print("Response: ");
    Serial.println(response);
    
    return true;
  } else {
    Serial.print("Error consuming command: ");
    Serial.println(httpResponseCode);
    return false;
  }
  
  http.end();
}

// Helper function to format JSON request for IoT event
String formatJsonRequest(const char* eventType, const char* slotName) {
  DynamicJsonDocument doc(256);
  
  doc["event_type"] = eventType;
  doc["slot_name"] = slotName;
  doc["device_id"] = DEVICE_ID;
  
  String jsonString;
  serializeJson(doc, jsonString);
  
  return jsonString;
}

// Helper function to format JSON request for consuming command
String formatJsonCommandRequest(int commandId, String result) {
  DynamicJsonDocument doc(256);
  
  doc["command_id"] = commandId;
  doc["result"] = result;
  doc["device_id"] = DEVICE_ID;
  
  String jsonString;
  serializeJson(doc, jsonString);
  
  return jsonString;
}