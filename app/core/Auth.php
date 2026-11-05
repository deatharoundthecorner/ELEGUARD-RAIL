<?php
/**
 * Authentication Helper Core Class
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Session.php';

class Auth {
    
    public static function login($email, $password) {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        
        if ($user && $user['status'] === 'active' && password_verify($password, $user['password'])) {
            Session::set('user_id', $user['id']);
            Session::set('user_name', $user['name']);
            Session::set('user_email', $user['email']);
            Session::set('user_role', $user['role']);
            
            // Log successful login
            self::logActivity($user['id'], 'User Login', 'User successfully logged in.');
            return true;
        }
        
        // Log failed attempt if user exists but bad password
        if ($user) {
            self::logActivity($user['id'], 'Failed Login Attempt', 'Attempt with incorrect password.');
        }
        return false;
    }

    public static function check() {
        return Session::has('user_id');
    }

    public static function user() {
        if (!self::check()) return null;
        return [
            'id' => Session::get('user_id'),
            'name' => Session::get('user_name'),
            'email' => Session::get('user_email'),
            'role' => Session::get('user_role')
        ];
    }

    public static function role() {
        return Session::get('user_role');
    }

    public static function hasRole($roles) {
        if (!self::check()) return false;
        $userRole = self::role();
        if (is_array($roles)) {
            return in_array($userRole, $roles);
        }
        return $userRole === $roles;
    }

    public static function logout() {
        $userId = Session::get('user_id');
        if ($userId) {
            self::logActivity($userId, 'User Logout', 'User logged out.');
        }
        Session::destroy();
    }

    /**
     * Utility method to write records directly to the system logs database
     */
    public static function logActivity($userId, $action, $description) {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("INSERT INTO system_logs (user_id, action, description) VALUES (:uid, :act, :desc)");
            $stmt->execute([
                ':uid' => $userId,
                ':act' => $action,
                ':desc' => $description
            ]);
        } catch (PDOException $e) {
            // Ignore logging errors to prevent breaking execution paths
        }
    }
}
