"""
EleGuard Rail - AI Elephant Detector Command Line Interface (CLI)

Enables command-line execution and automated integration with external services
(such as the PHP ReportService backend) via JSON output over stdout.
"""

import argparse
import json
import os
import sys
from pathlib import Path

# Add project root to sys.path so ai package is discoverable
PROJECT_ROOT = Path(__file__).resolve().parent.parent.parent
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

from ai.detector.detector import (
    ElephantDetector,
    ImageValidationError,
    ModelNotFoundError,
)


def parse_args():
    parser = argparse.ArgumentParser(
        description="EleGuard Rail - AI Elephant Detection CLI",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    parser.add_argument(
        "--image", "-i",
        required=True,
        help="Path to image file (JPG, PNG, WebP) to analyze",
    )
    parser.add_argument(
        "--output", "-o",
        default=None,
        help="Optional path to save JSON results file",
    )
    parser.add_argument(
        "--visualize", "-v",
        default=None,
        help="Optional path to save annotated image with bounding boxes",
    )
    parser.add_argument(
        "--conf",
        type=float,
        default=0.40,
        help="Confidence threshold for positive elephant detection",
    )
    parser.add_argument(
        "--uncertain",
        type=float,
        default=0.20,
        help="Threshold below which image is considered negative",
    )
    parser.add_argument(
        "--model", "-m",
        default=None,
        help="Optional path to custom ONNX model file",
    )
    parser.add_argument(
        "--pretty",
        action="store_true",
        help="Format stdout JSON with 2-space indentation",
    )
    return parser.parse_args()


def main():
    args = parse_args()

    try:
        detector = ElephantDetector(
            model_path=args.model,
            conf_threshold=args.conf,
            uncertain_threshold=args.uncertain,
        )

        result = detector.detect(args.image)

        # Handle visualization if requested
        if args.visualize and result["detections"]:
            annotated_path = detector.draw_annotations(
                image_input=args.image,
                detections=result["detections"],
                output_path=args.visualize,
            )
            result["annotated_image_path"] = str(annotated_path)

        # Save to output file if requested
        if args.output:
            out_file = Path(args.output).resolve()
            out_file.parent.mkdir(parents=True, exist_ok=True)
            with open(out_file, "w", encoding="utf-8") as f:
                json.dump(result, f, indent=2)

        # Write clean JSON to stdout
        indent = 2 if args.pretty else None
        print(json.dumps(result, indent=indent))
        sys.exit(0)

    except ImageValidationError as e:
        error_payload = {
            "status": "error",
            "error_type": "ImageValidationError",
            "message": str(e),
            "image_path": str(args.image),
        }
        print(json.dumps(error_payload, indent=2))
        sys.exit(2)

    except ModelNotFoundError as e:
        error_payload = {
            "status": "error",
            "error_type": "ModelNotFoundError",
            "message": str(e),
        }
        print(json.dumps(error_payload, indent=2))
        sys.exit(3)

    except Exception as e:
        error_payload = {
            "status": "error",
            "error_type": type(e).__name__,
            "message": str(e),
        }
        print(json.dumps(error_payload, indent=2))
        sys.exit(1)


if __name__ == "__main__":
    main()
