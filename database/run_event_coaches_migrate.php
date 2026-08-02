<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

function tableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

if (!tableExists($db, 'intramural_event_coaches')) {
    echo "Creating intramural_event_coaches...\n";
    $db->exec("CREATE TABLE intramural_event_coaches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        team_id INT NOT NULL,
        sport_id INT NOT NULL,
        coach_user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_team_sport_coach (team_id, sport_id),
        FOREIGN KEY (team_id) REFERENCES intramural_teams(id) ON DELETE CASCADE,
        FOREIGN KEY (sport_id) REFERENCES intramural_sports(id) ON DELETE CASCADE,
        FOREIGN KEY (coach_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
}

// Migrate legacy single team coach to all active sports for that team
$legacy = $db->query('SELECT id, coach_user_id FROM intramural_teams WHERE coach_user_id IS NOT NULL')->fetchAll();
$sports = $db->query('SELECT id FROM intramural_sports WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
$insert = $db->prepare('INSERT IGNORE INTO intramural_event_coaches (team_id, sport_id, coach_user_id) VALUES (?, ?, ?)');

foreach ($legacy as $team) {
    foreach ($sports as $sportId) {
        $insert->execute([(int) $team['id'], (int) $sportId, (int) $team['coach_user_id']]);
    }
    echo "Migrated team #{$team['id']} coach to " . count($sports) . " events\n";
}

echo "Event coach rows: " . $db->query('SELECT COUNT(*) FROM intramural_event_coaches')->fetchColumn() . "\n";
echo "DONE\n";
