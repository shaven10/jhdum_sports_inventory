<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();

header('Content-Type: application/json; charset=utf-8');

$matchId = (int) get('match_id');
$teamId = (int) get('team_id');

if ($matchId <= 0 || $teamId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Select a match and team.']);
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT m.id, m.sport_id, m.season_id, m.team_a_id, m.team_b_id, m.round_label, m.round_number, m.scheduled_at, m.status,
        s.name as sport_name, s.category as sport_category,
        ta.name as team_a_name, tb.name as team_b_name
    FROM intramural_matches m
    JOIN intramural_sports s ON m.sport_id = s.id
    LEFT JOIN intramural_teams ta ON m.team_a_id = ta.id
    LEFT JOIN intramural_teams tb ON m.team_b_id = tb.id
    WHERE m.id = ?");
$stmt->execute([$matchId]);
$match = $stmt->fetch();

if (!$match) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Match not found.']);
    exit;
}

if (!canViewEvent((int) $match['sport_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to view this event roster.']);
    exit;
}

$side = null;
$teamName = '';
if ((int) $match['team_a_id'] === $teamId) {
    $side = 'A';
    $teamName = (string) ($match['team_a_name'] ?? 'Team A');
} elseif ((int) $match['team_b_id'] === $teamId) {
    $side = 'B';
    $teamName = (string) ($match['team_b_name'] ?? 'Team B');
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'That team is not assigned to this match.']);
    exit;
}

$players = getOfficialEventRoster((int) $match['sport_id'], $teamId, (int) ($match['season_id'] ?: getCurrentSeasonId()));
$list = [];
foreach ($players as $p) {
    $list[] = [
        'jersey' => (string) ($p['jersey_number'] ?: ''),
        'name' => athleteFullName($p),
        'student_id' => (string) ($p['student_id'] ?? ''),
        'gender' => ucfirst((string) ($p['gender'] ?? '')),
        'year_level' => (string) ($p['year_level'] ?? ''),
        'position' => (string) ($p['position'] ?? ''),
        'division' => (string) ($p['event_category'] ?? ''),
        'photo' => !empty($p['photo']) ? (UPLOAD_URL_ATHLETES . $p['photo']) : '',
    ];
}

echo json_encode([
    'ok' => true,
    'team' => $teamName,
    'side' => $side,
    'sport' => sportLabel([
        'name' => $match['sport_name'],
        'category' => $match['sport_category'],
    ]),
    'round' => $match['round_label'] ?: ('Round ' . (int) $match['round_number']),
    'scheduled_at' => $match['scheduled_at'] ? formatDateTime($match['scheduled_at']) : '',
    'status' => (string) $match['status'],
    'players' => $list,
    'count' => count($list),
]);
