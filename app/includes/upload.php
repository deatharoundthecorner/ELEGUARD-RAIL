<?php
/**
 * Image Upload Helper
 * Restricts uploads to JPEG/PNG, max size 2MB, and renames files safely.
 */

class Upload {
    
    /**
     * Handles file upload
     * @param array $file The $_FILES['name'] array
     * @return array [success => bool, path => string|null, error => string|null]
     */
    public static function saveReportImage($file) {
        if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['success' => true, 'path' => null, 'error' => null];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'path' => null, 'error' => 'File upload error occurred.'];
        }

        // Validate size (2MB limit)
        $maxSize = 2 * 1024 * 1024; // 2MB
        if ($file['size'] > $maxSize) {
            return ['success' => false, 'path' => null, 'error' => 'File size exceeds maximum limit of 2MB.'];
        }

        // Validate file type (JPEG, JPG, PNG)
        $tempPath = $file['tmp_name'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tempPath);
        
        $allowedMimeTypes = ['image/jpeg', 'image/jpg', 'image/png'];
        if (!in_array($mimeType, $allowedMimeTypes)) {
            return ['success' => false, 'path' => null, 'error' => 'Invalid file format. Only JPG, JPEG, and PNG are allowed.'];
        }

        // Extract extension safely
        $origName = htmlspecialchars(basename($file['name']), ENT_QUOTES, 'UTF-8');
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
            return ['success' => false, 'path' => null, 'error' => 'Invalid file extension.'];
        }

        // Target directory setup
        $targetDir = __DIR__ . '/../../public/assets/uploads/reports/';
        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // Create a unique name
        $newName = 'report_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $targetFile = $targetDir . $newName;

        if (move_uploaded_file($tempPath, $targetFile)) {
            // Return relative path from web root public/
            return ['success' => true, 'path' => 'assets/uploads/reports/' . $newName, 'error' => null];
        }

        return ['success' => false, 'path' => null, 'error' => 'Failed to save the uploaded file.'];
    }
}
