<?php
/**
 * EleGuard Rail - AI Detection Service
 *
 * Provides locally executed, zero-budget, advisory AI elephant detection
 * using the YOLOX-Tiny ONNX model via Python subprocess execution.
 *
 * STRICT GOVERNANCE & BUSINESS RULES:
 * - AI detection results are STRICTLY ADVISORY.
 * - Under NO circumstances must AI automatically verify, reject, or resolve reports.
 * - Under NO circumstances must AI modify risk scores or trigger train cab alerts.
 * - The authorized Wildlife Officer retains sole verification authority.
 * - AI failures, timeouts, or corrupted images MUST NEVER block report submission.
 */

require_once __DIR__ . '/../core/Database.php';

class AiDetectionService {

    /**
     * Timeout in seconds for Python detector execution.
     */
    const PROCESS_TIMEOUT_SECONDS = 5.0;

    /**
     * Default confidence threshold for positive elephant detection.
     */
    const DEFAULT_CONF_THRESHOLD = 0.40;

    /**
     * Threshold below which detection is classified as negative (0.20 - 0.40 is uncertain).
     */
    const DEFAULT_UNCERTAIN_THRESHOLD = 0.20;

    /**
     * Resolves the absolute path to the Python executable.
     */
    public static function getPythonBinary(): string {
        // 1. Check if configured in environment
        $envPy = getenv('AI_PYTHON_BIN');
        if ($envPy && file_exists($envPy)) {
            return $envPy;
        }

        // 2. Check if defined in PHP constant
        if (defined('AI_PYTHON_BIN') && file_exists(AI_PYTHON_BIN)) {
            return AI_PYTHON_BIN;
        }

        // 3. Known Windows install path check
        $knownPaths = [
            'C:\\Users\\user\\AppData\\Local\\Programs\\Python\\Python313\\python.exe',
            'C:\\Users\\user\\AppData\\Local\\Programs\\Python\\Python312\\python.exe',
            'C:\\Users\\user\\AppData\\Local\\Programs\\Python\\Python311\\python.exe',
            'C:\\Python313\\python.exe',
            'C:\\Python312\\python.exe',
        ];
        foreach ($knownPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // 4. Default to system PATH 'python'
        return 'python';
    }

    /**
     * Resolves the absolute path to the CLI detector script.
     */
    public static function getCliScriptPath(): string {
        $path = realpath(__DIR__ . '/../../ai/detector/cli.py');
        if ($path && file_exists($path)) {
            return $path;
        }
        return __DIR__ . '/../../ai/detector/cli.py';
    }

    /**
     * Checks if the AI detection subsystem is available (Python + CLI script + Model).
     */
    public static function isAvailable(): bool {
        $cliScript = self::getCliScriptPath();
        if (!file_exists($cliScript)) {
            return false;
        }

        $modelPath = __DIR__ . '/../../ai/models/yolox_tiny.onnx';
        if (!file_exists($modelPath)) {
            return false;
        }

        return true;
    }

    /**
     * Analyzes an image file by invoking the Python YOLOX-Tiny CLI.
     *
     * @param string $imageFullPath Absolute path to the image on disk.
     * @param string|null $annotatedOutputPath Optional path to save annotated visualization.
     * @param float $confThreshold Confidence threshold (default 0.40).
     * @param float $uncertainThreshold Uncertainty threshold (default 0.20).
     * @return array Standardized analysis payload.
     */
    public static function analyzeImage(
        string $imageFullPath,
        ?string $annotatedOutputPath = null,
        float $confThreshold = self::DEFAULT_CONF_THRESHOLD,
        float $uncertainThreshold = self::DEFAULT_UNCERTAIN_THRESHOLD
    ): array {
        if (!file_exists($imageFullPath)) {
            return [
                'success' => false,
                'status' => 'failed',
                'classification' => 'Analysis Error',
                'detected' => null,
                'confidence' => null,
                'estimated_count' => null,
                'model_version' => null,
                'notes' => 'Image file does not exist on disk.',
                'detections' => [],
                'annotated_path' => null,
                'error' => 'Image file not found.'
            ];
        }

        $pythonBin = self::getPythonBinary();
        $cliScript = self::getCliScriptPath();

        // Build CLI command with strict escaping
        $cmd = escapeshellarg($pythonBin) . ' ' .
               escapeshellarg($cliScript) . ' ' .
               '--image ' . escapeshellarg($imageFullPath) . ' ' .
               '--conf ' . sprintf('%.2f', $confThreshold) . ' ' .
               '--uncertain ' . sprintf('%.2f', $uncertainThreshold);

        if ($annotatedOutputPath !== null) {
            $cmd .= ' --visualize ' . escapeshellarg($annotatedOutputPath);
        }

        // Subprocess descriptor specification
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = @proc_open($cmd, $descriptors, $pipes, dirname($cliScript));

        if (!is_resource($process)) {
            return [
                'success' => false,
                'status' => 'failed',
                'classification' => 'Subprocess Error',
                'detected' => null,
                'confidence' => null,
                'estimated_count' => null,
                'model_version' => null,
                'notes' => 'Failed to initialize Python AI detection process.',
                'detections' => [],
                'annotated_path' => null,
                'error' => 'Unable to spawn AI detector process.'
            ];
        }

        // Close stdin as no input is needed
        fclose($pipes[0]);

        // Non-blocking read with timeout
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = microtime(true);
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            if (!$status['running']) {
                break;
            }

            if ((microtime(true) - $startTime) > self::PROCESS_TIMEOUT_SECONDS) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }

            usleep(10000); // 10ms poll
        }

