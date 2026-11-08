"""
EleGuard Rail - AI-Assisted Elephant Detection Engine
Milestone 8B: Permissively Licensed YOLOX-Tiny Architecture with YOLOv8 Baseline Support

Performs offline inference on submitted wildlife report images using
a lightweight ONNX Runtime session. Default model: YOLOX-Tiny (Apache 2.0).

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


class InvalidModelError(Exception):
    """Raised when an ONNX model has an unexpected tensor input/output shape."""
    pass


class ElephantDetector:
    """
    Offline Elephant Detector supporting both YOLOX (Apache 2.0) and YOLOv8 (AGPL-3.0 baseline).
    Defaults to YOLOX-Tiny for permissive licensing freedom.

    Attributes:
        model_path (Path): Path to the ONNX model file.
        model_type (str): 'yolox' (default) or 'yolov8'.
        conf_threshold (float): Threshold for confident elephant detection (default: 0.40).
        uncertain_threshold (float): Threshold for uncertain flag (default: 0.20).
        nms_iou_threshold (float): Intersection-over-Union threshold for NMS (default: 0.45).
        max_file_size_bytes (int): Maximum allowable image size in bytes (default: 10MB).
    """

    COCO_ELEPHANT_CLASS_ID = 20
    SUPPORTED_EXTENSIONS = {".jpg", ".jpeg", ".png", ".webp"}

    def __init__(
        self,
        model_path: Optional[Union[str, Path]] = None,
        model_type: Optional[str] = None,
        conf_threshold: float = 0.40,
        uncertain_threshold: float = 0.20,
        nms_iou_threshold: float = 0.45,
        max_file_size_bytes: int = 10 * 1024 * 1024,
    ):
        self.conf_threshold = float(conf_threshold)
        self.uncertain_threshold = float(uncertain_threshold)
        self.nms_iou_threshold = float(nms_iou_threshold)
        self.max_file_size_bytes = int(max_file_size_bytes)

        base_dir = Path(__file__).resolve().parent.parent

        # Resolve model path (Default: YOLOX-Tiny)
        if model_path is None:
            primary_path = base_dir / "models" / "yolox_tiny.onnx"
            candidate_path = base_dir / "models" / "candidates" / "yolox_tiny.onnx"
            fallback_v8 = base_dir / "models" / "yolov8n.onnx"

            if primary_path.exists():
                self.model_path = primary_path
            elif candidate_path.exists():
                self.model_path = candidate_path
            elif fallback_v8.exists():
                self.model_path = fallback_v8
            else:
                self.model_path = primary_path
        else:
            self.model_path = Path(model_path).resolve()

        if not self.model_path.exists():
            raise ModelNotFoundError(
                f"Model file not found at '{self.model_path}'. "
                "Ensure yolox_tiny.onnx is placed in ai/models/."
            )

        # Initialize ONNX Runtime Session (CPU Execution Provider)
        try:
            import onnxruntime as ort
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
            self.input_shape = self.session.get_inputs()[0].shape
            self.output_shape = self.session.get_outputs()[0].shape
        except Exception as e:
            if "ModelNotFoundError" in type(e).__name__:
                raise
            raise RuntimeError(f"Failed to initialize ONNX Runtime session: {e}") from e

        # Determine model architecture type and metadata
        self._configure_model_type(model_type)

    def _configure_model_type(self, requested_type: Optional[str] = None):
        """Auto-detect or configure model type (YOLOX vs YOLOv8)."""
        if requested_type:
            self.model_type = requested_type.lower()
        else:
            # Auto-detect from input/output shapes or filename
            fname = self.model_path.name.lower()
            if "yolox" in fname or (len(self.input_shape) == 4 and self.input_shape[2] == 416):
                self.model_type = "yolox"
            elif "yolov8" in fname or (len(self.input_shape) == 4 and self.input_shape[2] == 640):
                self.model_type = "yolov8"
            else:
                self.model_type = "yolox"  # Default assumption

        if self.model_type == "yolox":
            self.input_size = 416
            self.model_version = "YOLOX-Tiny-ONNX-v1.0-COCO"
            self.model_license = "Apache 2.0 (Permissive)"
        elif self.model_type == "yolov8":
            self.input_size = 640
            self.model_version = "YOLOv8n-ONNX-v1.0-COCO"
            self.model_license = "AGPL-3.0 (Copyleft Baseline)"
        else:
            raise InvalidModelError(f"Unsupported model type: '{self.model_type}'. Allowed: 'yolox', 'yolov8'.")

    def validate_image(self, image_input: Union[str, Path, Image.Image]) -> Tuple[Image.Image, Optional[Path]]:
        """
        Validate input image for existence, file size, format, and readable pixels.
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
        Letterbox resize image to model input size (416 for YOLOX, 640 for YOLOv8)
        with neutral grey padding (114, 114, 114).

        Returns:
            Tuple of (tensor, scale, pad_x, pad_y)
        """
        orig_w, orig_h = img.size
        scale = min(self.input_size / orig_w, self.input_size / orig_h)
        new_w = int(orig_w * scale)
        new_h = int(orig_h * scale)

        resized = img.resize((new_w, new_h), Image.Resampling.BILINEAR)

        pad_x = (self.input_size - new_w) // 2
        pad_y = (self.input_size - new_h) // 2

        canvas = Image.new("RGB", (self.input_size, self.input_size), (114, 114, 114))
        canvas.paste(resized, (pad_x, pad_y))

        if self.model_type == "yolox":
            # YOLOX expects BGR unnormalized float32 [0.0, 255.0]
            arr = np.array(canvas, dtype=np.float32)[:, :, ::-1]
        else:
            # YOLOv8 expects RGB normalized float32 [0.0, 1.0]
            arr = np.array(canvas, dtype=np.float32) / 255.0

        tensor = np.transpose(arr, (2, 0, 1))
        tensor = np.expand_dims(tensor, axis=0)

        return tensor, scale, pad_x, pad_y

    def _decode_yolox_boxes(self, raw_preds: np.ndarray) -> np.ndarray:
        """
        Decode YOLOX grid offsets and exponential strides into pixel bounding boxes.
        Formula:
          cx, cy = (output[:2] + grid) * stride
          w, h = exp(output[2:4]) * stride
        """
        outputs = raw_preds.copy()
        if outputs.ndim == 3:
            outputs = outputs[0]

        grids = []
        expanded_strides = []
        strides = [8, 16, 32]
        hsizes = [self.input_size // s for s in strides]
        wsizes = [self.input_size // s for s in strides]

        for hsize, wsize, stride in zip(hsizes, wsizes, strides):
            xv, yv = np.meshgrid(np.arange(wsize), np.arange(hsize))
            grid = np.stack((xv, yv), 2).reshape(1, -1, 2)
            grids.append(grid)
            expanded_strides.append(np.full((*grid.shape[:2], 1), stride))

        grids = np.concatenate(grids, 1)
        expanded_strides = np.concatenate(expanded_strides, 1)

        outputs[..., :2] = (outputs[..., :2] + grids[0]) * expanded_strides[0]
        outputs[..., 2:4] = np.exp(outputs[..., 2:4]) * expanded_strides[0]

        return outputs

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

            # Containment suppression for nested body parts
            min_area = np.minimum(areas[i], areas[order[1:]]) + 1e-6
            ios = inter / min_area

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
        Parse raw model predictions, extract elephant detections,
        convert coordinates back to original image space, and apply NMS.
        """
        preds = raw_preds[0] if raw_preds.ndim == 3 else raw_preds

        if self.model_type == "yolox":
            # YOLOX output: (3549, 85)
            # Elements: [0:4] = box offsets, [4] = obj_conf, [5:85] = 80 classes
            decoded = self._decode_yolox_boxes(preds)
            obj_conf = decoded[:, 4]
            cls_conf = decoded[:, 5 + self.COCO_ELEPHANT_CLASS_ID]
            elephant_scores = obj_conf * cls_conf
            max_raw_conf = float(np.max(elephant_scores)) if elephant_scores.size > 0 else 0.0

            cands = np.where(elephant_scores >= self.uncertain_threshold)[0]
            if len(cands) == 0:
                return [], max_raw_conf

            cand_boxes, cand_scores = [], []
            for idx in cands:
                score = float(elephant_scores[idx])
                cx, cy, w, h = decoded[idx, :4]
                x1 = max(0, min(orig_w, int((cx - w / 2.0 - pad_x) / scale)))
                y1 = max(0, min(orig_h, int((cy - h / 2.0 - pad_y) / scale)))
                x2 = max(0, min(orig_w, int((cx + w / 2.0 - pad_x) / scale)))
                y2 = max(0, min(orig_h, int((cy + h / 2.0 - pad_y) / scale)))

                if x2 > x1 and y2 > y1:
                    cand_boxes.append([x1, y1, x2, y2])
                    cand_scores.append(score)

        else:
            # YOLOv8 output: (84, 8400)
            # Elements: [0:4] = cx, cy, w, h, [4 + 20] = elephant class score
            elephant_scores = preds[4 + self.COCO_ELEPHANT_CLASS_ID, :]
            max_raw_conf = float(np.max(elephant_scores)) if elephant_scores.size > 0 else 0.0

            cands = np.where(elephant_scores >= self.uncertain_threshold)[0]
            if len(cands) == 0:
                return [], max_raw_conf

            cand_boxes, cand_scores = [], []
            for idx in cands:
                score = float(elephant_scores[idx])
                cx, cy, w, h = preds[:4, idx]
                x1 = max(0, min(orig_w, int((cx - w / 2.0 - pad_x) / scale)))
                y1 = max(0, min(orig_h, int((cy - h / 2.0 - pad_y) / scale)))
                x2 = max(0, min(orig_w, int((cx + w / 2.0 - pad_x) / scale)))
                y2 = max(0, min(orig_h, int((cy + h / 2.0 - pad_y) / scale)))

                if x2 > x1 and y2 > y1:
                    cand_boxes.append([x1, y1, x2, y2])
                    cand_scores.append(score)

        if not cand_boxes:
            return [], max_raw_conf

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
        Classify detection into three standard outcomes:
        1. 'Potential Elephant Detected' (>= 1 detection with confidence >= conf_threshold)
        2. 'Uncertain Result' (max confidence between uncertain_threshold and conf_threshold)
        3. 'No Elephant Detected' (max confidence < uncertain_threshold)
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
            "model_version": self.model_version,
            "model_type": self.model_type,
            "model_license": self.model_license,
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
        color: str = "#10B981"
    ) -> Union[Image.Image, Path]:
        """
        Draw visual bounding boxes and confidence banners onto an image.
        """
        img, _ = self.validate_image(image_input)
        draw = ImageDraw.Draw(img)

        try:
            font = ImageFont.load_default()
        except Exception:
            font = None

        for det in detections:
            box = det["box"]
            conf = det["confidence"]
            x1, y1, x2, y2 = box

            draw.rectangle([x1, y1, x2, y2], outline=color, width=3)
            label = f"Elephant: {conf * 100:.1f}%"
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
