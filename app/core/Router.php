<?php
/**
 * Router and Navigation Helper
 * Handles safe redirection and base URL resolution for relocatable directories.
 */

class Router {
    
    /**
     * Resolves absolute path from application root.
     * E.g. Router::url('assets/css/style.css') => '/eleguard-rail/public/assets/css/style.css'
     */
    public static function url($path = '') {
        // Find script subdirectory mapping.
        // If the script name is /eleguard-rail/public/index.php, the base is /eleguard-rail/public
        $scriptName = $_SERVER['SCRIPT_NAME'];
        
        // Find position of '/public/' in script name
        $publicPos = strpos($scriptName, '/public/');
        
        if ($publicPos !== false) {
            $base = substr($scriptName, 0, $publicPos + 8); // includes '/public/'
        } else {
            // Fallback: If not in public directly (e.g. root redirector)
            $base = dirname($scriptName);
            $base = rtrim($base, '/\\') . '/public/';
        }
        
        $base = rtrim($base, '/');
        $path = ltrim($path, '/');
        
        return $base . '/' . $path;
    }

    /**
     * Perform a redirection.
     */
    public static function redirect($path) {
        $url = self::url($path);
        header("Location: " . $url);
        exit;
    }

    /**
     * Resolves the correct relative dashboard path for a given user role.
     */
    public static function getDashboardPath($role = null) {
        if ($role === null) {
            require_once __DIR__ . '/Auth.php';
            $role = Auth::role();
        }
        switch ($role) {
            case 'admin':
                return 'admin/dashboard.php';
            case 'control_officer':
                return 'control/dashboard.php';
            case 'wildlife_officer':
                return 'wildlife/dashboard.php';
            case 'driver':
                return 'driver/dashboard.php';
            case 'reporter':
            default:
                return 'reporter/dashboard.php';
        }
    }

    /**
     * Redirects user to their role-specific dashboard.
     */
    public static function redirectToDashboard($role = null) {
        self::redirect(self::getDashboardPath($role));
    }
}
