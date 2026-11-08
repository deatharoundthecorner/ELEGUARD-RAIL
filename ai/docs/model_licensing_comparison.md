# EleGuard Rail — Object Detection Model Licensing & Technical Comparison
## Milestone 8B: Permissive Licensing Review for Elephant Detection

**Context:** The project has decided not to release the entire EleGuard Rail codebase under AGPL-3.0 solely because of the YOLOv8 prototype. This document establishes an independent licensing and technical comparison of candidate lightweight object detection models to replace YOLOv8 with a **permissively licensed (Apache 2.0 / BSD / MIT)** model.

---

## 1. Master Licensing Comparison Matrix

| Model Candidate | Source Repository | Code Licence | Weights Licence | Dataset Provenance | Commercial Use? | Redistribution? | Attribution Requirements | Final Licensing Suitability |
| :--- | :--- | :--- | :--- | :--- | :---: | :---: | :--- | :--- |
| **YOLOX-Tiny** | `Megvii-BaseDetection/YOLOX` | **Apache 2.0** | **Apache 2.0** (Official release) | Microsoft COCO (CC BY 4.0) | **Permitted** | **Permitted** | Include Apache 2.0 Notice & Copyright | **APPROVED (Permissive — Optimal)** |
| **YOLOX-Nano** | `Megvii-BaseDetection/YOLOX` | **Apache 2.0** | **Apache 2.0** (Official release) | Microsoft COCO (CC BY 4.0) | **Permitted** | **Permitted** | Include Apache 2.0 Notice & Copyright | **APPROVED (Permissive — Ultra-light)** |
| **SSD-MobileNetV1** | `onnx/models` (ONNX Zoo) | **Apache 2.0** | **Apache 2.0 / MIT** | Microsoft COCO (CC BY 4.0) | **Permitted** | **Permitted** | Include Apache/MIT Notice | **APPROVED (Permissive)** |
| **SSDLite-MobileNetV3** | `pytorch/vision` (torchvision) | **BSD 3-Clause** | **BSD 3-Clause** | Microsoft COCO (CC BY 4.0) | **Permitted** | **Permitted** | Include BSD 3-Clause Notice | **APPROVED (Permissive)** |
| **YOLOv6 (v3.0)** | `meituan/YOLOv6` | **GPL-3.0** | **GPL-3.0** | Microsoft COCO (CC BY 4.0) | Restrictive | Restrictive (Copyleft) | Full GPL-3.0 source distribution | **REJECTED (Copyleft Conflict)** |
| **YOLOv7** | `WongKinYiu/yolov7` | **GPL-3.0** | **GPL-3.0** | Microsoft COCO (CC BY 4.0) | Restrictive | Restrictive (Copyleft) | Full GPL-3.0 source distribution | **REJECTED (Copyleft Conflict)** |
| **YOLOv8n** (Baseline) | `ultralytics/ultralytics` | **AGPL-3.0** | **AGPL-3.0** | Microsoft COCO (CC BY 4.0) | Copyleft / Commercial Paid | Copyleft (Network clause) | Full AGPL-3.0 source distribution | **REJECTED by User Decision** |

---

## 2. Detailed Candidate Licensing Audits

### 2.1 Megvii YOLOX (YOLOX-Tiny & YOLOX-Nano)
- **Primary Repository:** `https://github.com/Megvii-BaseDetection/YOLOX`
- **Code License:** Apache License 2.0.
- **Pretrained Weights License:** Megvii explicitly released all model checkpoints and pre-exported ONNX models under the Apache 2.0 license via GitHub release `0.1.1rc0`.
- **Network Copyleft Clause (AGPL Section 13):** **None.** Apache 2.0 allows embedding the model inside private, proprietary, or custom-licensed web platforms without requiring the caller application to open-source its code.
- **Attribution:** Requires including the original Megvii copyright notice and Apache 2.0 license text in third-party distributions.

### 2.2 SSD-MobileNetV1 (ONNX Model Zoo / Google)
- **Primary Repository:** `https://github.com/onnx/models` (Validated vision models)
- **Code License:** Apache License 2.0 / MIT.
- **Pretrained Weights License:** Apache 2.0. Exported directly from TensorFlow Object Detection API COCO checkpoints.
- **Network Copyleft Clause:** **None.** Permissive for commercial and non-commercial deployments.

### 2.3 Meituan YOLOv6 (Why It Was Rejected)
- **Common Misconception:** Early blog posts and preprint papers from 2022 cited YOLOv6 under Apache 2.0.
- **Verification Finding:** In late 2022 / early 2023, Meituan officially changed the license of the `YOLOv6` repository to the **GNU General Public License v3.0 (GPL-3.0)**.
- **Conclusion:** Because GPL-3.0 is a strong copyleft license that restricts commercial proprietary bundling and requires downstream code to inherit GPL-3.0, YOLOv6 does not meet our requirement for permissive licensing.

---

## 3. Quantitative Technical Benchmark on 21 Ground-Truth Images

All four models were benchmarked under identical conditions using the exact same 21-image Sri Lankan dataset (`ai/datasets/ground_truth.json`):

| Model Candidate | License | Model Size | CPU Latency | Precision | Recall | F1-Score | Exact Matches | Verdict |
| :--- | :--- | :---: | :---: | :---: | :---: | :---: | :---: | :--- |
| **YOLOX-Tiny** | **Apache 2.0** | **19.28 MB** | **16.27 ms** | **100.0%** | **91.7%** | **95.7%** | **90.5% (19/21)** | **BEST OVERALL** |
| **SSD-MobileNetV1** | **Apache 2.0** | 27.92 MB | 16.45 ms | 100.0% | 83.3% | 90.9% | 85.7% (18/21) | Strong Second |
| **YOLOX-Nano** | **Apache 2.0** | 3.49 MB | 7.93 ms | 100.0% | 75.0% | 85.7% | 90.5% (19/21) | Ultra-light Alternative |
| **YOLOv8n** (Baseline) | AGPL-3.0 | 12.26 MB | 25.29 ms | 100.0% | 75.0% | 85.7% | 90.5% (19/21) | Replaced (Copyleft) |

### Key Benchmark Observations:
1. **Recall Advantage:** YOLOX-Tiny detected **11 out of 12** ground-truth elephants, failing only on the synthetic severe Gaussian blur test (`edge_02`). It successfully detected `elephant_07_kaudulla_brush.jpg` (dense dry-zone scrub), which YOLOv8n missed.
2. **Precision Discipline:** Every candidate maintained a **100.0% precision rate** (0 false positives across authentic Sri Lankan railway tracks, locomotives, vehicles, dogs, buffaloes, and landscapes).
3. **Speed Superiority:** YOLOX-Tiny executed at **16.27 ms / image** on CPU (35% faster than YOLOv8n's 25.29 ms). YOLOX-Nano ran at **7.93 ms / image**.

---

## 4. Final Recommendation

**Recommended Replacement Model:** **`YOLOX-Tiny`** (`yolox_tiny.onnx`, 19.28 MB)

### Justification:
1. **100% Permissive (Apache 2.0):** Complete commercial and closed-source freedom with zero network copyleft triggers.
2. **Highest Elephant Detection Recall (91.7%):** Outperformed both YOLOv8n and SSD-MobileNet on Sri Lankan brush and wild crossing imagery.
3. **High CPU Efficiency:** 16.3 ms latency with ~11 MB RAM footprint on ordinary CPU.
4. **Drop-in Architecture Compatibility:** Uses standard ONNX Runtime session and anchor coordinates, cleanly integrating into `ElephantDetector`.
