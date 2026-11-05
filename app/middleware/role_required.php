<?php
/**
 * Role Required Middleware
 * Restricts access to pages based on user role.
 * Expects $allowed_roles (array or string) to be defined before including this file.
 */

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Router.php';
require_once __DIR__ . '/../core/Session.php';

// Ensure the user is authenticated first
require_once __DIR__ . '/auth_required.php';

if (isset($allowed_roles)) {
    $roleAllowed = false;
    
    if (is_array($allowed_roles)) {
        if (Auth::hasRole($allowed_roles)) {
            $roleAllowed = true;
        }
    } else {
        if (Auth::hasRole($allowed_roles)) {
            $roleAllowed = true;
        }
    }
    
    if (!$roleAllowed) {
        Session::flash('error', 'Unauthorized: You do not have permission to view that section.');
        
        // Redirect user to their own dashboard
        $userRole = Auth::role();
        switch ($userRole) {
            case 'admin':
                Router::redirect('admin/dashboard.php');
                break;
            case 'control_officer':
                Router::redirect('control/dashboard.php');
                break;
            case 'driver':
                Router::redirect('driver/dashboard.php');
                break;
            case 'wildlife_officer':
                Router::redirect('wildlife/dashboard.php');
                break;
            case 'reporter':
                Router::redirect('reporter/dashboard.php');
                break;
            default:
                Router::redirect('login.php');
                break;
        }
    }
}
