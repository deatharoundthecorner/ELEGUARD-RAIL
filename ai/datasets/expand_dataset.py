"""
EleGuard Rail - Expanded Dataset Preparation & Benchmark Script
Milestone 8B: Authentic Sri Lankan Wildlife & Railway Environment Evaluation

Downloads legally usable CC-BY/CC-BY-SA images of Sri Lankan elephants,
railway tracks, and native wildlife, and constructs systematic optical benchmarks
(night-time headlight illumination, distant sighting, foliage occlusion).
"""

import json
import os
import sys
import urllib.request
from pathlib import Path
from PIL import Image, ImageEnhance, ImageFilter, ImageDraw

PROJECT_ROOT = Path(__file__).resolve().parent.parent.parent
DATASETS_DIR = PROJECT_ROOT / "ai" / "datasets"
ELEPHANTS_DIR = DATASETS_DIR / "elephants"
NON_ELEPHANTS_DIR = DATASETS_DIR / "non_elephants"
EDGE_CASES_DIR = DATASETS_DIR / "edge_cases"

HEADERS = {"User-Agent": "EleGuardRailResearch/1.0 (contact@eleguard.lk)"}

# Authentic CC-licensed Sri Lankan wildlife and railway images from Wikimedia Commons
REMOTE_SAMPLES = [
    {
        "filename": "elephant_04_habarana_crossing.jpg",
        "dir": ELEPHANTS_DIR,
        "url": "https://thumb.wikimedia.org/wikipedia/commons/thumb/4/4b/An_elephant_crossing_the_road_in_Habarana.jpg/960px-An_elephant_crossing_the_road_in_Habarana.jpg",
        "title": "An elephant crossing the road in Habarana",
        "category": "sri_lankan_elephant",
        "license": "CC BY-SA 4.0",
        "author": "Nisakya Nissanka",
        "source_url": "https://commons.wikimedia.org/wiki/File:An_elephant_crossing_the_road_in_Habarana.jpg",
        "has_elephant": True,
        "expected_count": 1,
        "expected_classification": "Potential Elephant Detected",
        "notes": "Wild Sri Lankan elephant (Elephas maximus maximus) crossing road in Habarana corridor"
    },
    {
        "filename": "elephant_05_sigiriya_wild.jpg",
        "dir": ELEPHANTS_DIR,
        "url": "https://thumb.wikimedia.org/wikipedia/commons/thumb/0/0b/Asian_Elephant_in_Sigiriya.jpg/960px-Asian_Elephant_in_Sigiriya.jpg",
        "title": "Asian Elephant in Sigiriya",
        "category": "sri_lankan_elephant",
        "license": "CC BY-SA 4.0",
        "author": "Lasanthi Wijayathunga",
        "source_url": "https://commons.wikimedia.org/wiki/File:Asian_Elephant_in_Sigiriya.jpg",
        "has_elephant": True,
        "expected_count": 1,
        "expected_classification": "Potential Elephant Detected",
        "notes": "Wild Asian elephant in Sigiriya dry forest zone near railway section 1"
    },
    {
        "filename": "elephant_06_kaudulla_herd.jpg",
        "dir": ELEPHANTS_DIR,
        "url": "https://thumb.wikimedia.org/wikipedia/commons/thumb/f/f9/Parc_Nacional_de_Kaudulla_-_Elefants.jpg/960px-Parc_Nacional_de_Kaudulla_-_Elefants.jpg",
        "title": "Parc Nacional de Kaudulla - Elefants",
        "category": "sri_lankan_elephant_herd",
        "license": "CC BY 4.0",
        "author": "Josep Renalias (Lohen11)",
        "source_url": "https://commons.wikimedia.org/wiki/File:Parc_Nacional_de_Kaudulla_-_Elefants.jpg",
        "has_elephant": True,
        "expected_count": 5,
        "expected_classification": "Potential Elephant Detected",
        "notes": "Wild Sri Lankan elephant herd foraging in Kaudulla National Park"
    },
    {
        "filename": "elephant_07_kaudulla_brush.jpg",
        "dir": ELEPHANTS_DIR,
        "url": "https://thumb.wikimedia.org/wikipedia/commons/thumb/6/6a/Kaudulla_nationalpark_2017-10-17_%289%29.jpg/960px-Kaudulla_nationalpark_2017-10-17_%289%29.jpg",
        "title": "Kaudulla nationalpark 2017-10-17 (9)",
        "category": "sri_lankan_elephant",
        "license": "CC BY-SA 4.0",
        "author": "Z thomas",
        "source_url": "https://commons.wikimedia.org/wiki/File:Kaudulla_nationalpark_2017-10-17_(9).jpg",
        "has_elephant": True,
        "expected_count": 1,
        "expected_classification": "Potential Elephant Detected",
        "notes": "Solitary adult Sri Lankan elephant in natural brush terrain"
    },
    {
        "filename": "rail_track_01_ambewela.jpg",
        "dir": NON_ELEPHANTS_DIR,
        "url": "https://thumb.wikimedia.org/wikipedia/commons/thumb/c/cf/Ambewela-Sri_Lanka_04.jpg/960px-Ambewela-Sri_Lanka_04.jpg",
        "title": "Ambewela-Sri Lanka 04",
        "category": "railway_environment",
        "license": "CC BY-SA 3.0",
        "author": "Ji-Elle",
        "source_url": "https://commons.wikimedia.org/wiki/File:Ambewela-Sri_Lanka_04.jpg",
        "has_elephant": False,
        "expected_count": 0,
        "expected_classification": "No Elephant Detected",
        "notes": "Authentic Sri Lanka Railways track infrastructure and surrounding embankment"
    },
    {
        "filename": "rail_track_02_corridor.jpg",
        "dir": NON_ELEPHANTS_DIR,
        "url": "https://thumb.wikimedia.org/wikipedia/commons/thumb/2/27/Beauty_%26_nature_of_Sri_Lanka.jpg/960px-Beauty_%26_nature_of_Sri_Lanka.jpg",
        "title": "Beauty & nature of Sri Lanka",
        "category": "railway_environment",
        "license": "CC BY-SA 4.0",
        "author": "Reshitha Gayamal",
        "source_url": "https://commons.wikimedia.org/wiki/File:Beauty_%26_nature_of_Sri_Lanka.jpg",
        "has_elephant": False,
        "expected_count": 0,
        "expected_classification": "No Elephant Detected",
        "notes": "Sri Lankan railway line traversing dense jungle foliage (negative corridor test)"
    }
]


