<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "Updating users.role ENUM (add secretariat)...\n";
$db->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'coordinator', 'staff', 'unit_manager', 'coach', 'tabulator', 'secretariat', 'student') NOT NULL DEFAULT 'student'");

$password = password_hash('admin123', PASSWORD_DEFAULT);
$exists = $db->prepare('SELECT id FROM users WHERE username = ?');
$exists->execute(['secretariat']);
if (!$exists->fetchColumn()) {
    $stmt = $db->prepare('INSERT INTO users (username, email, password, password_plain, first_name, last_name, department, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        'secretariat',
        'secretariat@jhcsc.edu.ph',
        $password,
        'admin123',
        'Intramurals',
        'Secretariat',
        'Sports Unit',
        'secretariat',
    ]);
    echo "Created demo user: secretariat / admin123\n";
} else {
    $db->prepare("UPDATE users SET role = 'secretariat', password = ?, password_plain = ? WHERE username = 'secretariat'")->execute([$password, 'admin123']);
    echo "Updated demo user: secretariat / admin123\n";
}

echo "DONE\n";
