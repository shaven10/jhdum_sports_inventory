<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/intramurals.php';

$db = getDB();

ensureEventManagersTable();

// Assign existing tabulator accounts to all sports for the active season (preserves prior global access)
$seasonId = (int) $db->query('SELECT id FROM intramural_seasons WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();
$tabulators = $db->query("SELECT id FROM users WHERE role = 'tabulator' AND is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$sports = $db->query('SELECT id FROM intramural_sports ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

if ($seasonId && $tabulators && $sports) {
    $managerId = (int) $tabulators[0];
    $insert = $db->prepare('INSERT IGNORE INTO intramural_event_managers (season_id, sport_id, manager_user_id) VALUES (?, ?, ?)');
    foreach ($sports as $sportId) {
        $insert->execute([$seasonId, (int) $sportId, $managerId]);
    }
    echo "Assigned tabulator user #{$managerId} to " . count($sports) . " events for season #{$seasonId}\n";
} elseif (!$tabulators) {
    echo "No tabulator accounts found — create Tournament Manager users and assign them under Sports → Tournament Managers.\n";
}

echo 'Event manager rows: ' . $db->query('SELECT COUNT(*) FROM intramural_event_managers')->fetchColumn() . "\n";
echo "DONE\n";