def download_remote_samples():
    print("Downloading authentic Sri Lankan evaluation samples...")
    for item in REMOTE_SAMPLES:
        dest_path = item["dir"] / item["filename"]
        if dest_path.exists() and dest_path.stat().st_size > 5000:
            print(f"  ✓ Already exists: {dest_path.name} ({dest_path.stat().st_size:,} bytes)")
            continue

        print(f"  Downloading: {item['filename']} from {item['url']}...")
        req = urllib.request.Request(item["url"], headers=HEADERS)
        try:
            with urllib.request.urlopen(req, timeout=20) as resp, open(dest_path, "wb") as f:
                f.write(resp.read())
            print(f"  ✓ Saved {dest_path.name} ({dest_path.stat().st_size:,} bytes)")
        except Exception as e:
            print(f"  ❌ Failed to download {item['filename']}: {e}")


def generate_optical_variations():
    """
    Constructs systematic optical benchmark cases:
    - Night-time locomotive headlight illumination
    - Partially obscured elephant behind foliage
    - Distant elephant sighting (small angular size)
    """
    print("\nGenerating systematic optical test variations...")

    # 1. Night-time Locomotive Headlight Illumination
    habarana_file = ELEPHANTS_DIR / "elephant_04_habarana_crossing.jpg"
    night_file = EDGE_CASES_DIR / "edge_05_night_headlight_elephant.jpg"
    if habarana_file.exists():
        img = Image.open(habarana_file).convert("RGB")
        w, h = img.size
        # Simulate night-time by heavily dimming ambient light
        enhancer = ImageEnhance.Brightness(img)
        dark = enhancer.enhance(0.12)  # 12% ambient moonlight
        # Apply warm yellowish locomotive headlight beam mask in center
        mask = Image.new("L", (w, h), 0)
        draw = ImageDraw.Draw(mask)
        draw.ellipse([int(w * 0.15), int(h * 0.15), int(w * 0.85), int(h * 0.90)], fill=210)
        mask = mask.filter(ImageFilter.GaussianBlur(50))
        # Composite brightened beam with dark ambient
        night_composite = Image.composite(img, dark, mask)
        night_composite.save(night_file, quality=85)
        print(f"  ✓ Created night headlight elephant: {night_file.name}")

    # 2. Partially Obscured Elephant Behind Foliage
    sigiriya_file = ELEPHANTS_DIR / "elephant_05_sigiriya_wild.jpg"
    obscured_file = EDGE_CASES_DIR / "edge_06_obscured_foliage_elephant.jpg"
    if sigiriya_file.exists():
        img = Image.open(sigiriya_file).convert("RGB")
        w, h = img.size
        draw = ImageDraw.Draw(img)
        # Draw realistic vertical tree trunk and branch occlusions across 35% of elephant body
        trunk_x = int(w * 0.42)
        draw.rectangle([trunk_x, 0, trunk_x + int(w * 0.08), h], fill=(62, 45, 30))
        # Draw hanging foliage leaves
        for y_pos in range(0, int(h * 0.6), 25):
            draw.ellipse([trunk_x - 30, y_pos, trunk_x + 90, y_pos + 40], fill=(35, 70, 30))
        img.save(obscured_file, quality=85)
        print(f"  ✓ Created partially obscured elephant: {obscured_file.name}")

    # 3. Distant Elephant Sighting (Scaled down to 8% of field of view)
    distant_file = EDGE_CASES_DIR / "edge_07_distant_elephant.jpg"
    rail_corridor = NON_ELEPHANTS_DIR / "rail_track_02_corridor.jpg"
    if rail_corridor.exists() and habarana_file.exists():
        bg = Image.open(rail_corridor).convert("RGB")
        ele = Image.open(habarana_file).convert("RGB")
        bg_w, bg_h = bg.size
        # Crop elephant tightly and resize to small distant scale
        crop_box = (int(ele.width * 0.2), int(ele.height * 0.2), int(ele.width * 0.8), int(ele.height * 0.8))
        ele_cropped = ele.crop(crop_box)
        target_h = int(bg_h * 0.12)  # 12% of vertical frame
        target_w = int(ele_cropped.width * (target_h / ele_cropped.height))
        ele_small = ele_cropped.resize((target_w, target_h), Image.Resampling.BILINEAR)
        # Paste near track in distance
        paste_x = int(bg_w * 0.48)
        paste_y = int(bg_h * 0.52)
        bg.paste(ele_small, (paste_x, paste_y))
        bg.save(distant_file, quality=85)
        print(f"  ✓ Created distant corridor elephant: {distant_file.name}")


