"""
EleGuard Rail - Automated Test Suite for AI Elephant Detector
Milestone 8B: Permissive Model Adoption (YOLOX-Tiny) & Baseline Verification

Covers:
- Positive single and herd elephant detections with default YOLOX-Tiny
- Baseline YOLOv8 backward compatibility
- Negative animal, vehicle, and railway track rejections (zero false positives)
- Uncertain edge-case calibration
- Low-light and night-time headlight resilience
- File validation (corrupted, empty, oversized, unsupported format, missing)
- Model initialization, missing model, and invalid model handling
- Bounding-box coordinate correctness and containment
- Annotation visualization
- CLI interface and JSON output contracts
"""

import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from PIL import Image

# Ensure project root is in sys.path
PROJECT_ROOT = Path(__file__).resolve().parent.parent.parent
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

from ai.detector.detector import (
    ElephantDetector,
    ImageValidationError,
    ModelNotFoundError,
    InvalidModelError,
)


class TestElephantDetector(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.detector = ElephantDetector()  # Defaults to YOLOX-Tiny
        cls.datasets_dir = PROJECT_ROOT / "ai" / "datasets"
        cls.asian_path = cls.datasets_dir / "elephants" / "elephant_01_asian.jpg"
        cls.african_path = cls.datasets_dir / "elephants" / "elephant_02_african.jpg"
        cls.herd_path = cls.datasets_dir / "elephants" / "elephant_03_herd.jpg"
        cls.habarana_path = cls.datasets_dir / "elephants" / "elephant_04_habarana_crossing.jpg"
        cls.buffalo_path = cls.datasets_dir / "non_elephants" / "buffalo_01.jpg"
        cls.train_path = cls.datasets_dir / "non_elephants" / "train_01.jpg"
        cls.track_path = cls.datasets_dir / "non_elephants" / "rail_track_01_ambewela.jpg"
        cls.dog_path = cls.datasets_dir / "non_elephants" / "dog_01.jpg"
        cls.vehicle_path = cls.datasets_dir / "non_elephants" / "vehicle_01.jpg"
        cls.landscape_path = cls.datasets_dir / "non_elephants" / "landscape_01.jpg"
        cls.low_light_path = cls.datasets_dir / "edge_cases" / "edge_01_low_light_elephant.jpg"
        cls.uncertain_path = cls.datasets_dir / "edge_cases" / "edge_04_uncertain_elephant.jpg"
        cls.yolov8_model_path = PROJECT_ROOT / "ai" / "models" / "yolov8n.onnx"

        # Warmup CPU inference session to avoid cold OS disk cache latency in tests
        cls.detector.detect(cls.asian_path)

    def test_01_valid_single_elephant_detection(self):
        """Asian elephant image should be classified as Potential Elephant Detected with high confidence."""
        res = self.detector.detect(self.asian_path)
        self.assertEqual(res["status"], "success")
        self.assertEqual(res["classification"], "Potential Elephant Detected")
        self.assertTrue(res["elephant_detected"])
        self.assertGreaterEqual(res["estimated_count"], 1)
        self.assertGreaterEqual(res["max_confidence"], 0.85)
        self.assertIn("Advisory only:", res["disclaimer"])
        self.assertLess(res["inference_time_ms"], 250.0)

    def test_02_herd_elephant_detection(self):
        """Herd image should detect multiple elephants."""
        res = self.detector.detect(self.herd_path)
        self.assertEqual(res["status"], "success")
        self.assertEqual(res["classification"], "Potential Elephant Detected")
        self.assertTrue(res["elephant_detected"])
        self.assertGreaterEqual(res["estimated_count"], 2)
        self.assertGreaterEqual(res["max_confidence"], 0.85)

    def test_03_negative_animal_buffalo_rejected(self):
        """Water buffalo should NOT be falsely detected as an elephant."""
        res = self.detector.detect(self.buffalo_path)
        self.assertEqual(res["status"], "success")
        self.assertEqual(res["classification"], "No Elephant Detected")
        self.assertFalse(res["elephant_detected"])
        self.assertEqual(res["estimated_count"], 0)
        self.assertLess(res["max_confidence"], 0.20)

    def test_04_negative_train_rejected(self):
        """Train/locomotive image must NOT be falsely detected as an elephant."""
        res = self.detector.detect(self.train_path)
        self.assertEqual(res["status"], "success")
        self.assertEqual(res["classification"], "No Elephant Detected")
        self.assertFalse(res["elephant_detected"])
        self.assertEqual(res["estimated_count"], 0)
        self.assertLess(res["max_confidence"], 0.20)

    def test_05_negative_dog_rejected(self):
        """Domestic canine should NOT trigger false elephant detection."""
        res = self.detector.detect(self.dog_path)
        self.assertEqual(res["status"], "success")
        self.assertEqual(res["classification"], "No Elephant Detected")
        self.assertFalse(res["elephant_detected"])

    def test_06_negative_landscape_rejected(self):
        """Empty landscape background should yield zero detections."""
        res = self.detector.detect(self.landscape_path)
        self.assertEqual(res["status"], "success")
        self.assertEqual(res["classification"], "No Elephant Detected")
        self.assertFalse(res["elephant_detected"])

    def test_07_low_light_elephant_detected(self):
        """Low-light / twilight elephant image should still be detected."""
        res = self.detector.detect(self.low_light_path)
        self.assertEqual(res["status"], "success")
        self.assertEqual(res["classification"], "Potential Elephant Detected")
        self.assertTrue(res["elephant_detected"])
        self.assertGreaterEqual(res["max_confidence"], 0.80)

    def test_08_uncertain_result_calibration(self):
        """Degraded/blurred elephant image must fall into the Uncertain Result advisory state."""
        res = self.detector.detect(self.uncertain_path)
        self.assertEqual(res["status"], "success")
        self.assertEqual(res["classification"], "Uncertain Result")
        self.assertFalse(res["elephant_detected"])
        self.assertGreaterEqual(res["max_confidence"], 0.20)
        self.assertLess(res["max_confidence"], 0.40)
        self.assertIn("Uncertain", res["classification"])

    def test_09_corrupted_image_handling(self):
        """Passing corrupted byte data must raise ImageValidationError."""
        with tempfile.NamedTemporaryFile(suffix=".jpg", delete=False) as f:
            f.write(b"\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01NOT_A_VALID_IMAGE_DATA")
            temp_path = f.name
        try:
            with self.assertRaises(ImageValidationError):
                self.detector.detect(temp_path)
        finally:
            if os.path.exists(temp_path):
                os.remove(temp_path)

    def test_10_empty_image_handling(self):
        """Passing a 0-byte file must raise ImageValidationError."""
        with tempfile.NamedTemporaryFile(suffix=".jpg", delete=False) as f:
            temp_path = f.name
        try:
            with self.assertRaises(ImageValidationError):
                self.detector.detect(temp_path)
        finally:
            if os.path.exists(temp_path):
                os.remove(temp_path)

    def test_11_unsupported_extension_handling(self):
        """Passing a non-image extension (e.g. .txt, .exe) must raise ImageValidationError."""
        with tempfile.NamedTemporaryFile(suffix=".txt", delete=False) as f:
            f.write(b"EleGuard text payload")
            temp_path = f.name
        try:
            with self.assertRaises(ImageValidationError):
                self.detector.detect(temp_path)
        finally:
            if os.path.exists(temp_path):
                os.remove(temp_path)

    def test_12_oversized_image_handling(self):
        """Image exceeding max_file_size_bytes must raise ImageValidationError."""
        detector_small_limit = ElephantDetector(max_file_size_bytes=1024)
        with self.assertRaises(ImageValidationError):
            detector_small_limit.detect(self.asian_path)

    def test_13_nonexistent_file_handling(self):
        """Non-existent file path must raise ImageValidationError."""
        with self.assertRaises(ImageValidationError):
            self.detector.detect("non_existent_file_xyz_12345.jpg")

    def test_14_invalid_model_path_handling(self):
        """Initializing detector with missing model path must raise ModelNotFoundError."""
        with self.assertRaises(ModelNotFoundError):
            ElephantDetector(model_path="missing_model_path_xyz.onnx")

    def test_15_draw_annotations(self):
        """draw_annotations should create a visual image file with bounding boxes."""
        res = self.detector.detect(self.asian_path)
        with tempfile.NamedTemporaryFile(suffix=".jpg", delete=False) as f:
            temp_out = f.name

        try:
            out_path = self.detector.draw_annotations(
                self.asian_path,
                res["detections"],
                output_path=temp_out
            )
            self.assertTrue(os.path.exists(out_path))
            self.assertGreater(os.path.getsize(out_path), 1000)

            with Image.open(out_path) as img:
                self.assertEqual(img.size, (500, 333))
        finally:
            if os.path.exists(temp_out):
                os.remove(temp_out)

    def test_16_cli_execution_success(self):
        """CLI must execute successfully, print valid JSON, and return exit code 0."""
        cli_path = PROJECT_ROOT / "ai" / "detector" / "cli.py"
        cmd = [
            sys.executable,
            str(cli_path),
            "--image", str(self.asian_path),
            "--conf", "0.40"
        ]
        proc = subprocess.run(cmd, capture_output=True, text=True)
        self.assertEqual(proc.returncode, 0, f"CLI stderr: {proc.stderr}")

        output_data = json.loads(proc.stdout)
        self.assertEqual(output_data["status"], "success")
        self.assertEqual(output_data["classification"], "Potential Elephant Detected")
        self.assertTrue(output_data["elephant_detected"])
        self.assertEqual(output_data["model_type"], "yolox")
        self.assertIn("Apache 2.0", output_data["model_license"])

    def test_17_cli_execution_error_exit_code(self):
        """CLI must return exit code 2 and structured error JSON on missing file."""
        cli_path = PROJECT_ROOT / "ai" / "detector" / "cli.py"
        cmd = [
            sys.executable,
            str(cli_path),
            "--image", "non_existent_image_file.jpg"
        ]
        proc = subprocess.run(cmd, capture_output=True, text=True)
        self.assertEqual(proc.returncode, 2)

        output_data = json.loads(proc.stdout)
        self.assertEqual(output_data["status"], "error")
        self.assertEqual(output_data["error_type"], "ImageValidationError")

    # =========================================================================
    # Milestone 8B: Additional Tests for YOLOX-Tiny Model Adoption
    # =========================================================================

    def test_18_yolox_tiny_default_model_loading(self):
        """ElephantDetector should default to YOLOX-Tiny with Apache 2.0 license."""
        det = ElephantDetector()
        self.assertEqual(det.model_type, "yolox")
        self.assertEqual(det.input_size, 416)
        self.assertIn("YOLOX-Tiny", det.model_version)
        self.assertIn("Apache 2.0", det.model_license)

    def test_19_yolov8_baseline_backward_compatibility(self):
        """ElephantDetector should support explicit YOLOv8n baseline model loading."""
        if self.yolov8_model_path.exists():
            det_v8 = ElephantDetector(model_path=self.yolov8_model_path, model_type="yolov8")
            self.assertEqual(det_v8.model_type, "yolov8")
            self.assertEqual(det_v8.input_size, 640)
            self.assertIn("YOLOv8", det_v8.model_version)
            self.assertIn("AGPL-3.0", det_v8.model_license)

            res = det_v8.detect(self.asian_path)
            self.assertEqual(res["classification"], "Potential Elephant Detected")
            self.assertTrue(res["elephant_detected"])

    def test_20_invalid_onnx_model_handling(self):
        """Passing an invalid non-ONNX file as model should raise RuntimeError."""
        with tempfile.NamedTemporaryFile(suffix=".onnx", delete=False) as f:
            f.write(b"NOT_A_VALID_ONNX_MODEL_HEADER")
            temp_model = f.name
        try:
            with self.assertRaises(RuntimeError):
                ElephantDetector(model_path=temp_model)
        finally:
            if os.path.exists(temp_model):
                os.remove(temp_model)

    def test_21_bounding_box_coordinate_correctness(self):
        """Bounding box coordinates must be non-negative, properly ordered, and within image bounds."""
        res = self.detector.detect(self.asian_path)
        img_w, img_h = res["image_dimensions"]
        self.assertGreater(len(res["detections"]), 0)

        for det in res["detections"]:
            x1, y1, x2, y2 = det["box"]
            self.assertGreaterEqual(x1, 0)
            self.assertGreaterEqual(y1, 0)
            self.assertLessEqual(x2, img_w)
            self.assertLessEqual(y2, img_h)
            self.assertLess(x1, x2, "x1 must be strictly less than x2")
            self.assertLess(y1, y2, "y1 must be strictly less than y2")

    def test_22_confidence_threshold_customization(self):
        """Custom confidence thresholds should be respected in classification and output filtering."""
        det_strict = ElephantDetector(conf_threshold=0.98)
        res = det_strict.detect(self.asian_path)
        # Even if elephant is in image, with threshold 0.98 it should not qualify as confident
        self.assertFalse(res["elephant_detected"])

    def test_23_negative_railway_track_rejection(self):
        """Authentic Sri Lankan railway track image must yield zero elephant detections."""
        res = self.detector.detect(self.track_path)
        self.assertEqual(res["classification"], "No Elephant Detected")
        self.assertFalse(res["elephant_detected"])
        self.assertEqual(res["estimated_count"], 0)
        self.assertLess(res["max_confidence"], 0.10)

    def test_24_sri_lankan_wild_crossing_detection(self):
        """Authentic Habarana corridor wild elephant image must be confidently detected."""
        res = self.detector.detect(self.habarana_path)
        self.assertEqual(res["classification"], "Potential Elephant Detected")
        self.assertTrue(res["elephant_detected"])
        self.assertGreaterEqual(res["max_confidence"], 0.85)


if __name__ == "__main__":
    unittest.main(verbosity=2)
