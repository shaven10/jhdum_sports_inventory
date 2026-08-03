<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "Ensuring team_play_sds (SDS single elimination) tournament format...\n";

try {
    $db->exec("ALTER TABLE intramural_sports MODIFY COLUMN tournament_format ENUM(
        'round_robin',
        'single_elimination',
        'single_elimination_consolation',
        'double_elimination',
        'group_knockout',
        'rank_first_to_last',
        'team_play_sds',
        'custom'
    ) NOT NULL DEFAULT 'round_robin'");
    echo "tournament_format ENUM updated.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

foreach (['Badminton', 'Table Tennis', 'Lawn Tennis'] as $name) {
    $stmt = $db->prepare("UPDATE intramural_sports
        SET tournament_format = 'team_play_sds',
            format_notes = COALESCE(NULLIF(format_notes, ''), 'Team Play SDS — single elimination; each team tie is Singles, Doubles, Singles (best of 3)')
        WHERE name = ?");
    $stmt->execute([$name]);
    echo "Set $name to Team Play SDS (Single Elimination).\n";
}

echo "Done.\n";
