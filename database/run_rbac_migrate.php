<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

echo "Updating users.role ENUM...\n";
$db->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'coordinator', 'staff', 'unit_manager', 'coach', 'student') NOT NULL DEFAULT 'student'");

if (!columnExists($db, 'users', 'team_id')) {
    echo "Adding users.team_id...\n";
    $db->exec('ALTER TABLE users ADD COLUMN team_id INT DEFAULT NULL AFTER role');
    try {
        $db->exec('ALTER TABLE users ADD CONSTRAINT fk_users_team FOREIGN KEY (team_id) REFERENCES intramural_teams(id) ON DELETE SET NULL');
    } catch (PDOException $e) {
        echo "FK users.team_id: " . $e->getMessage() . "\n";
    }
}

if (!columnExists($db, 'intramural_teams', 'unit_manager_id')) {
    echo "Adding intramural_teams.unit_manager_id / coach_user_id...\n";
    $db->exec('ALTER TABLE intramural_teams ADD COLUMN unit_manager_id INT DEFAULT NULL AFTER coach_name');
    $db->exec('ALTER TABLE intramural_teams ADD COLUMN coach_user_id INT DEFAULT NULL AFTER unit_manager_id');
    try {
        $db->exec('ALTER TABLE intramural_teams ADD CONSTRAINT fk_team_unit_manager FOREIGN KEY (unit_manager_id) REFERENCES users(id) ON DELETE SET NULL');
        $db->exec('ALTER TABLE intramural_teams ADD CONSTRAINT fk_team_coach_user FOREIGN KEY (coach_user_id) REFERENCES users(id) ON DELETE SET NULL');
    } catch (PDOException $e) {
        echo "FK teams: " . $e->getMessage() . "\n";
    }
}

// Seed unit managers and coaches for each team if missing
$teams = $db->query('SELECT * FROM intramural_teams WHERE is_active = 1 ORDER BY id')->fetchAll();
$password = password_hash('coach123', PASSWORD_DEFAULT);

foreach ($teams as $team) {
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '', $team['short_name'] ?: $team['name']));
    $slug = $slug !== '' ? $slug : ('team' . $team['id']);

    $managerUser = 'um_' . $slug;
    $coachUser = 'coach_' . $slug;

    $exists = $db->prepare('SELECT id FROM users WHERE username = ?');
    $exists->execute([$managerUser]);
    $managerId = $exists->fetchColumn();

    if (!$managerId) {
        $stmt = $db->prepare('INSERT INTO users (username, email, password, password_plain, first_name, last_name, department, role, team_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $managerUser,
            $managerUser . '@jhcsc.edu.ph',
            $password,
            'coach123',
            'Unit',
            'Manager (' . ($team['short_name'] ?: $team['name']) . ')',
            $team['department'],
            'unit_manager',
            $team['id'],
        ]);
        $managerId = (int) $db->lastInsertId();
        echo "Created unit manager: $managerUser / coach123\n";
    } else {
        $db->prepare("UPDATE users SET role = 'unit_manager', team_id = ? WHERE id = ?")->execute([$team['id'], $managerId]);
    }

    $exists->execute([$coachUser]);
    $coachId = $exists->fetchColumn();

    if (!$coachId) {
        $stmt = $db->prepare('INSERT INTO users (username, email, password, password_plain, first_name, last_name, department, role, team_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $coachUser,
            $coachUser . '@jhcsc.edu.ph',
            $password,
            'coach123',
            'Coach',
            ($team['short_name'] ?: $team['name']),
            $team['department'],
            'coach',
            $team['id'],
        ]);
        $coachId = (int) $db->lastInsertId();
        echo "Created coach: $coachUser / coach123\n";
    } else {
        $db->prepare("UPDATE users SET role = 'coach', team_id = ? WHERE id = ?")->execute([$team['id'], $coachId]);
    }

    $db->prepare('UPDATE intramural_teams SET unit_manager_id = ?, coach_user_id = ?, coach_name = COALESCE(NULLIF(coach_name, ""), ?) WHERE id = ?')
        ->execute([$managerId, $coachId, 'Coach ' . ($team['short_name'] ?: $team['name']), $team['id']]);
}

echo "DONE\n";
