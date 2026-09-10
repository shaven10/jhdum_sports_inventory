<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/intramurals.php';

$db = getDB();

echo "Ensuring team_play_sds (SDS single elimination) tournament format...\n";
ensureTournamentFormatEnum();
echo "tournament_format ENUM updated.\n";

foreach (['Badminton', 'Table Tennis', 'Lawn Tennis'] as $name) {
    $stmt = $db->prepare("UPDATE intramural_sports
        SET tournament_format = 'team_play_sds',
            format_notes = COALESCE(NULLIF(format_notes, ''), 'Team Play SDS — single elimination; each team tie is Singles, Doubles, Singles (best of 3)')
        WHERE name = ?");
    $stmt->execute([$name]);
    echo "Set $name to Team Play SDS (Single Elimination).\n";
}

echo "Done.\n";
