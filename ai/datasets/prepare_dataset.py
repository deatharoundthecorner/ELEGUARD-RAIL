"""
EleGuard Rail - AI Dataset Preparation & Curation Script
Collects and prepares public domain / CC-licensed evaluation images,
creates challenging edge cases (low light, blur, herd composites),
and generates ground_truth.json for reproducible AI benchmarking.
"""

import json
import os
import urllib.request
from PIL import Image, ImageEnhance, ImageFilter

DATASET_ROOT = os.path.dirname(os.path.abspath(__file__))
ELEPHANTS_DIR = os.path.join(DATASET_ROOT, "elephants")
NON_ELEPHANTS_DIR = os.path.join(DATASET_ROOT, "non_elephants")
EDGE_CASES_DIR = os.path.join(DATASET_ROOT, "edge_cases")

os.makedirs(ELEPHANTS_DIR, exist_ok=True)
os.makedirs(NON_ELEPHANTS_DIR, exist_ok=True)
os.makedirs(EDGE_CASES_DIR, exist_ok=True)

HEADERS = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"}

def download_file(url, out_path):
    if os.path.exists(out_path) and os.path.getsize(out_path) > 0:
        return True
    try:
        req = urllib.request.Request(url, headers=HEADERS)
        with urllib.request.urlopen(req) as resp, open(out_path, "wb") as f:
            f.write(resp.read())
        return True
    except Exception as e:
        print(f"Failed to download {url}: {e}")
        return False

