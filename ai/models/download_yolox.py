"""
EleGuard Rail - YOLOX-Tiny ONNX Model Downloader & Verification Script
Milestone 8B: Permissive Apache-2.0 Object Detection Prototype

Downloads and verifies the official pretrained YOLOX-Tiny ONNX model (19.28 MB)
from the Megvii YOLOX release repository.

Provenance:
- Upstream: https://github.com/Megvii-BaseDetection/YOLOX/releases/tag/0.1.1rc0
- Architecture: YOLOX-Tiny (Decoupled Anchor-Free, 416x416)
- Pretrained on: Microsoft COCO (CC BY 4.0)
- License: Apache License 2.0
- Checkpoint: yolox_tiny.pth -> yolox_tiny.onnx
- SHA-256: 427cc366d34e27ff7a03e2899b5e3671425c262ea2291f88bb942bc1cc70b0f7
"""

import hashlib
import os
import sys
import urllib.request

MODEL_URL = "https://github.com/Megvii-BaseDetection/YOLOX/releases/download/0.1.1rc0/yolox_tiny.onnx"
TARGET_DIR = os.path.dirname(os.path.abspath(__file__))
TARGET_PATH = os.path.join(TARGET_DIR, "yolox_tiny.onnx")
EXPECTED_SIZE = 20219662
EXPECTED_SHA256 = "427cc366d34e27ff7a03e2899b5e3671425c262ea2291f88bb942bc1cc70b0f7"


def verify_yolox_model():
    """Verify existence, file size, and SHA-256 checksum."""
    if not os.path.exists(TARGET_PATH):
        return False, f"Model file not found at: {TARGET_PATH}"
    size = os.path.getsize(TARGET_PATH)
    if size != EXPECTED_SIZE:
        return False, f"Model size mismatch: {size} bytes (expected {EXPECTED_SIZE})."
    
    h = hashlib.sha256()
    with open(TARGET_PATH, "rb") as f:
        while chunk := f.read(65536):
            h.update(chunk)
    actual_sha = h.hexdigest()
    if actual_sha.lower() != EXPECTED_SHA256.lower():
        return False, f"SHA-256 mismatch: {actual_sha} (expected {EXPECTED_SHA256})."

    return True, f"Verified YOLOX-Tiny ONNX model ({size:,} bytes, SHA-256: {actual_sha[:16]}...)"


def download_yolox_model(force=False):
    """Download YOLOX-Tiny model from official Megvii GitHub release."""
    is_valid, msg = verify_yolox_model()
    if is_valid and not force:
        print(f"✓ {msg}")
        return True

    print(f"Downloading YOLOX-Tiny ONNX model from:\n  {MODEL_URL}\nDestination: {TARGET_PATH}...")
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
        is_valid, msg = verify_yolox_model()
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
    download_yolox_model()
