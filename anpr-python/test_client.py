import requests

url = "http://127.0.0.1:5000/anpr"

# Ganti path ke gambar test kamu
image_path = "kendaraan.jpg"

with open(image_path, "rb") as f:
    files = {"image": f}
    response = requests.post(url, files=files)

print("Status:", response.status_code)
print("Response:", response.json())
