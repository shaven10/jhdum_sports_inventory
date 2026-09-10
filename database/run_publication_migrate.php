<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "Updating users.role ENUM (add publication)...\n";
$db->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'coordinator', 'staff', 'unit_manager', 'coach', 'tabulator', 'secretariat', 'publication', 'student') NOT NULL DEFAULT 'student'");

$password = password_hash('admin123', PASSWORD_DEFAULT);
$exists = $db->prepare('SELECT id FROM users WHERE username = ?');
$exists->execute(['publication']);
if (!$exists->fetchColumn()) {
    $stmt = $db->prepare('INSERT INTO users (username, email, password, password_plain, first_name, last_name, department, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        'publication',
        'publication@jhcsc.edu.ph',
        $password,
        'admin123',
        'Intramurals',
        'Publication',
        'Sports Unit',
        'publication',
    ]);
    echo "Created demo user: publication / admin123\n";
} else {
    $db->prepare("UPDATE users SET role = 'publication', password = ?, password_plain = ? WHERE username = 'publication'")->execute([$password, 'admin123']);
    echo "Updated demo user: publication / admin123\n";
}

echo "DONE\n";
