<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

function tableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

if (!tableExists($db, 'intramural_seasons')) {
    echo "Creating intramural_seasons...\n";
    $db->exec("CREATE TABLE intramural_seasons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        year_label VARCHAR(30) NOT NULL,
        start_date DATE DEFAULT NULL,
        end_date DATE DEFAULT NULL,
        description TEXT,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        is_archived TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_season_year_label (year_label)
    ) ENGINE=InnoDB");
}

$year = (int) date('Y');
$label = $year . '-' . ($year + 1);
$check = $db->prepare('SELECT id FROM intramural_seasons WHERE year_label = ?');
$check->execute([$label]);
$seasonId = $check->fetchColumn();

if (!$seasonId) {
    // Prefer any existing active, else create current year
    $seasonId = $db->query('SELECT id FROM intramural_seasons ORDER BY id ASC LIMIT 1')->fetchColumn();
}

if (!$seasonId) {
    $db->prepare('INSERT INTO intramural_seasons (name, year_label, start_date, end_date, description, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([
            'Intramurals ' . $label,
            $label,
            $year . '-06-01',
            ($year + 1) . '-05-31',
            'Default intramurals season created during migration',
        ]);
    $seasonId = (int) $db->lastInsertId();
    echo "Created active season: $label (#$seasonId)\n";
} else {
    $seasonId = (int) $seasonId;
    $hasActive = (int) $db->query('SELECT COUNT(*) FROM intramural_seasons WHERE is_active = 1')->fetchColumn();
    if (!$hasActive) {
        $db->prepare('UPDATE intramural_seasons SET is_active = 1 WHERE id = ?')->execute([$seasonId]);
        echo "Marked season #$seasonId as active\n";
    }
}

$tables = [
    'intramural_matches' => 'AFTER id',
    'intramural_registrations' => 'AFTER id',
    'intramural_event_coaches' => 'AFTER id',
];

foreach ($tables as $table => $after) {
    if (!tableExists($db, $table)) {
        echo "Skip missing table $table\n";
        continue;
    }
    if (!columnExists($db, $table, 'season_id')) {
        echo "Adding $table.season_id...\n";
        $db->exec("ALTER TABLE `$table` ADD COLUMN season_id INT DEFAULT NULL $after");
        try {
            $db->exec("ALTER TABLE `$table` ADD CONSTRAINT fk_{$table}_season FOREIGN KEY (season_id) REFERENCES intramural_seasons(id) ON DELETE RESTRICT");
        } catch (PDOException $e) {
            echo "FK note ($table): " . $e->getMessage() . "\n";
        }
    }
    $updated = $db->exec("UPDATE `$table` SET season_id = $seasonId WHERE season_id IS NULL");
    echo "Backfilled $table: $updated rows -> season #$seasonId\n";
}

// Tighten uniqueness for registrations / event coaches per season
try {
    $db->exec('ALTER TABLE intramural_registrations DROP INDEX uq_athlete_sport');
} catch (PDOException $e) {
    // may already be dropped
}
try {
    $db->exec('ALTER TABLE intramural_registrations ADD UNIQUE KEY uq_athlete_sport_season (athlete_id, sport_id, season_id)');
    echo "Updated registrations unique key\n";
} catch (PDOException $e) {
    echo "Registrations unique key: " . $e->getMessage() . "\n";
}

try {
    $db->exec('ALTER TABLE intramural_event_coaches DROP INDEX uq_team_sport_coach');
} catch (PDOException $e) {
    // ignore
}
try {
    $db->exec('ALTER TABLE intramural_event_coaches ADD UNIQUE KEY uq_team_sport_season_coach (team_id, sport_id, season_id)');
    echo "Updated event coaches unique key\n";
} catch (PDOException $e) {
    echo "Event coaches unique key: " . $e->getMessage() . "\n";
}

echo "Active season: ";
$active = $db->query('SELECT id, name, year_label FROM intramural_seasons WHERE is_active = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
print_r($active);
echo "DONE\n";
