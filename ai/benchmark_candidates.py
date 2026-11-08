"""
EleGuard Rail - Candidate Model Benchmarking Harness
Milestone 8B: Comparative Evaluation of Permissive Models vs YOLOv8n

Evaluates:
1. YOLOv8n (nano) - Baseline [AGPL-3.0]
2. YOLOX-Nano - Megvii [Apache 2.0]
3. YOLOX-Tiny - Megvii [Apache 2.0]
4. SSD-MobileNetV1 - ONNX Model Zoo / Google [Apache 2.0 / MIT]

All models are evaluated on the exact same 21-image Sri Lankan wildlife & railway dataset.
"""

import json
import os
import sys
import time
import tracemalloc
from pathlib import Path
from typing import Dict, Any, List, Tuple
import numpy as np
from PIL import Image
import onnxruntime as ort

PROJECT_ROOT = Path(__file__).resolve().parent.parent
DATASETS_DIR = PROJECT_ROOT / "ai" / "datasets"
GT_FILE = DATASETS_DIR / "ground_truth.json"
MODELS_DIR = PROJECT_ROOT / "ai" / "models"
CANDIDATES_DIR = MODELS_DIR / "candidates"
RESULTS_DIR = PROJECT_ROOT / "ai" / "results"


class BenchmarkEngine:
    def __init__(self, gt_path: Path):
        with open(gt_path, "r", encoding="utf-8") as f:
            self.gt_data = json.load(f)
        self.images = self.gt_data.get("images", [])

    def evaluate_yolov8(self, model_path: Path) -> Dict[str, Any]:
        """Evaluate baseline YOLOv8n model."""
        session = ort.InferenceSession(str(model_path), providers=["CPUExecutionProvider"])
        input_name = session.get_inputs()[0].name
        output_name = session.get_outputs()[0].name
        file_size_mb = round(model_path.stat().st_size / (1024 * 1024), 2)

        latencies = []
        tp, fp, tn, fn, unc = 0, 0, 0, 0, 0
        exact_matches = 0
        per_image_results = []

        tracemalloc.start()
        for item in self.images:
            img_path = DATASETS_DIR / item["file"]
            has_ele = item["has_elephant"]
            expected = item.get("expected_classification", "Potential Elephant Detected" if has_ele else "No Elephant Detected")

            img = Image.open(img_path).convert("RGB")
            w, h = img.size
            scale = min(640 / w, 640 / h)
            nw, nh = int(w * scale), int(h * scale)
            resized = img.resize((nw, nh), Image.Resampling.BILINEAR)
            canvas = Image.new("RGB", (640, 640), (114, 114, 114))
            canvas.paste(resized, ((640 - nw) // 2, (640 - nh) // 2))
            arr = np.array(canvas, dtype=np.float32) / 255.0
            tensor = np.expand_dims(np.transpose(arr, (2, 0, 1)), 0)

            t0 = time.perf_counter()
            out = session.run([output_name], {input_name: tensor})[0]
            lat = (time.perf_counter() - t0) * 1000.0
            latencies.append(lat)

            # Class 20 = elephant in YOLOv8
            preds = out[0]
            ele_scores = preds[4 + 20, :]
            max_conf = float(np.max(ele_scores)) if ele_scores.size > 0 else 0.0

            if max_conf >= 0.40:
                classification = "Potential Elephant Detected"
            elif max_conf >= 0.20:
                classification = "Uncertain Result"
            else:
                classification = "No Elephant Detected"

            if classification == expected:
                exact_matches += 1

            if has_ele:
                if classification == "Potential Elephant Detected":
                    tp += 1
                elif classification == "Uncertain Result":
                    unc += 1
                else:
                    fn += 1
            else:
                if classification == "Potential Elephant Detected":
                    fp += 1
                else:
                    tn += 1

            per_image_results.append({
                "file": item["file"],
                "expected": expected,
                "actual": classification,
                "confidence": round(max_conf, 4),
                "latency_ms": round(lat, 2)
            })

        _, peak_mem = tracemalloc.get_traced_memory()
        tracemalloc.stop()

        precision = round(tp / (tp + fp), 4) if (tp + fp) > 0 else 0.0
        recall = round(tp / (tp + fn), 4) if (tp + fn) > 0 else 0.0
        f1 = round(2 * (precision * recall) / (precision + recall), 4) if (precision + recall) > 0 else 0.0

        return {
            "model_name": "YOLOv8n (Ultralytics)",
            "license": "AGPL-3.0 (Copyleft)",
            "license_type": "Copyleft (Network clause)",
            "file_size_mb": file_size_mb,
            "avg_latency_ms": round(float(np.mean(latencies)), 2),
            "peak_memory_mb": round(peak_mem / (1024 * 1024), 2),
            "true_positives": tp,
            "false_positives": fp,
            "true_negatives": tn,
            "false_negatives": fn,
            "uncertain_reviews": unc,
            "precision": precision,
            "recall": recall,
            "f1_score": f1,
            "exact_match_accuracy": round(exact_matches / len(self.images), 4),
            "details": per_image_results
        }

    def evaluate_yolox(self, model_path: Path, model_display_name: str) -> Dict[str, Any]:
        """Evaluate YOLOX candidate model (Nano or Tiny)."""
        session = ort.InferenceSession(str(model_path), providers=["CPUExecutionProvider"])
        input_name = session.get_inputs()[0].name
        output_name = session.get_outputs()[0].name
        file_size_mb = round(model_path.stat().st_size / (1024 * 1024), 2)

        latencies = []
        tp, fp, tn, fn, unc = 0, 0, 0, 0, 0
        exact_matches = 0
        per_image_results = []

        tracemalloc.start()
        for item in self.images:
            img_path = DATASETS_DIR / item["file"]
            has_ele = item["has_elephant"]
            expected = item.get("expected_classification", "Potential Elephant Detected" if has_ele else "No Elephant Detected")

            img = Image.open(img_path).convert("RGB")
            w, h = img.size
            scale = min(416 / w, 416 / h)
            nw, nh = int(w * scale), int(h * scale)
            resized = img.resize((nw, nh), Image.Resampling.BILINEAR)
            canvas = Image.new("RGB", (416, 416), (114, 114, 114))
            canvas.paste(resized, ((416 - nw) // 2, (416 - nh) // 2))
            arr = np.array(canvas, dtype=np.float32)[:, :, ::-1]  # BGR [0, 255]
            tensor = np.expand_dims(np.transpose(arr, (2, 0, 1)), 0)

            t0 = time.perf_counter()
            out = session.run([output_name], {input_name: tensor})[0]
            lat = (time.perf_counter() - t0) * 1000.0
            latencies.append(lat)

            # In YOLOX, out shape is (1, 3549, 85)
            # score = obj_conf * cls_conf (class 20 = elephant -> element 5 + 20 = 25)
            obj_conf = out[0, :, 4]
            cls_conf = out[0, :, 5 + 20]
            scores = obj_conf * cls_conf
            max_conf = float(np.max(scores)) if scores.size > 0 else 0.0

            # Calibrated thresholds: confident >= 0.40, uncertain >= 0.20
            if max_conf >= 0.40:
                classification = "Potential Elephant Detected"
            elif max_conf >= 0.20:
                classification = "Uncertain Result"
            else:
                classification = "No Elephant Detected"

            if classification == expected:
                exact_matches += 1

            if has_ele:
                if classification == "Potential Elephant Detected":
                    tp += 1
                elif classification == "Uncertain Result":
                    unc += 1
                else:
                    fn += 1
            else:
                if classification == "Potential Elephant Detected":
                    fp += 1
                else:
                    tn += 1

            per_image_results.append({
                "file": item["file"],
                "expected": expected,
                "actual": classification,
                "confidence": round(max_conf, 4),
                "latency_ms": round(lat, 2)
            })

        _, peak_mem = tracemalloc.get_traced_memory()
        tracemalloc.stop()

        precision = round(tp / (tp + fp), 4) if (tp + fp) > 0 else 0.0
        recall = round(tp / (tp + fn), 4) if (tp + fn) > 0 else 0.0
        f1 = round(2 * (precision * recall) / (precision + recall), 4) if (precision + recall) > 0 else 0.0

        return {
            "model_name": model_display_name,
            "license": "Apache 2.0 (Permissive)",
            "license_type": "Permissive (Commercial & private friendly)",
            "file_size_mb": file_size_mb,
            "avg_latency_ms": round(float(np.mean(latencies)), 2),
            "peak_memory_mb": round(peak_mem / (1024 * 1024), 2),
            "true_positives": tp,
            "false_positives": fp,
            "true_negatives": tn,
            "false_negatives": fn,
            "uncertain_reviews": unc,
            "precision": precision,
            "recall": recall,
            "f1_score": f1,
            "exact_match_accuracy": round(exact_matches / len(self.images), 4),
            "details": per_image_results
        }

    def evaluate_ssd_mobilenet(self, model_path: Path) -> Dict[str, Any]:
        """Evaluate SSD-MobileNetV1 candidate model."""
        session = ort.InferenceSession(str(model_path), providers=["CPUExecutionProvider"])
        input_name = session.get_inputs()[0].name
        file_size_mb = round(model_path.stat().st_size / (1024 * 1024), 2)

        latencies = []
        tp, fp, tn, fn, unc = 0, 0, 0, 0, 0
        exact_matches = 0
        per_image_results = []

        tracemalloc.start()
        for item in self.images:
            img_path = DATASETS_DIR / item["file"]
            has_ele = item["has_elephant"]
            expected = item.get("expected_classification", "Potential Elephant Detected" if has_ele else "No Elephant Detected")

            img = Image.open(img_path).convert("RGB")
            tensor = np.expand_dims(np.array(img, dtype=np.uint8), 0)

            t0 = time.perf_counter()
            boxes, classes, scores, num_dets = session.run(None, {input_name: tensor})
            lat = (time.perf_counter() - t0) * 1000.0
            latencies.append(lat)

            # In TF COCO 90-class label map, class 22 is elephant
            max_conf = 0.0
            for i in range(int(num_dets[0])):
                if int(classes[0][i]) == 22:
                    max_conf = max(max_conf, float(scores[0][i]))

            if max_conf >= 0.40:
                classification = "Potential Elephant Detected"
            elif max_conf >= 0.20:
                classification = "Uncertain Result"
            else:
                classification = "No Elephant Detected"

            if classification == expected:
                exact_matches += 1

            if has_ele:
                if classification == "Potential Elephant Detected":
                    tp += 1
                elif classification == "Uncertain Result":
                    unc += 1
                else:
                    fn += 1
            else:
                if classification == "Potential Elephant Detected":
                    fp += 1
                else:
                    tn += 1

            per_image_results.append({
                "file": item["file"],
                "expected": expected,
                "actual": classification,
                "confidence": round(max_conf, 4),
                "latency_ms": round(lat, 2)
            })

        _, peak_mem = tracemalloc.get_traced_memory()
        tracemalloc.stop()

        precision = round(tp / (tp + fp), 4) if (tp + fp) > 0 else 0.0
        recall = round(tp / (tp + fn), 4) if (tp + fn) > 0 else 0.0
        f1 = round(2 * (precision * recall) / (precision + recall), 4) if (precision + recall) > 0 else 0.0

        return {
            "model_name": "SSD-MobileNetV1 (ONNX Zoo)",
            "license": "Apache 2.0 / MIT (Permissive)",
            "license_type": "Permissive (Commercial & private friendly)",
            "file_size_mb": file_size_mb,
            "avg_latency_ms": round(float(np.mean(latencies)), 2),
            "peak_memory_mb": round(peak_mem / (1024 * 1024), 2),
            "true_positives": tp,
            "false_positives": fp,
            "true_negatives": tn,
            "false_negatives": fn,
            "uncertain_reviews": unc,
            "precision": precision,
            "recall": recall,
            "f1_score": f1,
            "exact_match_accuracy": round(exact_matches / len(self.images), 4),
            "details": per_image_results
        }


def main():
    engine = BenchmarkEngine(GT_FILE)
    print("=================================================================")
    print(" EleGuard Rail - Milestone 8B Permissive Model Candidate Benchmark")
    print(" Total Dataset Evaluation Samples: 21 (Sri Lankan & Benchmark)")
    print("=================================================================\n")

    results = []

    # 1. Baseline: YOLOv8n (AGPL-3.0)
    yolov8_path = MODELS_DIR / "yolov8n.onnx"
    if yolov8_path.exists():
        print("Benchmarking Baseline: YOLOv8n (AGPL-3.0)...")
        res_v8 = engine.evaluate_yolov8(yolov8_path)
        results.append(res_v8)
        print(f"  -> Precision: {res_v8['precision']*100:.1f}%, Recall: {res_v8['recall']*100:.1f}%, Latency: {res_v8['avg_latency_ms']:.1f}ms")

    # 2. Candidate: YOLOX-Nano (Apache 2.0)
    nano_path = CANDIDATES_DIR / "yolox_nano.onnx"
    if nano_path.exists():
        print("Benchmarking Candidate 1: YOLOX-Nano (Apache 2.0)...")
        res_nano = engine.evaluate_yolox(nano_path, "YOLOX-Nano (Megvii)")
        results.append(res_nano)
        print(f"  -> Precision: {res_nano['precision']*100:.1f}%, Recall: {res_nano['recall']*100:.1f}%, Latency: {res_nano['avg_latency_ms']:.1f}ms")

    # 3. Candidate: YOLOX-Tiny (Apache 2.0)
    tiny_path = CANDIDATES_DIR / "yolox_tiny.onnx"
    if tiny_path.exists():
        print("Benchmarking Candidate 2: YOLOX-Tiny (Apache 2.0)...")
        res_tiny = engine.evaluate_yolox(tiny_path, "YOLOX-Tiny (Megvii)")
        results.append(res_tiny)
        print(f"  -> Precision: {res_tiny['precision']*100:.1f}%, Recall: {res_tiny['recall']*100:.1f}%, Latency: {res_tiny['avg_latency_ms']:.1f}ms")

    # 4. Candidate: SSD-MobileNetV1 (Apache 2.0 / MIT)
    ssd_path = CANDIDATES_DIR / "ssd_mobilenet_v1_10.onnx"
    if ssd_path.exists():
        print("Benchmarking Candidate 3: SSD-MobileNetV1 (Apache 2.0 / MIT)...")
        res_ssd = engine.evaluate_ssd_mobilenet(ssd_path)
        results.append(res_ssd)
        print(f"  -> Precision: {res_ssd['precision']*100:.1f}%, Recall: {res_ssd['recall']*100:.1f}%, Latency: {res_ssd['avg_latency_ms']:.1f}ms")

    # Output comparative report
    RESULTS_DIR.mkdir(parents=True, exist_ok=True)
    report_file = RESULTS_DIR / "candidate_models_benchmark.json"
    with open(report_file, "w", encoding="utf-8") as f:
        json.dump({"timestamp": time.strftime("%Y-%m-%d %H:%M:%S UTC"), "models": results}, f, indent=2)

    print("\n" + "="*85)
    print(f"{'Model Candidate':<25} | {'License':<15} | {'Size':<8} | {'Latency':<9} | {'Prec':<7} | {'Recall':<7} | {'F1':<7}")
    print("="*85)
    for r in results:
        print(
            f"{r['model_name']:<25} | {r['license'][:15]:<15} | "
            f"{str(r['file_size_mb'])+'MB':<8} | {str(r['avg_latency_ms'])+'ms':<9} | "
            f"{r['precision']*100:>5.1f}% | {r['recall']*100:>5.1f}% | {r['f1_score']*100:>5.1f}%"
        )
    print("="*85)
    print(f"\nSaved benchmark results to: {report_file}")


if __name__ == "__main__":
    main()
