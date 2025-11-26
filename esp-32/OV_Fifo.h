// ESP32 (Arduino) - Complete implementation for OV7670 camera to capture and send images to Python ANPR server
#include <WiFi.h>
#include <HTTPClient.h>
#include "esp_camera.h"  // Include the camera library

// WiFi credentials
const char* ssid = "YOUR_SSID";
const char* password = "YOUR_PASS";

// Python ANPR server URL (update with your actual server IP)
const char* serverUrl = "http://192.168.1.100:5000/process_image"; // Changed endpoint to match Python server

// Camera pin definitions for ESP32-CAM or custom board
#define PWDN_GPIO_NUM     -1
#define RESET_GPIO_NUM    -1
#define XCLK_GPIO_NUM     21
#define SIOD_GPIO_NUM     26
#define SIOC_GPIO_NUM     27

#define Y9_GPIO_NUM       35
#define Y8_GPIO_NUM       34
#define Y7_GPIO_NUM       39
#define Y6_GPIO_NUM       36
#define Y5_GPIO_NUM       19
#define Y4_GPIO_NUM       18
#define Y3_GPIO_NUM        5
#define Y2_GPIO_NUM        4
#define VSYNC_GPIO_NUM    25
#define HREF_GPIO_NUM     23
#define PCLK_GPIO_NUM     22

void setup() {
  Serial.begin(115200);

  // Connect to WiFi
  WiFi.begin(ssid, password);
  Serial.print("Connecting WiFi");
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println("\nConnected to WiFi!");
  Serial.print("IP address: ");
  Serial.println(WiFi.localIP());

  // Camera configuration
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = Y2_GPIO_NUM;
  config.pin_d1 = Y3_GPIO_NUM;
  config.pin_d2 = Y4_GPIO_NUM;
  config.pin_d3 = Y5_GPIO_NUM;
  config.pin_d4 = Y6_GPIO_NUM;
  config.pin_d5 = Y7_GPIO_NUM;
  config.pin_d6 = Y8_GPIO_NUM;
  config.pin_d7 = Y9_GPIO_NUM;
  config.pin_xclk = XCLK_GPIO_NUM;
  config.pin_pclk = PCLK_GPIO_NUM;
  config.pin_vsync = VSYNC_GPIO_NUM;
  config.pin_href = HREF_GPIO_NUM;
  config.pin_sscb_sda = SIOD_GPIO_NUM;
  config.pin_sscb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn = PWDN_GPIO_NUM;
  config.pin_reset = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000; // 20MHz
  config.pixel_format = PIXFORMAT_JPEG; // Use JPEG to reduce data size

  // Frame settings
  if(psramFound()){
    config.frame_size = FRAMESIZE_UXGA; // UXGA (1600x1200) for better ANPR accuracy
    config.jpeg_quality = 10; // Lower number = higher quality
    config.fb_count = 2;
  } else {
    config.frame_size = FRAMESIZE_SVGA; // SVGA (800x600) if PSRAM not available
    config.jpeg_quality = 12;
    config.fb_count = 1;
  }

  // Initialize the camera
  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) {
    Serial.printf("Camera initialization failed with error 0x%x", err);
    return;
  }

  Serial.println("Camera initialized successfully!");
}

void loop() {
  // Capture frame from camera
  camera_fb_t * fb = esp_camera_fb_get();
  if (!fb) {
    Serial.println("Camera capture failed");
    delay(1000);
    return;
  }

  Serial.printf("Captured image: %dx%d, size: %d bytes\n", fb->width, fb->height, fb->len);

  // Send via HTTP POST to Python ANPR server
  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;

    // Set the server URL
    http.begin(serverUrl);
    http.addHeader("Content-Type", "image/jpeg");
    http.addHeader("Content-Length", String(fb->len));

    // Send the image data
    int httpResponseCode = http.POST((uint8_t*)fb->buf, fb->len);

    if (httpResponseCode > 0) {
      String response = http.getString();
      Serial.printf("HTTP Response code: %d\n", httpResponseCode);
      Serial.println("Server response: " + response);

      // Parse the JSON response to get the license plate
      if (httpResponseCode == 200) {
        Serial.println("ANPR processed successfully");
      }
    } else {
      Serial.printf("Error sending POST: %s\n", http.errorToString(httpResponseCode).c_str());
    }

    http.end();
  } else {
    Serial.println("WiFi not connected");
  }

  // Return the frame buffer back to the driver for reuse
  esp_camera_fb_return(fb);

  delay(3000); // Capture and send every 3 seconds
}
