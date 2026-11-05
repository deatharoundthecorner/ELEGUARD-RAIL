<?php
/**
 * EleGuard Rail Setup Script
 * Seeds/updates sample users using secure password_hash().
 */

require_once __DIR__ . '/../app/config/database.php';

try {
    // Create PDO connection (without database name first, to check if db exists)
    $dsnNoDb = "mysql:host=" . DB_HOST . ";charset=utf8mb4";
    $pdo = new PDO($dsnNoDb, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Create database if not exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE " . DB_NAME);
    
    echo "Database '" . DB_NAME . "' verified/created.\n";
    
    // Check if users table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
    if (!$tableCheck) {
        echo "Table 'users' does not exist yet. Please import eleguard_rail.sql first.\n";
        exit(1);
    }
    
    // Define sample users
    $users = [
        [
            'id' => 1,
            'name' => 'System Admin',
            'email' => 'admin@eleguard.lk',
            'password' => 'admin123',
            'role' => 'admin'
        ],
        [
            'id' => 2,
            'name' => 'Railway Controller',
            'email' => 'control@eleguard.lk',
            'password' => 'control123',
            'role' => 'control_officer'
        ],
        [
            'id' => 3,
            'name' => 'Train Driver A',
            'email' => 'driver@eleguard.lk',
            'password' => 'driver123',
            'role' => 'driver'
        ],
        [
            'id' => 4,
            'name' => 'Wildlife Ranger',
            'email' => 'wildlife@eleguard.lk',
            'password' => 'wildlife123',
            'role' => 'wildlife_officer'
        ],
        [
            'id' => 5,
            'name' => 'Villager Reporter',
            'email' => 'reporter@eleguard.lk',
            'password' => 'reporter123',
            'role' => 'reporter'
        ]
    ];
    
    $stmt = $pdo->prepare("
        INSERT INTO users (id, name, email, password, role, status)
        VALUES (:id, :name, :email, :password, :role, 'active')
        ON DUPLICATE KEY UPDATE
            name = :uname,
            password = :upass,
            role = :urole
    ");
    
    foreach ($users as $u) {
        $hashedPassword = password_hash($u['password'], PASSWORD_BCRYPT);
        $stmt->execute([
            ':id' => $u['id'],
            ':name' => $u['name'],
            ':email' => $u['email'],
            ':password' => $hashedPassword,
            ':role' => $u['role'],
            ':uname' => $u['name'],
            ':upass' => $hashedPassword,
            ':urole' => $u['role']
        ]);
        echo "User '{$u['email']}' loaded/updated successfully.\n";
    }
    
    echo "All sample users successfully seeded with secure password hashes.\n";
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
    exit(1);
}
