#define WIFI_SSID "Raflii"
#define WIFI_PASS "77777777"

// Updated API endpoint using the new secure structure
#define API_BASE "http://10.218.100.27:8000/api"

// ESP32-specific token (should be generated via the API first time)
// This should be a real token obtained from the API registration
#define API_TOKEN "your_secure_esp32_token_here"
#define AUTH_HEADER "Bearer " API_TOKEN

// API endpoints with proper authentication
#define API_PARKING_STATUS_ENDPOINT API_BASE "/parking/slots/status"
#define API_IOT_EVENT_ENDPOINT API_BASE "/iot/event"
#define API_IOT_COMMAND_ENDPOINT API_BASE "/iot/command"
#define API_PARKING_SLOTS_ENDPOINT API_BASE "/parking/slots"
#define API_IOT_COMMAND_CONSUME_ENDPOINT API_BASE "/iot/command/consume"