<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/intramurals.php';

ensureSportGameDurationColumn();

$db = getDB();
$sports = $db->query('SELECT * FROM intramural_sports')->fetchAll();
$updated = 0;
$stmt = $db->prepare('UPDATE intramural_sports SET game_duration_minutes = ? WHERE id = ?');

foreach ($sports as $sport) {
    $current = (int) ($sport['game_duration_minutes'] ?? 0);
    $target = guessDefaultGameDuration($sport);
    if ($current <= 0 || ($current === DEFAULT_GAME_DURATION_MINUTES && $target !== DEFAULT_GAME_DURATION_MINUTES)) {
        $stmt->execute([$target, (int) $sport['id']]);
        $updated++;
    }
}

echo "intramural_sports.game_duration_minutes column is ready.\n";
echo "Updated {$updated} sport(s) with sport-specific default durations.\n";
