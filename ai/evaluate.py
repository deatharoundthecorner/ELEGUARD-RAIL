"""
EleGuard Rail - AI Elephant Detector Evaluation Harness
Milestone 8B: Model Performance & Accuracy Benchmarking

Evaluates ElephantDetector (default: YOLOX-Tiny Apache 2.0) against the ground truth
dataset in ai/datasets/ground_truth.json (21 samples).
Generates comprehensive accuracy, confusion matrix, and latency metrics.
Exports results to ai/results/evaluation_report.json.
"""

import json
import os
import sys
import time
import tracemalloc
from pathlib import Path
from typing import Dict, Any, List

# Ensure project root is in sys.path
PROJECT_ROOT = Path(__file__).resolve().parent.parent
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

from ai.detector.detector import ElephantDetector


def run_evaluation(
    model_path: str = None,
    model_type: str = None,
    conf_threshold: float = 0.40,
    uncertain_threshold: float = 0.20,
    save_report: bool = True
) -> Dict[str, Any]:
    """
    Run evaluation benchmark across all ground truth samples.
    """
    tracemalloc.start()
    start_eval = time.perf_counter()

    base_dir = Path(__file__).resolve().parent
    datasets_dir = base_dir / "datasets"
    gt_file = datasets_dir / "ground_truth.json"

    if not gt_file.exists():
        raise FileNotFoundError(f"Ground truth file not found at: {gt_file}")

    with open(gt_file, "r", encoding="utf-8") as f:
        ground_truth_data = json.load(f)

    images = ground_truth_data.get("images", [])
    if not images:
        raise ValueError("Ground truth dataset contains no images.")

    detector = ElephantDetector(
        model_path=model_path,
        model_type=model_type,
        conf_threshold=conf_threshold,
        uncertain_threshold=uncertain_threshold
    )

    results_list: List[Dict[str, Any]] = []
    inference_times: List[float] = []
    total_times: List[float] = []

    # Confusion matrix counters
    tp = 0  # True Positive
    fp = 0  # False Positive
    tn = 0  # True Negative
    fn = 0  # False Negative
    uncertain_cases = 0
    exact_category_matches = 0

    print(f"\n========================================================")
    print(f" EleGuard Rail - Milestone 8B Detector Evaluation")
    print(f" Model: {detector.model_version} [{detector.model_license}]")
    print(f" Conf Threshold: {conf_threshold:.2f} | Uncertain: {uncertain_threshold:.2f}")
    print(f" Total Evaluation Samples: {len(images)}")
    print(f"========================================================\n")

    for idx, item in enumerate(images, start=1):
        rel_path = item["file"]
        full_path = datasets_dir / rel_path
        has_elephant = item["has_elephant"]
        expected_class = item.get("expected_classification", "Potential Elephant Detected" if has_elephant else "No Elephant Detected")
        category = item.get("category", "unspecified")

        if not full_path.exists():
            print(f"[{idx}/{len(images)}] ERROR: File missing: {rel_path}")
            continue

        det_result = detector.detect(full_path)
        actual_class = det_result["classification"]
        detected = det_result["elephant_detected"]
        max_conf = det_result["max_confidence"]
        inf_time = det_result["inference_time_ms"]
        tot_time = det_result["total_time_ms"]

        inference_times.append(inf_time)
        total_times.append(tot_time)

        # Categorical match check
        is_exact_match = (actual_class == expected_class)
        if is_exact_match:
            exact_category_matches += 1

        # Binary confusion matrix categorization
        if has_elephant:
            if detected:
                tp += 1
                outcome = "TP (True Positive)"
            elif actual_class == "Uncertain Result":
                uncertain_cases += 1
                outcome = "UNCERTAIN (Advisory Flag)"
            else:
                fn += 1
                outcome = "FN (False Negative)"
        else:
            if detected:
                fp += 1
                outcome = "FP (False Positive)"
            else:
                tn += 1
                outcome = "TN (True Negative)"

        status_marker = "✓" if is_exact_match else "~"
        print(
            f"[{idx:02d}/{len(images):02d}] {status_marker} {rel_path:<42} "
            f"| Exp: {expected_class:<27} | Act: {actual_class:<27} "
            f"| Conf: {max_conf * 100:>5.1f}% | {inf_time:>5.1f}ms | {outcome}"
        )

        results_list.append({
            "image": rel_path,
            "category": category,
            "has_elephant_ground_truth": has_elephant,
            "expected_classification": expected_class,
            "actual_classification": actual_class,
            "exact_match": is_exact_match,
            "outcome": outcome,
            "max_confidence": max_conf,
            "estimated_count": det_result["estimated_count"],
            "inference_time_ms": inf_time,
            "total_time_ms": tot_time,
            "detections": det_result["detections"]
        })

    total_eval_time = round((time.perf_counter() - start_eval), 3)
    current_mem, peak_mem = tracemalloc.get_traced_memory()
    tracemalloc.stop()

    peak_mem_mb = round(peak_mem / (1024 * 1024), 2)
    avg_inf_time = round(sum(inference_times) / len(inference_times), 2) if inference_times else 0.0
    min_inf_time = round(min(inference_times), 2) if inference_times else 0.0
    max_inf_time = round(max(inference_times), 2) if inference_times else 0.0
    avg_tot_time = round(sum(total_times) / len(total_times), 2) if total_times else 0.0

    # Compute precision, recall, f1, accuracy
    total_samples = len(results_list)
    precision = round(tp / (tp + fp), 4) if (tp + fp) > 0 else 0.0
    recall = round(tp / (tp + fn), 4) if (tp + fn) > 0 else 0.0
    f1_score = round(2 * (precision * recall) / (precision + recall), 4) if (precision + recall) > 0 else 0.0
    binary_accuracy = round((tp + tn) / total_samples, 4) if total_samples > 0 else 0.0
    exact_match_accuracy = round(exact_category_matches / total_samples, 4) if total_samples > 0 else 0.0

    report = {
        "metadata": {
            "project": "EleGuard Rail",
            "milestone": "8B - Permissive YOLOX-Tiny Prototype Adoption",
            "model_version": detector.model_version,
            "model_type": detector.model_type,
            "model_license": detector.model_license,
            "evaluated_at": time.strftime("%Y-%m-%d %H:%M:%S UTC", time.gmtime()),
            "device": "CPU (CPUExecutionProvider)",
            "conf_threshold": conf_threshold,
            "uncertain_threshold": uncertain_threshold,
            "total_samples": total_samples,
        },
        "confusion_matrix": {
            "true_positives": tp,
            "false_positives": fp,
            "true_negatives": tn,
            "false_negatives": fn,
            "uncertain_cases": uncertain_cases,
        },
        "metrics": {
            "precision": precision,
            "recall": recall,
            "f1_score": f1_score,
            "binary_accuracy": binary_accuracy,
            "exact_classification_accuracy": exact_match_accuracy,
            "false_positive_rate": round(fp / (fp + tn), 4) if (fp + tn) > 0 else 0.0,
        },
        "performance": {
            "average_inference_time_ms": avg_inf_time,
            "min_inference_time_ms": min_inf_time,
            "max_inference_time_ms": max_inf_time,
            "average_total_pipeline_time_ms": avg_tot_time,
            "peak_memory_traced_mb": peak_mem_mb,
            "total_evaluation_duration_seconds": total_eval_time,
        },
        "sample_results": results_list,
    }

    print(f"\n--------------------------------------------------------")
    print(f" EVALUATION SUMMARY")
    print(f"--------------------------------------------------------")
    print(f" True Positives (TP):  {tp:<4} | False Positives (FP): {fp}")
    print(f" True Negatives (TN):  {tn:<4} | False Negatives (FN): {fn}")
    print(f" Uncertain Reviews:    {uncertain_cases}")
    print(f"--------------------------------------------------------")
    print(f" Precision:            {precision * 100:.2f}%")
    print(f" Recall:               {recall * 100:.2f}%")
    print(f" F1-Score:             {f1_score * 100:.2f}%")
    print(f" Exact Match Accuracy: {exact_match_accuracy * 100:.2f}%")
    print(f"--------------------------------------------------------")
    print(f" Avg Inference Latency:{avg_inf_time:.2f} ms / image (CPU)")
    print(f" Total Pipeline Time:  {avg_tot_time:.2f} ms / image")
    print(f" Peak Traced Memory:   {peak_mem_mb:.2f} MB")
    print(f"========================================================\n")

    if save_report:
        results_dir = base_dir / "results"
        results_dir.mkdir(parents=True, exist_ok=True)
        report_path = results_dir / "evaluation_report.json"
        with open(report_path, "w", encoding="utf-8") as f:
            json.dump(report, f, indent=2)
        print(f"Saved evaluation report to: {report_path}")

    return report


if __name__ == "__main__":
    run_evaluation()
