<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

function columnExistsTm(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function tableExistsTm(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

echo "Updating users.role ENUM (add tournament_manager)...\n";
$db->exec("ALTER TABLE users MODIFY COLUMN role ENUM(
    'admin',
    'coordinator',
    'staff',
    'unit_manager',
    'coach',
    'tabulator',
    'tournament_manager',
    'student'
) NOT NULL DEFAULT 'student'");

if (!tableExistsTm($db, 'intramural_event_managers')) {
    echo "Creating intramural_event_managers...\n";
    $db->exec("CREATE TABLE intramural_event_managers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        season_id INT NOT NULL,
        sport_id INT NOT NULL,
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_event_manager_season_sport (season_id, sport_id),
        KEY idx_event_manager_user (user_id, season_id),
        FOREIGN KEY (season_id) REFERENCES intramural_seasons(id) ON DELETE RESTRICT,
        FOREIGN KEY (sport_id) REFERENCES intramural_sports(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
}

$password = password_hash('admin123', PASSWORD_DEFAULT);
$exists = $db->prepare('SELECT id FROM users WHERE username = ?');
$exists->execute(['tm']);
if (!$exists->fetchColumn()) {
    $hasPlain = columnExistsTm($db, 'users', 'password_plain');
    if ($hasPlain) {
        $stmt = $db->prepare('INSERT INTO users (username, email, password, password_plain, first_name, last_name, department, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute(['tm', 'tm@jhcsc.edu.ph', $password, 'admin123', 'Tournament', 'Manager', 'Sports Unit', 'tournament_manager']);
    } else {
        $stmt = $db->prepare('INSERT INTO users (username, email, password, first_name, last_name, department, role) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute(['tm', 'tm@jhcsc.edu.ph', $password, 'Tournament', 'Manager', 'Sports Unit', 'tournament_manager']);
    }
    echo "Created demo user: tm / admin123\n";
} else {
    $db->prepare("UPDATE users SET role = 'tournament_manager' WHERE username = 'tm'")->execute();
    echo "Ensured demo user tm has tournament_manager role.\n";
}

echo "DONE\n";