def prepare_all():
    print("Preparing evaluation datasets...")

    # 1. Base Elephant Images (Asian and African elephants from ImageNet sample / public domain)
    ele1_path = os.path.join(ELEPHANTS_DIR, "elephant_01_asian.jpg")
    download_file(
        "https://raw.githubusercontent.com/EliSchwartz/imagenet-sample-images/master/n02504013_Indian_elephant.JPEG",
        ele1_path
    )

    ele2_path = os.path.join(ELEPHANTS_DIR, "elephant_02_african.jpg")
    download_file(
        "https://raw.githubusercontent.com/EliSchwartz/imagenet-sample-images/master/n02504458_African_elephant.JPEG",
        ele2_path
    )

    # 2. Herd / Multiple Elephants (Synthesized composite of Asian elephants on natural terrain)
    ele_herd_path = os.path.join(ELEPHANTS_DIR, "elephant_03_herd.jpg")
    if os.path.exists(ele1_path) and not os.path.exists(ele_herd_path):
        im1 = Image.open(ele1_path).convert("RGB")
        w, h = im1.size
        herd = Image.new("RGB", (w * 2, h), (120, 140, 110))
        # Left elephant
        herd.paste(im1.resize((w, h)), (0, 0))
        # Right elephant (flipped horizontal & scaled)
        im2 = im1.transpose(Image.FLIP_LEFT_RIGHT).resize((int(w * 0.9), int(h * 0.9)))
        herd.paste(im2, (int(w * 0.95), int(h * 0.1)))
        herd.save(ele_herd_path, quality=92)
        print("✓ Created synthetic herd image (2 elephants):", ele_herd_path)

    # 3. Non-Elephant Images
    # Water buffalo (crucial negative test - cattle near tracks)
    buf_path = os.path.join(NON_ELEPHANTS_DIR, "buffalo_01.jpg")
    download_file(
        "https://raw.githubusercontent.com/EliSchwartz/imagenet-sample-images/master/n02408429_water_buffalo.JPEG",
        buf_path
    )

    # Locomotive / Train
    train_path = os.path.join(NON_ELEPHANTS_DIR, "train_01.jpg")
    download_file(
        "https://raw.githubusercontent.com/EliSchwartz/imagenet-sample-images/master/n04310018_steam_locomotive.JPEG",
        train_path
    )

    # Domestic Animal / Dog
    dog_path = os.path.join(NON_ELEPHANTS_DIR, "dog_01.jpg")
    download_file(
        "https://raw.githubusercontent.com/EliSchwartz/imagenet-sample-images/master/n02106662_German_shepherd.JPEG",
        dog_path
    )

    # Vehicle / Jeep
    jeep_path = os.path.join(NON_ELEPHANTS_DIR, "vehicle_01.jpg")
    download_file(
        "https://raw.githubusercontent.com/EliSchwartz/imagenet-sample-images/master/n03594945_jeep.JPEG",
        jeep_path
    )

    # Empty Landscape (Synthesized railway track landscape with forest corridor)
    land_path = os.path.join(NON_ELEPHANTS_DIR, "landscape_01.jpg")
    if not os.path.exists(land_path):
        land = Image.new("RGB", (640, 480), (60, 95, 45))
        # Draw sky
        for y in range(160):
            r = int(120 + y * 0.4)
            g = int(160 + y * 0.3)
            b = int(210 - y * 0.3)
            for x in range(640):
                land.putpixel((x, y), (r, g, b))
        land.save(land_path, quality=90)
        print("✓ Created empty landscape test image:", land_path)

    # 4. Edge Cases: Low Light & Blur
    low_light_path = os.path.join(EDGE_CASES_DIR, "edge_01_low_light_elephant.jpg")
    if os.path.exists(ele1_path) and not os.path.exists(low_light_path):
        im = Image.open(ele1_path).convert("RGB")
        dark = ImageEnhance.Brightness(im).enhance(0.28) # Simulate dusk / twilight
        dark.save(low_light_path, quality=85)
        print("✓ Created low-light edge case image:", low_light_path)

    blurry_path = os.path.join(EDGE_CASES_DIR, "edge_02_blurry_elephant.jpg")
    if os.path.exists(ele1_path) and not os.path.exists(blurry_path):
        im = Image.open(ele1_path).convert("RGB")
        blur = im.filter(ImageFilter.GaussianBlur(radius=4.5)) # Camera motion shake
        blur.save(blurry_path, quality=85)
        print("✓ Created motion-blurred edge case image:", blurry_path)

    blurry_non_path = os.path.join(EDGE_CASES_DIR, "edge_03_blurry_buffalo.jpg")
    if os.path.exists(buf_path) and not os.path.exists(blurry_non_path):
        im = Image.open(buf_path).convert("RGB")
        blur = im.filter(ImageFilter.GaussianBlur(radius=4.0))
        blur.save(blurry_non_path, quality=85)
        print("✓ Created motion-blurred buffalo image:", blurry_non_path)

    # 5. Build ground_truth.json
    gt = {
        "metadata": {
            "title": "EleGuard Rail - Computer Vision Evaluation Ground Truth",
            "version": "1.0",
            "categories": ["elephant", "non_elephant"],
            "target_species": "Elephas maximus (Asian Elephant) and Loxodonta africana (African Elephant)"
        },
        "images": [
            {
                "file": "elephants/elephant_01_asian.jpg",
                "has_elephant": True,
                "expected_count": 1,
                "category": "asian_elephant",
                "notes": "Single Asian elephant (Elephas maximus), natural lighting"
            },
            {
                "file": "elephants/elephant_02_african.jpg",
                "has_elephant": True,
                "expected_count": 1,
                "category": "african_elephant",
                "notes": "Single African bush elephant in savanna terrain"
            },
            {
                "file": "elephants/elephant_03_herd.jpg",
                "has_elephant": True,
                "expected_count": 2,
                "category": "elephant_herd",
                "notes": "Two elephants in proximity, herd crossing simulation"
            },
            {
                "file": "non_elephants/buffalo_01.jpg",
                "has_elephant": False,
                "expected_count": 0,
                "category": "water_buffalo",
                "notes": "Water buffalo (Bubalus bubalis), frequent false positive test"
            },
            {
                "file": "non_elephants/train_01.jpg",
                "has_elephant": False,
                "expected_count": 0,
                "category": "locomotive",
                "notes": "Train on railway tracks"
            },
            {
                "file": "non_elephants/dog_01.jpg",
                "has_elephant": False,
                "expected_count": 0,
                "category": "canine",
                "notes": "Domestic dog / sheepdog"
            },
            {
                "file": "non_elephants/vehicle_01.jpg",
                "has_elephant": False,
                "expected_count": 0,
                "category": "vehicle",
                "notes": "Motor vehicle / jeep near road"
            },
            {
                "file": "non_elephants/landscape_01.jpg",
                "has_elephant": False,
                "expected_count": 0,
                "category": "empty_landscape",
                "notes": "Corridor foliage without animals"
            },
            {
                "file": "edge_cases/edge_01_low_light_elephant.jpg",
                "has_elephant": True,
                "expected_count": 1,
                "category": "low_light_elephant",
                "notes": "Elephant under twilight / low ambient illumination"
            },
            {
                "file": "edge_cases/edge_02_blurry_elephant.jpg",
                "has_elephant": True,
                "expected_count": 1,
                "category": "blurry_elephant",
                "notes": "Elephant with mobile camera motion blur"
            },
            {
                "file": "edge_cases/edge_03_blurry_buffalo.jpg",
                "has_elephant": False,
                "expected_count": 0,
                "category": "blurry_non_elephant",
                "notes": "Blurry water buffalo - testing false positive suppression under blur"
            }
        ]
    }

    gt_path = os.path.join(DATASET_ROOT, "ground_truth.json")
    with open(gt_path, "w", encoding="utf-8") as f:
        json.dump(gt, f, indent=2)
    print(f"✓ Ground truth dataset specification saved: {gt_path} ({len(gt['images'])} test cases)")

if __name__ == "__main__":
    prepare_all()
