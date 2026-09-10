<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/intramurals.php';

echo "Ensuring Team Play SDS (Single Elimination with Consolation) tournament format...\n";
ensureTournamentFormatEnum();
echo "tournament_format ENUM is ready (includes team_play_sds_consolation).\n";
echo "Done.\n";