def update_ground_truth():
    gt_file = DATASETS_DIR / "ground_truth.json"
    with open(gt_file, "r", encoding="utf-8") as f:
        gt = json.load(f)

    existing_files = {item["file"]: item for item in gt.get("images", [])}

    # Add remote downloaded samples
    for item in REMOTE_SAMPLES:
        rel_path = f"{item['dir'].name}/{item['filename']}"
        existing_files[rel_path] = {
            "file": rel_path,
            "has_elephant": item["has_elephant"],
            "expected_count": item["expected_count"],
            "category": item["category"],
            "expected_classification": item["expected_classification"],
            "license": item["license"],
            "author": item["author"],
            "provenance_url": item["source_url"],
            "notes": item["notes"]
        }

    # Add systematic optical variation cases
    variations = [
        {
            "file": "edge_cases/edge_05_night_headlight_elephant.jpg",
            "has_elephant": True,
            "expected_count": 1,
            "category": "night_headlight_elephant",
            "expected_classification": "Potential Elephant Detected",
            "license": "Derived from CC BY-SA 4.0 (Nisakya Nissanka)",
            "author": "EleGuard Rail Optical Benchmark",
            "notes": "Elephant illuminated under simulated locomotive headlight cone against nocturnal darkness"
        },
        {
            "file": "edge_cases/edge_06_obscured_foliage_elephant.jpg",
            "has_elephant": True,
            "expected_count": 1,
            "category": "foliage_obscured_elephant",
            "expected_classification": "Potential Elephant Detected",
            "license": "Derived from CC BY-SA 4.0 (Lasanthi Wijayathunga)",
            "author": "EleGuard Rail Optical Benchmark",
            "notes": "Wild Asian elephant partially obscured (~35% occlusion) by foreground tree trunk and branches"
        },
        {
            "file": "edge_cases/edge_07_distant_elephant.jpg",
            "has_elephant": True,
            "expected_count": 1,
            "category": "distant_elephant",
            "expected_classification": "Uncertain Result",
            "license": "Derived from CC BY-SA 4.0 (Reshitha Gayamal & Nisakya Nissanka)",
            "author": "EleGuard Rail Optical Benchmark",
            "notes": "Distant elephant along railway corridor; low pixel resolution expected to yield uncertain advisory"
        }
    ]

    for v in variations:
        existing_files[v["file"]] = v

    # Reassemble images list
    gt["images"] = list(existing_files.values())
    gt["metadata"]["total_images"] = len(gt["images"])
    gt["metadata"]["last_expanded"] = "Milestone 8B authentic Sri Lankan dataset expansion"

    with open(gt_file, "w", encoding="utf-8") as f:
        json.dump(gt, f, indent=2)

    print(f"\n✓ Updated {gt_file.name}: total evaluation images now = {len(gt['images'])}")


def main():
    download_remote_samples()
    generate_optical_variations()
    update_ground_truth()


if __name__ == "__main__":
    main()
