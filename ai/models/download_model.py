"""
EleGuard Rail - AI Model Downloader & Verification Script
Downloads and verifies the lightweight pretrained YOLOv8n ONNX model (~12.2 MB).
Model Source: Pretrained YOLOv8n (COCO 80-class object detection) exported to ONNX format.
"""

import hashlib
import os
import sys
import urllib.request

MODEL_URL = "https://huggingface.co/kshitijjjjjjjjjjjjjjjj/yolov8n-coco-onnx/resolve/main/yolov8n.onnx"
TARGET_DIR = os.path.dirname(os.path.abspath(__file__))
TARGET_PATH = os.path.join(TARGET_DIR, "yolov8n.onnx")
EXPECTED_SIZE = 12851145

def verify_model():
    if not os.path.exists(TARGET_PATH):
        return False, "Model file not found."
    size = os.path.getsize(TARGET_PATH)
    if size != EXPECTED_SIZE:
        return False, f"Model size mismatch: {size} bytes (expected {EXPECTED_SIZE})."
    return True, f"Model verified: {TARGET_PATH} ({size:,} bytes)"

def download_model(force=False):
    is_valid, msg = verify_model()
    if is_valid and not force:
        print(f"✓ {msg}")
        return True

    print(f"Downloading YOLOv8n ONNX model from:\n  {MODEL_URL}\nDestination: {TARGET_PATH}...")
    headers = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"}
    req = urllib.request.Request(MODEL_URL, headers=headers)

    try:
        with urllib.request.urlopen(req) as resp, open(TARGET_PATH, "wb") as f:
            total = int(resp.headers.get("Content-Length", 0))
            downloaded = 0
            block_size = 65536
            while True:
                chunk = resp.read(block_size)
                if not chunk:
                    break
                f.write(chunk)
                downloaded += len(chunk)
                pct = (downloaded / total * 100) if total else 0
                sys.stdout.write(f"\rProgress: {downloaded:,} / {total:,} bytes ({pct:.1f}%)")
                sys.stdout.flush()
        print("\nDownload complete.")
        is_valid, msg = verify_model()
        if is_valid:
            print(f"✓ {msg}")
            return True
        else:
            print(f"❌ Verification failed: {msg}")
            return False
    except Exception as e:
        print(f"\n❌ Error downloading model: {e}")
        return False

if __name__ == "__main__":
    force_download = "--force" in sys.argv
    success = download_model(force=force_download)
    sys.exit(0 if success else 1)
