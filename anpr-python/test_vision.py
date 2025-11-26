import os
from google.cloud import vision
import io

# Paksa Python memakai key ini
os.environ["GOOGLE_APPLICATION_CREDENTIALS"] = r"C:\Programing\GitHub\IoT\IoT-ParkingSystem\anpr-python\keys\project-parkir-479209-523f16a4b91a.json"

def read_text(img_path):
    client = vision.ImageAnnotatorClient()

    with io.open(img_path, "rb") as f:
        content = f.read()

    image = vision.Image(content=content)

    response = client.text_detection(image=image)

    if response.error.message:
        raise Exception(response.error.message)

    if not response.text_annotations:
        print("Tidak ada teks ditemukan")
        return None

    print("=== HASIL OCR ===")
    print(response.text_annotations[0].description)

    return response.text_annotations[0].description


if __name__ == "__main__":
    read_text("kendaraan.jpg")