        // Read any remaining data in pipes
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($timedOut) {
            return [
                'success' => false,
                'status' => 'failed',
                'classification' => 'Timeout Error',
                'detected' => null,
                'confidence' => null,
                'estimated_count' => null,
                'model_version' => 'YOLOX-Tiny-ONNX',
                'notes' => 'AI detection timed out after ' . self::PROCESS_TIMEOUT_SECONDS . 's. Advisory suggestion unavailable.',
                'detections' => [],
                'annotated_path' => null,
                'error' => 'AI process timed out.'
            ];
        }

        // Parse JSON output from stdout
        $stdoutTrimmed = trim($stdout);
        $parsed = json_decode($stdoutTrimmed, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed)) {
            return [
                'success' => false,
                'status' => 'failed',
                'classification' => 'Parser Error',
                'detected' => null,
                'confidence' => null,
                'estimated_count' => null,
                'model_version' => 'YOLOX-Tiny-ONNX',
                'notes' => 'Malformed response from AI detector: ' . substr(strip_tags($stderr ?: $stdoutTrimmed), 0, 200),
                'detections' => [],
                'annotated_path' => null,
                'error' => 'Failed to parse AI output JSON.'
            ];
        }

        if (isset($parsed['status']) && $parsed['status'] === 'error') {
            return [
                'success' => false,
                'status' => 'failed',
                'classification' => 'Detector Error',
                'detected' => null,
                'confidence' => null,
                'estimated_count' => null,
                'model_version' => 'YOLOX-Tiny-ONNX',
                'notes' => 'AI Detector reported error: ' . ($parsed['message'] ?? 'Unknown error'),
                'detections' => [],
                'annotated_path' => null,
                'error' => $parsed['message'] ?? 'AI detector execution error'
            ];
        }

        // Successful detection output
        $detected = !empty($parsed['elephant_detected']);
        $classification = $parsed['classification'] ?? ($detected ? 'Potential Elephant Detected' : 'No Elephant Detected');
        $confidence = isset($parsed['max_confidence']) ? (float)$parsed['max_confidence'] : 0.0;
        $count = isset($parsed['estimated_count']) ? (int)$parsed['estimated_count'] : 0;
        $modelVersion = $parsed['model_version'] ?? 'YOLOX-Tiny-ONNX-v1.0-COCO';
        $notes = $parsed['notes'] ?? '';
        $detections = $parsed['detections'] ?? [];
        $annotatedPath = $parsed['annotated_image_path'] ?? null;

        return [
            'success' => true,
            'status' => 'processed',
            'classification' => $classification,
            'detected' => $detected,
            'confidence' => $confidence,
            'estimated_count' => $count,
            'model_version' => $modelVersion,
            'notes' => "[{$classification}] " . $notes,
            'detections' => $detections,
            'annotated_path' => $annotatedPath,
            'inference_time_ms' => $parsed['inference_time_ms'] ?? null,
            'error' => null
        ];
    }

    /**
     * Executes AI analysis for a specific report ID and saves metadata to the database.
     *
     * @param int $reportId The database primary key in elephant_reports.
     * @param string|null $imageRelativePath Relative path from public/, e.g. 'assets/uploads/reports/xxx.jpg'.
     * @return array Result summary with status.
     */
    public static function processReportAi(int $reportId, ?string $imageRelativePath): array {
        $db = Database::getInstance()->getConnection();

        // If no image is attached, mark as skipped
        if (empty($imageRelativePath)) {
            $stmt = $db->prepare("
                UPDATE elephant_reports
                SET ai_status = 'skipped',
                    ai_detected_elephant = NULL,
                    ai_confidence = NULL,
                    ai_estimated_count = NULL,
                    ai_model_version = NULL,
                    ai_detection_notes = 'No photograph attached to report.',
                    ai_processed_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':id' => $reportId]);

            return [
                'status' => 'skipped',
                'message' => 'No image attached; AI detection skipped.'
            ];
        }

        // Resolve absolute filesystem path to image
        $cleanRel = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $imageRelativePath), DIRECTORY_SEPARATOR);
        $fullPath = realpath(__DIR__ . '/../../public/' . $cleanRel);

        if (!$fullPath || !file_exists($fullPath)) {
            $stmt = $db->prepare("
                UPDATE elephant_reports
                SET ai_status = 'failed',
                    ai_detected_elephant = NULL,
                    ai_confidence = NULL,
                    ai_estimated_count = NULL,
                    ai_model_version = 'YOLOX-Tiny-ONNX',
                    ai_detection_notes = 'Uploaded photograph was not found on server storage.',
                    ai_processed_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':id' => $reportId]);

            return [
                'status' => 'failed',
                'message' => 'Image file not found on disk.'
            ];
        }

        // Determine destination for annotated visualization
        $dir = dirname($fullPath);
        $filename = basename($fullPath);
        $annotatedFullPath = $dir . DIRECTORY_SEPARATOR . 'annotated_' . $filename;

        // Run analysis via local subprocess
        $analysis = self::analyzeImage($fullPath, $annotatedFullPath);

        if ($analysis['success']) {
            $stmt = $db->prepare("
                UPDATE elephant_reports
                SET ai_status = 'processed',
                    ai_detected_elephant = :detected,
                    ai_confidence = :confidence,
                    ai_estimated_count = :count,
                    ai_model_version = :version,
                    ai_detection_notes = :notes,
                    ai_processed_at = NOW()
                WHERE id = :id
            ");

            $stmt->execute([
                ':detected' => $analysis['detected'] ? 1 : 0,
                ':confidence' => $analysis['confidence'],
                ':count' => $analysis['estimated_count'],
                ':version' => $analysis['model_version'],
                ':notes' => $analysis['notes'],
                ':id' => $reportId
            ]);

            return [
                'status' => 'processed',
                'classification' => $analysis['classification'],
                'detected' => $analysis['detected'],
                'confidence' => $analysis['confidence'],
                'count' => $analysis['estimated_count'],
                'model_version' => $analysis['model_version'],
                'notes' => $analysis['notes']
            ];
        } else {
            $stmt = $db->prepare("
                UPDATE elephant_reports
                SET ai_status = 'failed',
                    ai_detected_elephant = NULL,
                    ai_confidence = NULL,
                    ai_estimated_count = NULL,
                    ai_model_version = 'YOLOX-Tiny-ONNX',
                    ai_detection_notes = :notes,
                    ai_processed_at = NOW()
                WHERE id = :id
            ");

            $stmt->execute([
                ':notes' => $analysis['notes'] ?: 'AI analysis process failed.',
                ':id' => $reportId
            ]);

            return [
                'status' => 'failed',
                'error' => $analysis['error'] ?? 'Detection failure'
            ];
        }
    }

    /**
     * Helper to retrieve annotated image web path if it exists on disk.
     */
    public static function getAnnotatedWebPath(?string $imageRelativePath): ?string {
        if (empty($imageRelativePath)) {
            return null;
        }

        $cleanRel = ltrim(str_replace(['\\', '/'], '/', $imageRelativePath), '/');
        $dir = dirname($cleanRel);
        $filename = basename($cleanRel);
        $annotatedRel = ($dir === '.' ? '' : $dir . '/') . 'annotated_' . $filename;

        $fullPath = realpath(__DIR__ . '/../../public/' . $annotatedRel);
        if ($fullPath && file_exists($fullPath)) {
            return $annotatedRel;
        }

        return null;
    }
}
