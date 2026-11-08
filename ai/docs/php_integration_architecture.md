# EleGuard Rail — Safe PHP Backend Integration Architecture
## Milestone 8A: AI-Assisted Elephant Detection Service Integration

### 1. Executive Summary & Safety Principles

The AI-Assisted Elephant Image Detection prototype provides automated computer vision assistance to Wildlife Officers when reviewing wildlife hazard reports.

To protect the integrity of the EleGuard Rail platform and ensure absolute safety, the integration adheres to four non-negotiable principles:

1. **Human Authority Preservation (Human-in-the-Loop):**
   - AI outputs are strictly non-authoritative, advisory suggestions.
   - Under no circumstances does the AI model alter `report_status` (`Pending`, `Verified`, `Dismissed`), alter the calculated `risk_score`, or trigger active railway alerts (`alerts` table).
   - Only authorized Wildlife Officers can verify reports and issue alerts.

2. **Fault Isolation & Graceful Degradation:**
   - Failure of the AI subsystem (e.g. Python missing, model corrupt, process timeout, invalid image bytes) must **never** block, fail, or reject a citizen report submission.
   - In any failure scenario, the report is saved normally with `ai_status = 'failed'` or `'skipped'`, allowing officers to manually verify it without hindrance.

3. **Secure Process Execution:**
   - The AI CLI is never exposed to public HTTP endpoints.
   - Execution uses `proc_open()` with strict 5-second execution timeouts, piped I/O, and mandatory `escapeshellarg()` parameter escaping.

4. **Zero-Cost & Local Execution:**
   - Runs locally via `onnxruntime` on standard CPU with ~10MB memory overhead and ~30ms inference latency.
   - No cloud API keys, recurring charges, or external network dependencies.

---

### 2. Database Schema Alignment

When Migration 02 (`02_add_ai_detection_metadata_to_elephant_reports.sql`) is executed in future milestones, the `elephant_reports` table will be enriched with the following non-destructive columns:

| Column | Type | Default | Purpose |
| :--- | :--- | :--- | :--- |
| `ai_status` | `ENUM('pending', 'processed', 'skipped', 'failed')` | `'pending'` | Lifecycle state of AI inference task |
| `ai_detected_elephant` | `TINYINT(1)` | `NULL` | 1 if confident elephant detected, 0 if not, NULL if pending/failed |
| `ai_confidence` | `DECIMAL(5, 4)` | `NULL` | Peak confidence score (0.0000 to 1.0000) |
| `ai_estimated_count` | `INT` | `NULL` | Number of distinct elephant bounding boxes |
| `ai_model_version` | `VARCHAR(50)` | `NULL` | Model identifier (e.g. `YOLOv8n-ONNX-v1.0-COCO`) |
| `ai_detection_notes` | `TEXT` | `NULL` | Human-readable explanation and advisory notes |
| `ai_processed_at` | `DATETIME` | `NULL` | Timestamp when inference completed |

---

### 3. Service Layer Architecture: `AiDetectionService.php`

A dedicated PHP service (`app/services/AiDetectionService.php`) will encapsulate all interaction with the Python detector CLI:

```php
<?php
/**
 * EleGuard Rail - AI Detection Integration Service
 * Executes local YOLOv8 ONNX inference via secure subprocess.
 */

class AiDetectionService {
    private const TIMEOUT_SECONDS = 5;
    private const PYTHON_EXECUTABLE = 'python'; // Or configurable via .env (e.g. PYTHON_PATH)

    /**
     * Run AI detection on a saved report image.
     *
     * @param string $absoluteImagePath
     * @return array Structured result dictionary
     */
    public static function analyzeImage(string $absoluteImagePath): array {
        if (!file_exists($absoluteImagePath) || filesize($absoluteImagePath) === 0) {
            return [
                'status' => 'skipped',
                'reason' => 'Image file not found or empty',
            ];
        }

        $cliPath = realpath(__DIR__ . '/../../ai/detector/cli.py');
        if (!$cliPath || !file_exists($cliPath)) {
            return [
                'status' => 'failed',
                'reason' => 'AI detector CLI script missing',
            ];
        }

        $cmd = sprintf(
            '%s %s --image %s --conf 0.40 --uncertain 0.20',
            escapeshellcmd(self::PYTHON_EXECUTABLE),
            escapeshellarg($cliPath),
            escapeshellarg($absoluteImagePath)
        );

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return [
                'status' => 'failed',
                'reason' => 'Unable to spawn AI detector subprocess',
            ];
        }

        fclose($pipes[0]);

        // Stream output with timeout
        $startTime = microtime(true);
        $stdout = '';
        $stderr = '';

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $changed = stream_select($read, $write, $except, 0, 100000); // 100ms slice

            if ($changed > 0) {
                foreach ($read as $r) {
                    if ($r === $pipes[1]) {
                        $stdout .= stream_get_contents($pipes[1]);
                    } elseif ($r === $pipes[2]) {
                        $stderr .= stream_get_contents($pipes[2]);
                    }
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }

            if ((microtime(true) - $startTime) > self::TIMEOUT_SECONDS) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return [
                    'status' => 'failed',
                    'reason' => 'Inference timed out after ' . self::TIMEOUT_SECONDS . ' seconds',
                ];
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $data = json_decode($stdout, true);
        if ($exitCode === 0 && is_array($data) && ($data['status'] ?? '') === 'success') {
            return [
                'status' => 'processed',
                'detected_elephant' => (int)($data['elephant_detected'] ?? 0),
                'confidence' => (float)($data['max_confidence'] ?? 0.0),
                'estimated_count' => (int)($data['estimated_count'] ?? 0),
                'model_version' => (string)($data['model_version'] ?? 'YOLOv8n-ONNX'),
                'notes' => (string)($data['notes'] ?? ''),
                'raw' => $data,
            ];
        }

        return [
            'status' => 'failed',
            'reason' => $data['message'] ?? ($stderr ?: 'Unknown error during inference'),
            'exit_code' => $exitCode,
        ];
    }
}
```

---

### 4. Integration into Report Workflow

1. **User Submits Report with Photo:**
   - Citizen or Station Master uploads a sighting photo via `/report.php`.
   - `Upload::saveReportImage()` validates size ($\le 2\text{ MB}$) and MIME type, saving to `public/assets/uploads/reports/`.
   - `ReportService::submitReport()` writes baseline record to `elephant_reports`.

2. **Triggering AI Inference:**
   - Because inference requires only ~30ms, inference can be executed immediately after report persistence, updating `ai_status`, `ai_detected_elephant`, `ai_confidence`, etc.
   - Alternatively, a lightweight CLI queue worker (`php bin/process_ai_queue.php`) can process rows with `ai_status = 'pending'`, ensuring web response times remain instantaneous regardless of host workload.

3. **Officer UI Display:**
   - In Wildlife Officer views (`wildlife/dashboard.php`, `wildlife/view_report.php`), reports with images display an advisory AI insight badge:
     - 🟢 **Potential Elephant (91.3% Match)** — Suggested 1 elephant.
     - 🟡 **Ambiguous / Low Match (37.4%)** — Officer visual inspection required.
     - ⚪ **No Elephant Detected (< 20%)** — Sighting details should be re-checked.
     - ⏳ **AI Pending** / ⚠️ **AI Skipped**.
   - An interactive toggle lets the officer view the original image or overlay bounding boxes with one click.
