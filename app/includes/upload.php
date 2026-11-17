<?php
/**
 * EleGuard Rail — Image Upload Helper
 * Milestone 9C — Stage J Phase J.1 (TSK-J01 & RSK-J1-02 Remediation)
 * 
 * Enforces strict defense-in-depth security:
 * 1. File size enforcement (<= 2MB).
 * 2. Null-byte, control character, and path traversal rejection in original filename.
 * 3. Double-extension and executable pattern rejection.
 * 4. MIME type validation via finfo magic bytes (image/jpeg, image/png).
 * 5. Structural image integrity validation via getimagesize() and IMAGETYPE assertions.
 * 6. Dimension and pixel cap enforcement preventing decompression bombs (DoS).
 * 7. Image re-encoding via GD to strip appended executable polyglots and comments (RSK-J1-02).
 * 8. Discarding user-provided filenames; generating randomized safe server-side names.
 * 9. Canonical extension mapping based on validated binary image type.
 * 10. Hardcoded destination path with canonical realpath boundary assertion.
 */

declare(strict_types=1);

class Upload {
    
    /**
     * Maximum allowed file size: 2MB (2,097,152 bytes)
     */
    public const MAX_FILE_SIZE = 2 * 1024 * 1024;

    /**
     * Maximum allowable image width in pixels (decompression bomb defense)
     */
    public const MAX_IMAGE_WIDTH = 4096;

    /**
     * Maximum allowable image height in pixels (decompression bomb defense)
     */
    public const MAX_IMAGE_HEIGHT = 4096;

    /**
     * Maximum allowable pixel count: 16 MegaPixels
     */
    public const MAX_PIXELS = 16_000_000;

