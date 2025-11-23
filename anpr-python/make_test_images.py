import requests

API_TOKEN = "Bearer 1|pZCLZ3qMYwALInqrNca4zCtNRr9wBNBtWJjD4bfj2084de25"
API_URL_ENTRY = "http://127.0.0.1:8000/api/entry"

fake_plate = "D 9999 XYZ"

r = requests.post(
    API_URL_ENTRY,
    json={"plate": fake_plate},
    headers={"Authorization": API_TOKEN}
)

print("Server response:", r.text)
