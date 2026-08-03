<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

if (!columnExists($db, 'users', 'password_plain')) {
    echo "Adding users.password_plain...\n";
    $db->exec('ALTER TABLE users ADD COLUMN password_plain VARCHAR(255) DEFAULT NULL AFTER password');
    $db->exec("UPDATE users SET password_plain = 'admin123' WHERE password_plain IS NULL");
    echo "Backfilled existing users with default password (admin123).\n";
} else {
    echo "users.password_plain already exists.\n";
}

echo "Done.\n";