    /**
     * Strictly whitelisted MIME types
     */
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png'
    ];

    /**
     * Strictly whitelisted image binary types and their canonical extensions
     */
    public const ALLOWED_IMAGE_TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png'
    ];

    /**
     * Dangerous executable extensions pattern (for double-extension rejection)
     */
    private const EXECUTABLE_EXTENSIONS_PATTERN = '/\.(php|phtml|php\d|phps|cgi|pl|py|sh|bash|exe|bat|cmd|dll|bin|vbs|js|html|htm|htaccess|ini|log|conf|sql|env)\./i';

    /**
     * Checks whether runtime image re-encoding (GD extension) is active.
     */
    public static function isReencodingAvailable(): bool {
        return extension_loaded('gd') && function_exists('imagecreatefromjpeg') && function_exists('imagecreatefrompng');
    }

    /**
     * Handles, secures, and sanitizes observation report image uploads.
     * 
     * @param array $file The $_FILES['name'] array
     * @return array [success => bool, path => string|null, error => string|null]
     */
    public static function saveReportImage(array $file): array {
        if (!isset($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['success' => true, 'path' => null, 'error' => null];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'path' => null, 'error' => 'File upload error occurred (Error code: ' . (int)$file['error'] . ').'];
        }

        // 1. Validate file size (2MB limit)
        if (!isset($file['size']) || $file['size'] > self::MAX_FILE_SIZE) {
            return ['success' => false, 'path' => null, 'error' => 'File size exceeds maximum limit of 2MB.'];
        }

        if ($file['size'] <= 0) {
            return ['success' => false, 'path' => null, 'error' => 'Uploaded file is empty.'];
        }

        // 2. Validate original filename security
        $clientFilename = (string)($file['name'] ?? '');

        // 2.1 Null-byte and control character detection
        if (strpos($clientFilename, "\0") !== false || preg_match('/[\x00-\x1F\x7F]/', $clientFilename)) {
            return ['success' => false, 'path' => null, 'error' => 'Dangerous control characters detected in filename.'];
        }

        // 2.2 Path traversal detection
        if (strpos($clientFilename, '..') !== false || strpos($clientFilename, '/') !== false || strpos($clientFilename, '\\') !== false) {
            return ['success' => false, 'path' => null, 'error' => 'Path traversal pattern detected in filename.'];
        }

        // 2.3 Double-extension / executable pattern rejection (e.g. exploit.php.jpg)
        if (preg_match(self::EXECUTABLE_EXTENSIONS_PATTERN, $clientFilename)) {
            return ['success' => false, 'path' => null, 'error' => 'Dangerous double-extension pattern detected in filename.'];
        }

        // 2.4 Whitelisted apparent extension check
        $apparentExt = strtolower(pathinfo($clientFilename, PATHINFO_EXTENSION));
        if (!in_array($apparentExt, ['jpg', 'jpeg', 'png'], true)) {
            return ['success' => false, 'path' => null, 'error' => 'Invalid file extension. Only JPG, JPEG, and PNG images are allowed.'];
        }

        // 3. Temporary path validation
        $tempPath = (string)($file['tmp_name'] ?? '');
        if (empty($tempPath) || !file_exists($tempPath)) {
            return ['success' => false, 'path' => null, 'error' => 'Temporary upload file not found.'];
        }

        // 4. MIME-type validation via magic bytes (finfo)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tempPath);
        if ($mimeType === false || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return ['success' => false, 'path' => null, 'error' => 'Invalid file format. Only legitimate JPEG and PNG images are allowed.'];
        }

        // 5. Image structural integrity validation via getimagesize()
        $imageInfo = @getimagesize($tempPath);
        if ($imageInfo === false || !isset($imageInfo[2])) {
            return ['success' => false, 'path' => null, 'error' => 'Uploaded file is not a valid or readable image.'];
        }

        // Assert width and height are positive integers
        $width = (int)($imageInfo[0] ?? 0);
        $height = (int)($imageInfo[1] ?? 0);
        if ($width <= 0 || $height <= 0) {
            return ['success' => false, 'path' => null, 'error' => 'Image dimensions are invalid or corrupted.'];
        }

        // 5.1 Enforce dimension and pixel caps (Decompression bomb mitigation)
        if ($width > self::MAX_IMAGE_WIDTH || $height > self::MAX_IMAGE_HEIGHT || ($width * $height) > self::MAX_PIXELS) {
            return ['success' => false, 'path' => null, 'error' => 'Image dimensions exceed maximum allowable limits (' . self::MAX_IMAGE_WIDTH . 'x' . self::MAX_IMAGE_HEIGHT . ').'];
        }

        $imageType = (int)$imageInfo[2];
        if (!array_key_exists($imageType, self::ALLOWED_IMAGE_TYPES)) {
            return ['success' => false, 'path' => null, 'error' => 'Image binary signature does not match allowed types.'];
        }

        // 6. Select canonical extension based strictly on validated binary image type
        $canonicalExt = self::ALLOWED_IMAGE_TYPES[$imageType];

        // 7. Secure storage destination setup
        $baseUploadsDir = realpath(__DIR__ . '/../../public/assets/uploads');
        if ($baseUploadsDir === false) {
            $baseUploadsDir = __DIR__ . '/../../public/assets/uploads';
            if (!file_exists($baseUploadsDir)) {
                mkdir($baseUploadsDir, 0755, true);
            }
            $baseUploadsDir = realpath($baseUploadsDir) ?: $baseUploadsDir;
        }

        $targetDir = $baseUploadsDir . DIRECTORY_SEPARATOR . 'reports';
        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // Canonical realpath check to ensure targetDir remains inside baseUploadsDir
        $realTargetDir = realpath($targetDir);
        if ($realTargetDir === false || strpos($realTargetDir, (string)$baseUploadsDir) !== 0) {
            return ['success' => false, 'path' => null, 'error' => 'Storage destination path traversal prevented.'];
        }

        // 8. Generate safe, randomized server-side filename (user input discarded)
        $randomToken = bin2hex(random_bytes(16));
        $newName = 'report_' . time() . '_' . $randomToken . '.' . $canonicalExt;
        $targetFile = $realTargetDir . DIRECTORY_SEPARATOR . $newName;

        // 9. Re-encode image to strip polyglots and appended executable payloads (RSK-J1-02)
        if (self::isReencodingAvailable()) {
            $reencoded = false;

            if ($imageType === IMAGETYPE_JPEG) {
                $img = @imagecreatefromjpeg($tempPath);
                if ($img !== false) {
                    $reencoded = imagejpeg($img, $targetFile, 88);
                    imagedestroy($img);
                }
            } elseif ($imageType === IMAGETYPE_PNG) {
                $img = @imagecreatefrompng($tempPath);
                if ($img !== false) {
                    imagealphablending($img, false);
                    imagesavealpha($img, true);
                    $reencoded = imagepng($img, $targetFile, 8);
                    imagedestroy($img);
                }
            }

            if ($reencoded) {
                @chmod($targetFile, 0644);
                return [
                    'success' => true,
                    'path' => 'assets/uploads/reports/' . $newName,
                    'error' => null
                ];
            }

            return ['success' => false, 'path' => null, 'error' => 'Failed to process and sanitize uploaded image.'];
        }

        // Fallback: If GD is unavailable, persist validated binary directly
        // Note: Apache .htaccess denies script execution on this directory
        $moveSuccess = false;
        if (is_uploaded_file($tempPath)) {
            $moveSuccess = move_uploaded_file($tempPath, $targetFile);
        } else {
            // Permitted fallback strictly for CLI test harness execution
            $isTestEnv = defined('ELEGUARD_TEST_ENVIRONMENT') || (PHP_SAPI === 'cli');
            if ($isTestEnv) {
                $moveSuccess = copy($tempPath, $targetFile);
            }
        }

        if ($moveSuccess) {
            @chmod($targetFile, 0644);
            return [
                'success' => true,
                'path' => 'assets/uploads/reports/' . $newName,
                'error' => null
            ];
        }

        return ['success' => false, 'path' => null, 'error' => 'Failed to persist uploaded image to storage.'];
    }
}
