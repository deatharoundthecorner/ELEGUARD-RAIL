"""
EleGuard Rail - AI-Assisted Elephant Detection Engine
Milestone 8A: Zero-Cost Local Computer Vision Prototype

Performs offline inference on submitted wildlife report images using
a lightweight ONNX Runtime session and YOLOv8n (nano) model.

IMPORTANT: AI outputs are strictly suggestions for human review.
Authorized Wildlife Officers retain sole verification authority.
"""

import os
import sys
import time
from pathlib import Path
from typing import Dict, List, Optional, Tuple, Union, Any

import numpy as np
from PIL import Image, ImageDraw, ImageFont


class ImageValidationError(Exception):
    """Raised when an input image fails integrity, format, or size validation."""
    pass


class ModelNotFoundError(Exception):
    """Raised when the specified ONNX model cannot be found or loaded."""
    pass


class ElephantDetector:
    """
    Offline Elephant Detector utilizing YOLOv8n in ONNX format via onnxruntime.

    Attributes:
        model_path (Path): Path to the ONNX model file.
        conf_threshold (float): Threshold for confident elephant detection (default: 0.40).
        uncertain_threshold (float): Threshold for uncertain flag (default: 0.20).
        nms_iou_threshold (float): Intersection-over-Union threshold for NMS (default: 0.45).
        max_file_size_bytes (int): Maximum allowable image size in bytes (default: 10MB).
    """

    COCO_ELEPHANT_CLASS_ID = 20
    MODEL_VERSION = "YOLOv8n-ONNX-v1.0-COCO"
    INPUT_SIZE = 640
    SUPPORTED_EXTENSIONS = {".jpg", ".jpeg", ".png", ".webp"}

    def __init__(
        self,
        model_path: Optional[Union[str, Path]] = None,
        conf_threshold: float = 0.40,
        uncertain_threshold: float = 0.20,
        nms_iou_threshold: float = 0.45,
        max_file_size_bytes: int = 10 * 1024 * 1024,
    ):
        self.conf_threshold = float(conf_threshold)
        self.uncertain_threshold = float(uncertain_threshold)
        self.nms_iou_threshold = float(nms_iou_threshold)
        self.max_file_size_bytes = int(max_file_size_bytes)

        # Resolve model path
        if model_path is None:
            # Default to ai/models/yolov8n.onnx relative to this file
            base_dir = Path(__file__).resolve().parent.parent
            self.model_path = base_dir / "models" / "yolov8n.onnx"
        else:
            self.model_path = Path(model_path).resolve()

        if not self.model_path.exists():
            raise ModelNotFoundError(
                f"Model file not found at '{self.model_path}'. "
                "Ensure yolov8n.onnx is placed in ai/models/."
            )

        # Initialize ONNX Runtime Session (CPU Execution Provider)
        try:
            import onnxruntime as ort
            # Configure session options for quiet, predictable CPU execution
            opts = ort.SessionOptions()
            opts.graph_optimization_level = ort.GraphOptimizationLevel.ORT_ENABLE_ALL
            opts.log_severity_level = 3  # Error only
            self.session = ort.InferenceSession(
                str(self.model_path),
                sess_options=opts,
                providers=["CPUExecutionProvider"]
            )
            self.input_name = self.session.get_inputs()[0].name
            self.output_name = self.session.get_outputs()[0].name
        except Exception as e:
            raise RuntimeError(f"Failed to initialize ONNX Runtime session: {e}") from e

    def validate_image(self, image_input: Union[str, Path, Image.Image]) -> Tuple[Image.Image, Optional[Path]]:
        """
        Validate input image for existence, file size, format, and readable pixels.

        Args:
            image_input: File path (str/Path) or existing PIL Image object.

        Returns:
            Tuple of (PIL.Image in RGB mode, optional resolved Path).

        Raises:
            ImageValidationError: If validation fails.
        """
        resolved_path: Optional[Path] = None

        if isinstance(image_input, (str, Path)):
            resolved_path = Path(image_input).resolve()
            if not resolved_path.exists():
                raise ImageValidationError(f"Image file does not exist: '{resolved_path}'")
            if not resolved_path.is_file():
                raise ImageValidationError(f"Image path is not a regular file: '{resolved_path}'")

            # Validate extension
            if resolved_path.suffix.lower() not in self.SUPPORTED_EXTENSIONS:
                raise ImageValidationError(
                    f"Unsupported image extension '{resolved_path.suffix}'. "
                    f"Supported formats: {', '.join(sorted(self.SUPPORTED_EXTENSIONS))}"
                )

            # Validate file size
            file_size = resolved_path.stat().st_size
            if file_size == 0:
                raise ImageValidationError("Image file is empty (0 bytes).")
            if file_size > self.max_file_size_bytes:
                raise ImageValidationError(
                    f"Image file size ({file_size} bytes) exceeds limit of {self.max_file_size_bytes} bytes (10MB)."
                )

            try:
                img = Image.open(resolved_path)
                img.load()  # Force load pixel data to detect corruption
            except Exception as e:
                raise ImageValidationError(f"Corrupted or unreadable image file: {e}") from e

        elif isinstance(image_input, Image.Image):
            img = image_input
        else:
            raise ImageValidationError(f"Invalid image input type: {type(image_input).__name__}")

        # Ensure RGB format
        if img.mode != "RGB":
            try:
                img = img.convert("RGB")
            except Exception as e:
                raise ImageValidationError(f"Failed to convert image mode '{img.mode}' to RGB: {e}") from e

        if img.width < 10 or img.height < 10:
            raise ImageValidationError(f"Image dimensions ({img.width}x{img.height}) are too small for detection.")

        return img, resolved_path

    def preprocess(self, img: Image.Image) -> Tuple[np.ndarray, float, int, int]:
        """
        Letterbox resize image to 640x640 with neutral grey padding (114, 114, 114)
        and normalize to float32 tensor of shape (1, 3, 640, 640).

        Returns:
            Tuple of (tensor, scale, pad_x, pad_y)
        """
        orig_w, orig_h = img.size
        scale = min(self.INPUT_SIZE / orig_w, self.INPUT_SIZE / orig_h)
        new_w = int(orig_w * scale)
        new_h = int(orig_h * scale)

        resized = img.resize((new_w, new_h), Image.Resampling.BILINEAR)

        pad_x = (self.INPUT_SIZE - new_w) // 2
        pad_y = (self.INPUT_SIZE - new_h) // 2

        canvas = Image.new("RGB", (self.INPUT_SIZE, self.INPUT_SIZE), (114, 114, 114))
        canvas.paste(resized, (pad_x, pad_y))

        # Convert to numpy, normalize [0, 1], transpose to CHW
        arr = np.array(canvas, dtype=np.float32) / 255.0
        tensor = np.transpose(arr, (2, 0, 1))  # (3, 640, 640)
        tensor = np.expand_dims(tensor, axis=0)  # (1, 3, 640, 640)

        return tensor, scale, pad_x, pad_y

    def _nms(self, boxes: np.ndarray, scores: np.ndarray) -> List[int]:
        """
        Greedy Non-Maximum Suppression with containment suppression.
        """
        if len(boxes) == 0:
            return []

        x1 = boxes[:, 0]
        y1 = boxes[:, 1]
        x2 = boxes[:, 2]
        y2 = boxes[:, 3]
        areas = (x2 - x1) * (y2 - y1)

        order = scores.argsort()[::-1]
        keep = []

        while order.size > 0:
            i = order[0]
            keep.append(i)

            xx1 = np.maximum(x1[i], x1[order[1:]])
            yy1 = np.maximum(y1[i], y1[order[1:]])
            xx2 = np.minimum(x2[i], x2[order[1:]])
            yy2 = np.minimum(y2[i], y2[order[1:]])

            w = np.maximum(0.0, xx2 - xx1)
            h = np.maximum(0.0, yy2 - yy1)
            inter = w * h

            # Standard IoU
            iou = inter / (areas[i] + areas[order[1:]] - inter + 1e-6)

            # Intersection over Smaller area (containment suppression for nested body parts)
            min_area = np.minimum(areas[i], areas[order[1:]]) + 1e-6
            ios = inter / min_area

            # Suppress if standard IoU > threshold OR smaller box is heavily contained (> 0.70)
            suppress = (iou > self.nms_iou_threshold) | (ios > 0.70)
            inds = np.where(~suppress)[0]
            order = order[inds + 1]

        return keep

    def postprocess(
        self,
        raw_preds: np.ndarray,
        orig_w: int,
        orig_h: int,
        scale: float,
        pad_x: int,
        pad_y: int
    ) -> Tuple[List[Dict[str, Any]], float]:
        """
        Parse raw YOLOv8 output tensor (1, 84, 8400), extract elephant detections,
        convert coordinates back to original image space, and apply NMS.

        Returns:
            Tuple of (list of detection dicts, max_confidence_found)
        """
        # Squeeze batch dimension: (84, 8400)
        preds = raw_preds[0] if raw_preds.ndim == 3 else raw_preds

        # Class 20 score across all 8400 anchor predictions
        elephant_scores = preds[4 + self.COCO_ELEPHANT_CLASS_ID, :]
        max_raw_conf = float(np.max(elephant_scores)) if elephant_scores.size > 0 else 0.0

        # Filter candidate anchor indices above uncertain threshold
        cands = np.where(elephant_scores >= self.uncertain_threshold)[0]

        if len(cands) == 0:
            return [], max_raw_conf

        cand_boxes = []
        cand_scores = []

        for idx in cands:
            score = float(elephant_scores[idx])
            cx, cy, w, h = preds[:4, idx]

            # Convert from 640x640 padded canvas back to original image pixels
            x1 = max(0, min(orig_w, int((cx - w / 2.0 - pad_x) / scale)))
            y1 = max(0, min(orig_h, int((cy - h / 2.0 - pad_y) / scale)))
            x2 = max(0, min(orig_w, int((cx + w / 2.0 - pad_x) / scale)))
            y2 = max(0, min(orig_h, int((cy + h / 2.0 - pad_y) / scale)))

            if x2 > x1 and y2 > y1:
                cand_boxes.append([x1, y1, x2, y2])
                cand_scores.append(score)

        if not cand_boxes:
            return [], max_raw_conf

        # Perform NMS
        keep_indices = self._nms(np.array(cand_boxes), np.array(cand_scores))

        detections = []
        for k in keep_indices:
            detections.append({
                "box": cand_boxes[k],
                "confidence": round(float(cand_scores[k]), 4),
                "class_id": self.COCO_ELEPHANT_CLASS_ID,
                "class_name": "elephant"
            })

        return detections, max_raw_conf

    def classify_result(
        self,
        detections: List[Dict[str, Any]],
        max_raw_conf: float
    ) -> Tuple[str, bool, int, float]:
        """
        Classify detection into one of three standard categories:
        1. 'Potential Elephant Detected' (>= 1 detection with confidence >= conf_threshold)
        2. 'Uncertain Result' (max confidence between uncertain_threshold and conf_threshold)
        3. 'No Elephant Detected' (max confidence < uncertain_threshold)

        Returns:
            Tuple of (classification_string, elephant_detected_bool, estimated_count, max_conf)
        """
        confident_dets = [d for d in detections if d["confidence"] >= self.conf_threshold]
        all_confs = [d["confidence"] for d in detections]
        highest_conf = max(all_confs) if all_confs else max_raw_conf

        if len(confident_dets) > 0:
            return "Potential Elephant Detected", True, len(confident_dets), highest_conf
        elif highest_conf >= self.uncertain_threshold:
            return "Uncertain Result", False, 0, highest_conf
        else:
            return "No Elephant Detected", False, 0, highest_conf

    def detect(self, image_input: Union[str, Path, Image.Image]) -> Dict[str, Any]:
        """
        Execute end-to-end elephant detection on an image.

        Args:
            image_input: Image path or PIL Image.

        Returns:
            Structured dictionary with detection findings, timings, and advisory disclaimer.
        """
        start_total = time.perf_counter()

        # Step 1: Validate image
        img, resolved_path = self.validate_image(image_input)
        orig_w, orig_h = img.size

        # Step 2: Preprocess
        tensor, scale, pad_x, pad_y = self.preprocess(img)

        # Step 3: Run inference
        start_inf = time.perf_counter()
        raw_outputs = self.session.run([self.output_name], {self.input_name: tensor})
        inference_time_ms = round((time.perf_counter() - start_inf) * 1000.0, 2)

        # Step 4: Postprocess
        detections, max_raw_conf = self.postprocess(
            raw_outputs[0], orig_w, orig_h, scale, pad_x, pad_y
        )

        # Step 5: Classify result
        classification, detected, count, max_conf = self.classify_result(detections, max_raw_conf)

        total_time_ms = round((time.perf_counter() - start_total) * 1000.0, 2)

        # Human-readable advisory note
        if classification == "Potential Elephant Detected":
            notes = (
                f"AI detected {count} potential elephant(s) with highest confidence of {max_conf * 100:.1f}%. "
                "Advisory suggestion for wildlife officer review."
            )
        elif classification == "Uncertain Result":
            notes = (
                f"Low-confidence feature match ({max_conf * 100:.1f}%), possibly degraded, distant, or ambiguous image. "
                "Wildlife officer manual inspection required."
            )
        else:
            notes = (
                f"No elephant detected in image (highest match {max_conf * 100:.1f}% below threshold). "
                "Advisory suggestion only."
            )

        # Filter returned detections list based on classification
        returned_detections = [d for d in detections if d["confidence"] >= (
            self.conf_threshold if classification == "Potential Elephant Detected" else self.uncertain_threshold
        )]

        return {
            "status": "success",
            "image_path": str(resolved_path) if resolved_path else None,
            "image_dimensions": [orig_w, orig_h],
            "classification": classification,
            "elephant_detected": detected,
            "estimated_count": count,
            "max_confidence": round(float(max_conf), 4),
            "detections": returned_detections,
            "model_version": self.MODEL_VERSION,
            "inference_time_ms": inference_time_ms,
            "total_time_ms": total_time_ms,
            "notes": notes,
            "disclaimer": (
                "Advisory only: This detection is an automated AI suggestion. Official report verification "
                "and railway hazard decisions must be made solely by authorized Wildlife Officers."
            )
        }

    def draw_annotations(
        self,
        image_input: Union[str, Path, Image.Image],
        detections: List[Dict[str, Any]],
        output_path: Optional[Union[str, Path]] = None,
        color: str = "#10B981"  # Emerald green
    ) -> Union[Image.Image, Path]:
        """
        Draw visual bounding boxes and confidence banners onto an image.

        Args:
            image_input: Source image or path.
            detections: List of detection dictionaries containing 'box' and 'confidence'.
            output_path: Optional destination file path to save annotated image.
            color: Hex color string for bounding boxes.

        Returns:
            Saved Path if output_path is provided, or modified PIL Image.
        """
        img, _ = self.validate_image(image_input)
        draw = ImageDraw.Draw(img)

        try:
            # Fallback to default bitmap font if truetype is unavailable
            font = ImageFont.load_default()
        except Exception:
            font = None

        for det in detections:
            box = det["box"]
            conf = det["confidence"]
            x1, y1, x2, y2 = box

            # Draw outer rectangle with 3px width
            draw.rectangle([x1, y1, x2, y2], outline=color, width=3)

            # Draw label banner
            label = f"Elephant: {conf * 100:.1f}%"
            # Label background banner
            banner_h = 16
            banner_w = len(label) * 7 + 6
            by1 = max(0, y1 - banner_h)
            by2 = by1 + banner_h
            draw.rectangle([x1, by1, x1 + banner_w, by2], fill=color)
            draw.text((x1 + 3, by1 + 2), label, fill="#FFFFFF", font=font)

        if output_path is not None:
            out_file = Path(output_path).resolve()
            out_file.parent.mkdir(parents=True, exist_ok=True)
            img.save(out_file, quality=90)
            return out_file

        return img
