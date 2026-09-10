<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

echo "Updating users.role ENUM (add tabulator)...\n";
$db->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'coordinator', 'staff', 'unit_manager', 'coach', 'tabulator', 'student') NOT NULL DEFAULT 'student'");

if (!columnExists($db, 'intramural_sports', 'tournament_format')) {
    echo "Adding intramural_sports.tournament_format...\n";
    $db->exec("ALTER TABLE intramural_sports ADD COLUMN tournament_format ENUM('round_robin', 'single_elimination', 'double_elimination', 'group_knockout', 'custom') NOT NULL DEFAULT 'round_robin' AFTER schedule_notes");
}

if (!columnExists($db, 'intramural_sports', 'format_notes')) {
    echo "Adding intramural_sports.format_notes...\n";
    $db->exec("ALTER TABLE intramural_sports ADD COLUMN format_notes TEXT NULL AFTER tournament_format");
}

// Seed a demo tabulator account if missing
$password = password_hash('admin123', PASSWORD_DEFAULT);
$exists = $db->prepare('SELECT id FROM users WHERE username = ?');
$exists->execute(['tabulator']);
if (!$exists->fetchColumn()) {
    $stmt = $db->prepare('INSERT INTO users (username, email, password, password_plain, first_name, last_name, department, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        'tabulator',
        'tabulator@jhcsc.edu.ph',
        $password,
        'admin123',
        'Match',
        'Tabulator',
        'Sports Unit',
        'tabulator',
    ]);
    echo "Created demo user: tabulator / admin123\n";
} else {
    $db->prepare("UPDATE users SET role = 'tabulator', password = ?, password_plain = ? WHERE username = 'tabulator'")->execute([$password, 'admin123']);
    echo "Updated demo user: tabulator / admin123\n";
}

echo "DONE\n";
