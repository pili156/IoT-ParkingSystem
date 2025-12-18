Conda setup for ANPR (anpr-python)
=================================

1. Create the conda environment from `environment.yml`:

```bash
conda env create -f environment.yml
```

2. Activate the environment:

```bash
conda activate anpr
```

3. (Optional) If `paddlepaddle` needs a specific wheel (GPU/CPU), install per PaddlePaddle instructions.

4. Ensure `.env` is configured (edit `anpr-python/.env`): set `LARAVEL_API_URL` to your Laravel server, and verify model paths (`YOLO_MODEL_PATH`, `PADDLE_OCR_DIR`).

5. Start the Flask ANPR API server:

```bash
cd anpr-python
python anpr_api_server.py
```

6. Start the camera capture (will use `CAMERA_1_ID` and `CAMERA_2_ID` from `.env`):

```bash
python anpr_dual_cam.py
```

7. Use `test_integration.py` to simulate ANPR posts if you don't have a camera or want to validate quickly:

```bash
python test_integration.py
```

Notes:
- If OpenCV installation fails on your platform, prefer `conda install -c conda-forge opencv`.
- `ultralytics` and `paddleocr` may download large models; ensure you have enough disk space and network bandwidth.
- If you use a CUDA GPU, follow PaddlePaddle and Ultralytics docs to install appropriate GPU-enabled packages.
