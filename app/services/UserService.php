<?php
/**
 * User Service for database operations
 */

require_once __DIR__ . '/../core/Database.php';

class UserService {

    public static function getAll($roleFilter = null, $searchQuery = null) {
        $db = Database::getInstance()->getConnection();
        
        $sql = "SELECT id, name, email, role, status, created_at FROM users WHERE 1=1";
        $params = [];
        
        if ($roleFilter) {
            $sql .= " AND role = :role";
            $params[':role'] = $roleFilter;
        }
        
        if ($searchQuery) {
            $sql .= " AND (name LIKE :search OR email LIKE :search)";
            $params[':search'] = '%' . $searchQuery . '%';
        }
        
        $sql .= " ORDER BY created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getById($id) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public static function add($name, $email, $password, $role, $status = 'active') {
        $db = Database::getInstance()->getConnection();
        
        // Check duplicate email
        $checkStmt = $db->prepare("SELECT id FROM users WHERE email = :email");
        $checkStmt->execute([':email' => $email]);
        if ($checkStmt->fetch()) {
            return false;
        }

        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO users (name, email, password, role, status) VALUES (:name, :email, :password, :role, :status)");
        return $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':password' => $hashed,
            ':role' => $role,
            ':status' => $status
        ]);
    }

    public static function update($id, $name, $email, $role, $status, $password = null) {
        $db = Database::getInstance()->getConnection();
        
        // Check duplicate email (excluding own id)
        $checkStmt = $db->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
        $checkStmt->execute([':email' => $email, ':id' => $id]);
        if ($checkStmt->fetch()) {
            return false;
        }

        if (!empty($password)) {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $sql = "UPDATE users SET name = :name, email = :email, role = :role, status = :status, password = :password WHERE id = :id";
            $params = [
                ':name' => $name,
                ':email' => $email,
                ':role' => $role,
                ':status' => $status,
                ':password' => $hashed,
                ':id' => $id
            ];
        } else {
            $sql = "UPDATE users SET name = :name, email = :email, role = :role, status = :status WHERE id = :id";
            $params = [
                ':name' => $name,
                ':email' => $email,
                ':role' => $role,
                ':status' => $status,
                ':id' => $id
            ];
        }

        return $stmt = $db->prepare($sql)->execute($params);
    }

    public static function countAll() {
        $db = Database::getInstance()->getConnection();
        return (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    }
}
