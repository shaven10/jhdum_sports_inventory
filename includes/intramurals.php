<?php
/**
 * Intramurals helper functions
 */

function generateAthleteCode(): string
{
    return 'ATH-' . date('Y') . '-' . strtoupper(substr(uniqid(), -5));
}

function tournamentFormatLabels(): array
{
    return [
        'round_robin' => 'Round Robin',
        'single_elimination' => 'Single Elimination',
        'single_elimination_consolation' => 'Single Elimination with Consolation',
        'double_elimination' => 'Double Elimination',
        'group_knockout' => 'Group Stage → Knockout',
        'rank_first_to_last' => 'Rank from First to Last',
        'team_play_sds' => 'Team Play SDS (Single Elimination)',
        'custom' => 'Custom / Agreed Format',
    ];
}

function tournamentFormatLabel(?string $format): string
{
    $labels = tournamentFormatLabels();
    $key = $format ?: 'round_robin';
    return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
}

/**
 * Build fixture list for a tournament format.
 * Each fixture: ['team_a_id'=>?int, 'team_b_id'=>?int, 'round_number'=>int, 'round_label'=>string, 'match_order'=>int, 'notes'=>?string]
 *
 * @param list<int> $teamIds
 * @return list<array<string, mixed>>
 */
function buildTournamentFixtures(string $format, array $teamIds): array
{
    $teamIds = array_values(array_unique(array_map('intval', $teamIds)));
    $teamIds = array_values(array_filter($teamIds, fn($id) => $id > 0));
    sort($teamIds);

    if (count($teamIds) < 2) {
        return [];
    }

    switch ($format) {
        case 'single_elimination':
            return buildSingleEliminationFixtures($teamIds);
        case 'single_elimination_consolation':
            return buildSingleEliminationConsolationFixtures($teamIds);
        case 'double_elimination':
            // First pass: generate single-elim bracket; consolation rounds can be added later.
            $fixtures = buildSingleEliminationFixtures($teamIds);
            foreach ($fixtures as &$f) {
                $f['notes'] = trim(($f['notes'] ?? '') . ' (Double elimination — winners bracket)');
            }
            unset($f);
            return $fixtures;
        case 'group_knockout':
            // Group stage fixtures (round robin). Knockout bracket can be generated after standings.
            $group = buildRoundRobinFixtures($teamIds, 'Group Stage');
            foreach ($group as &$f) {
                $f['notes'] = 'Group stage — generate knockout bracket after standings if needed';
            }
            unset($f);
            return $group;
        case 'rank_first_to_last':
            return buildRankFirstToLastFixtures($teamIds);
        case 'team_play_sds':
            return buildTeamPlaySdsFixtures($teamIds);
        case 'custom':
        case 'round_robin':
        default:
            return buildRoundRobinFixtures($teamIds, 'Round Robin');
    }
}

/**
 * @param list<int> $teamIds
 * @return list<array<string, mixed>>
 */
function buildRoundRobinFixtures(array $teamIds, string $roundLabel = 'Round Robin'): array
{
    $fixtures = [];
    $n = count($teamIds);
    $order = 0;
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $order++;
            $fixtures[] = [
                'team_a_id' => $teamIds[$i],
                'team_b_id' => $teamIds[$j],
                'round_number' => 1,
                'round_label' => $roundLabel,
                'match_order' => $order,
                'notes' => null,
            ];
        }
    }
    return $fixtures;
}

/**
 * Rank from first to last: one match per team in a single round.
 * Teams are paired (1st vs 2nd seed order, 3rd vs 4th, …); final standings rank everyone 1st through last.
 *
 * @param list<int> $teamIds
 * @return list<array<string, mixed>>
 */
function buildRankFirstToLastFixtures(array $teamIds): array
{
    $teamIds = array_values(array_unique(array_map('intval', $teamIds)));
    sort($teamIds);
    $n = count($teamIds);
    if ($n < 2) {
        return [];
    }

    $fixtures = [];
    $order = 0;

    for ($i = 0; $i + 1 < $n; $i += 2) {
        $order++;
        $fixtures[] = [
            'team_a_id' => $teamIds[$i],
            'team_b_id' => $teamIds[$i + 1],
            'round_number' => 1,
            'round_label' => 'Rank from First to Last',
            'match_order' => $order,
            'notes' => 'One match per team — standings rank all teams 1st through last',
        ];
    }

    return $fixtures;
}

/** Racket sports that use SDS (Singles–Doubles–Singles) team ties. */
function racketSdsSportNames(): array
{
    return ['Badminton', 'Table Tennis', 'Lawn Tennis'];
}

function isRacketSdsSport(string $sportName): bool
{
    return in_array(trim($sportName), racketSdsSportNames(), true);
}

/**
 * Team Play SDS (Single Elimination): single-elim team bracket.
 * Each team tie expands into Singles → Doubles → Singles rubbers (best of 3).
 *
 * @param list<int> $teamIds
 * @return list<array<string, mixed>>
 */
function buildTeamPlaySdsFixtures(array $teamIds): array
{
    $teamIds = array_values(array_unique(array_map('intval', $teamIds)));
    sort($teamIds);
    $n = count($teamIds);
    if ($n < 2) {
        return [];
    }

    $ties = buildSingleEliminationFixtures($teamIds);
    $legs = [
        ['suffix' => 'Singles 1', 'note' => 'First singles rubber'],
        ['suffix' => 'Doubles', 'note' => 'Doubles rubber'],
        ['suffix' => 'Singles 2', 'note' => 'Second singles rubber'],
    ];

    $fixtures = [];
    $order = 0;
    $tieNum = 0;

    foreach ($ties as $tie) {
        $tieNum++;
        $roundLabel = (string) ($tie['round_label'] ?? ('Round ' . (int) ($tie['round_number'] ?? 1)));
        $baseRound = (int) ($tie['round_number'] ?? 1);
        $isTbd = empty($tie['team_a_id']) || empty($tie['team_b_id']);

        foreach ($legs as $legIndex => $leg) {
            $order++;
            $fixtures[] = [
                'team_a_id' => $tie['team_a_id'],
                'team_b_id' => $tie['team_b_id'],
                'round_number' => $baseRound,
                'round_label' => $roundLabel . ' — SDS ' . $leg['suffix'],
                'match_order' => $order,
                'notes' => ($isTbd ? 'TBD — fill teams after previous SDS ties. ' : '')
                    . 'SDS single-elim tie #' . $tieNum . ' — ' . $leg['note']
                    . '. Team wins the tie with 2 of 3 rubbers.',
            ];
        }
    }

    return $fixtures;
}

/**
 * Single elimination: first round with byes, plus TBD shells for later rounds.
 *
 * @param list<int> $teamIds
 * @return list<array<string, mixed>>
 */
function buildSingleEliminationFixtures(array $teamIds): array
{
    $n = count($teamIds);
    $size = 1;
    while ($size < $n) {
        $size *= 2;
    }

    // Seed list with byes (null) to fill bracket
    $slots = $teamIds;
    while (count($slots) < $size) {
        $slots[] = null; // bye
    }

    $fixtures = [];
    $order = 0;
    $rounds = (int) log($size, 2);
    $roundLabels = [
        1 => $size === 2 ? 'Final' : ($size === 4 ? 'Semi-finals' : 'Round of ' . $size),
    ];
    for ($r = 2; $r <= $rounds; $r++) {
        $teamsInRound = (int) ($size / (2 ** ($r - 1)));
        if ($teamsInRound === 2) {
            $roundLabels[$r] = 'Final';
        } elseif ($teamsInRound === 4) {
            $roundLabels[$r] = 'Semi-finals';
        } elseif ($teamsInRound === 8) {
            $roundLabels[$r] = 'Quarter-finals';
        } else {
            $roundLabels[$r] = 'Round of ' . $teamsInRound;
        }
    }

    // Round 1 pairings
    $nextAdvancers = [];
    for ($i = 0; $i < $size; $i += 2) {
        $a = $slots[$i];
        $b = $slots[$i + 1];
        if ($a === null && $b === null) {
            $nextAdvancers[] = null;
            continue;
        }
        if ($a === null || $b === null) {
            // Bye — team advances, no match created
            $nextAdvancers[] = $a ?? $b;
            continue;
        }
        $order++;
        $fixtures[] = [
            'team_a_id' => $a,
            'team_b_id' => $b,
            'round_number' => 1,
            'round_label' => $roundLabels[1] ?? 'Round 1',
            'match_order' => $order,
            'notes' => null,
        ];
        $nextAdvancers[] = null; // winner TBD
    }

    // Later rounds as TBD shells
    for ($r = 2; $r <= $rounds; $r++) {
        $count = (int) ($size / (2 ** $r));
        if ($count < 1) {
            break;
        }
        for ($i = 0; $i < $count; $i++) {
            $order++;
            $fixtures[] = [
                'team_a_id' => null,
                'team_b_id' => null,
                'round_number' => $r,
                'round_label' => $roundLabels[$r] ?? ('Round ' . $r),
                'match_order' => $order,
                'notes' => 'TBD — fill teams after previous round results',
            ];
        }
    }

    return $fixtures;
}

/**
 * Single elimination championship bracket plus consolation matches.
 * - 3rd Place Playoff between championship semi-final losers (4+ teams)
 * - First-round loser consolation bracket for 8+ team draws (5th–8th place)
 *
 * @param list<int> $teamIds
 * @return list<array<string, mixed>>
 */
function buildSingleEliminationConsolationFixtures(array $teamIds): array
{
    $fixtures = buildSingleEliminationFixtures($teamIds);

    $n = count($teamIds);
    $size = 1;
    while ($size < $n) {
        $size *= 2;
    }
    $rounds = (int) log($size, 2);

    $order = 0;
    foreach ($fixtures as $f) {
        $order = max($order, (int) $f['match_order']);
    }

    foreach ($fixtures as &$f) {
        $existing = (string) ($f['notes'] ?? '');
        if ($existing === 'TBD — fill teams after previous round results') {
            $f['notes'] = 'TBD — championship bracket';
        } elseif ($existing === '') {
            $f['notes'] = 'Championship bracket';
        }
    }
    unset($f);

    if ($rounds >= 2) {
        $order++;
        $fixtures[] = [
            'team_a_id' => null,
            'team_b_id' => null,
            'round_number' => $rounds + 1,
            'round_label' => 'Consolation Final (3rd Place)',
            'match_order' => $order,
            'notes' => 'TBD — losers of championship semi-finals',
        ];
    }

    if ($size >= 8) {
        $slots = $teamIds;
        while (count($slots) < $size) {
            $slots[] = null;
        }
        $r1Losers = 0;
        for ($i = 0; $i < $size; $i += 2) {
            if ($slots[$i] !== null && $slots[$i + 1] !== null) {
                $r1Losers++;
            }
        }
        if ($r1Losers >= 2) {
            $fixtures = array_merge($fixtures, buildConsolationBracketShells($r1Losers, $order));
        }
    }

    return $fixtures;
}

/**
 * TBD shell matches for a consolation bracket among first-round losers.
 *
 * @return list<array<string, mixed>>
 */
function buildConsolationBracketShells(int $loserCount, int &$order): array
{
    $fixtures = [];
    $size = 1;
    while ($size < $loserCount) {
        $size *= 2;
    }
    $rounds = (int) log($size, 2);

    $roundLabels = [];
    for ($r = 1; $r <= $rounds; $r++) {
        $teamsInRound = (int) ($size / (2 ** ($r - 1)));
        if ($teamsInRound === 2) {
            $roundLabels[$r] = 'Consolation Final (5th Place)';
        } elseif ($teamsInRound === 4) {
            $roundLabels[$r] = 'Consolation Semi-finals';
        } else {
            $roundLabels[$r] = 'Consolation — Round of ' . $teamsInRound;
        }
    }

    for ($r = 1; $r <= $rounds; $r++) {
        $count = (int) ($size / (2 ** $r));
        for ($i = 0; $i < $count; $i++) {
            $order++;
            $fixtures[] = [
                'team_a_id' => null,
                'team_b_id' => null,
                'round_number' => 100 + $r,
                'round_label' => $roundLabels[$r] ?? ('Consolation Round ' . $r),
                'match_order' => $order,
                'notes' => $r === 1
                    ? 'TBD — first-round losers from championship bracket'
                    : 'TBD — fill teams after previous consolation results',
            ];
        }
    }

    return $fixtures;
}

/**
 * Build hourly schedule slots between two dates (inclusive), default 7:00–17:00.
 * Dates may be any range — not limited to the active season period.
 *
 * @return list<string> Datetime strings (Y-m-d H:i:s)
 */
function buildScheduleSlots(?string $startDate, ?string $endDate, int $startHour = 7, int $endHour = 17): array
{
    if (!$startDate || !$endDate) {
        return [];
    }

    try {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
    } catch (Throwable $e) {
        return [];
    }

    if ($end < $start) {
        return [];
    }

    $startHour = max(0, min(23, $startHour));
    $endHour = max($startHour + 1, min(24, $endHour));

    $slots = [];
    $current = clone $start;
    $current->setTime(0, 0, 0);
    $end->setTime(23, 59, 59);

    while ($current <= $end) {
        for ($hour = $startHour; $hour < $endHour; $hour++) {
            $slot = clone $current;
            $slot->setTime($hour, 0, 0);
            $slots[] = $slot->format('Y-m-d H:i:s');
        }
        $current->modify('+1 day');
    }

    return $slots;
}

/**
 * Build hourly schedule slots from a season record (uses season start/end dates).
 *
 * @return list<string> Datetime strings (Y-m-d H:i:s)
 */
function buildSeasonScheduleSlots(?array $season, int $startHour = 7, int $endHour = 17): array
{
    if (!$season) {
        return [];
    }

    return buildScheduleSlots(
        $season['start_date'] ?? null,
        $season['end_date'] ?? null,
        $startHour,
        $endHour
    );
}

/**
 * Find the first unused schedule slot after existing season matches.
 */
function getNextScheduleSlotIndex(int $seasonId, array $slots): int
{
    if (empty($slots)) {
        return 0;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT MAX(scheduled_at) FROM intramural_matches WHERE season_id = ? AND scheduled_at IS NOT NULL');
    $stmt->execute([$seasonId]);
    $last = $stmt->fetchColumn();
    if (!$last) {
        return 0;
    }

    $lastTs = strtotime((string) $last);
    foreach ($slots as $i => $slot) {
        if (strtotime($slot) > $lastTs) {
            return $i;
        }
    }

    return count($slots);
}

/**
 * Assign scheduled_at to fixtures using the next available slots.
 *
 * @param list<array<string, mixed>> $fixtures
 * @return int Number of fixtures scheduled
 */
function applyAutoScheduleToFixtures(array &$fixtures, array $slots, int &$slotIndex): int
{
    $scheduled = 0;
    foreach ($fixtures as &$f) {
        if (!isset($slots[$slotIndex])) {
            break;
        }
        $f['scheduled_at'] = $slots[$slotIndex];
        $slotIndex++;
        $scheduled++;
    }
    unset($f);

    return $scheduled;
}

/**
 * Map house teams to Team A, B, C, D labels for the generate-matches UI.
 * Prefers Blue/Red/Green/Gold order when short names match; otherwise uses id order.
 *
 * @param list<array> $teams
 * @return array<int, array{letter: string, label: string}>
 */
function getHouseTeamLabels(array $teams): array
{
    $letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
    $preferred = ['blue' => 0, 'red' => 1, 'green' => 2, 'gold' => 3];

    $sorted = array_values($teams);
    usort($sorted, function ($a, $b) use ($preferred) {
        $ka = strtolower(trim((string) ($a['short_name'] ?? $a['name'] ?? '')));
        $kb = strtolower(trim((string) ($b['short_name'] ?? $b['name'] ?? '')));
        $pa = $preferred[$ka] ?? 100 + (int) ($a['id'] ?? 0);
        $pb = $preferred[$kb] ?? 100 + (int) ($b['id'] ?? 0);
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }
        return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
    });

    $labels = [];
    foreach ($sorted as $i => $t) {
        $letter = $letters[$i] ?? (string) ($i + 1);
        $labels[(int) $t['id']] = [
            'letter' => $letter,
            'label' => 'Team ' . $letter . ' — ' . ($t['name'] ?? ''),
        ];
    }

    return $labels;
}

/**
 * Persist generated fixtures for a sport/season. Returns number of matches inserted.
 *
 * @param list<int> $teamIds
 * @return array{created: int, format: string, error?: string}
 */
function generateMatchesForSport(int $sportId, int $seasonId, array $teamIds, ?int $createdBy = null, bool $replaceUnscheduled = false, ?array $scheduleSlots = null, ?int &$scheduleSlotIndex = null): array
{
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM intramural_sports WHERE id = ? AND is_active = 1');
    $stmt->execute([$sportId]);
    $sport = $stmt->fetch();
    if (!$sport) {
        return ['created' => 0, 'format' => '', 'error' => 'Sport not found.'];
    }

    $format = $sport['tournament_format'] ?? 'round_robin';
    if ($format === 'rank_first_to_last' && count($teamIds) % 2 !== 0) {
        return ['created' => 0, 'format' => $format, 'error' => 'Rank from First to Last requires an even number of teams (each team plays one match).'];
    }
    $fixtures = buildTournamentFixtures($format, $teamIds);
    if (empty($fixtures)) {
        return ['created' => 0, 'format' => $format, 'error' => 'Select at least 2 teams to generate matches.'];
    }

    if ($scheduleSlots !== null && $scheduleSlotIndex !== null) {
        applyAutoScheduleToFixtures($fixtures, $scheduleSlots, $scheduleSlotIndex);
    }

    if ($replaceUnscheduled) {
        // Remove only generated matches that have no score yet and are not ongoing/completed
        $db->prepare("DELETE FROM intramural_matches
            WHERE season_id = ? AND sport_id = ? AND is_generated = 1
              AND status IN ('scheduled', 'cancelled')
              AND score_a IS NULL AND score_b IS NULL")
            ->execute([$seasonId, $sportId]);
    }

    $insert = $db->prepare('INSERT INTO intramural_matches
        (season_id, sport_id, round_number, round_label, match_order, is_generated, team_a_id, team_b_id, scheduled_at, status, notes, created_by)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, \'scheduled\', ?, ?)');

    $created = 0;
    foreach ($fixtures as $f) {
        $insert->execute([
            $seasonId,
            $sportId,
            (int) $f['round_number'],
            $f['round_label'],
            (int) $f['match_order'],
            $f['team_a_id'],
            $f['team_b_id'],
            $f['scheduled_at'] ?? null,
            $f['notes'],
            $createdBy,
        ]);
        $created++;
    }

    return ['created' => $created, 'format' => $format];
}

/**
 * Generate fixtures for multiple sports in one pass.
 *
 * @param list<int> $sportIds
 * @param list<int>|null $sharedTeamIds  When set, used for every sport. When null, teams come from season registrations per sport.
 * @param array<int, list<int>>|null $teamsBySport  Per-sport team IDs (sport_id => team ids)
 * @param array{start_date?:?string,end_date?:?string,start_hour?:int,end_hour?:int}|null $scheduleOptions Custom auto-schedule window (optional)
 * @return array{created: int, sports: int, details: list<array>, errors: list<string>, scheduled: int}
 */
function generateMatchesForSports(array $sportIds, int $seasonId, ?array $sharedTeamIds, ?int $createdBy = null, bool $replaceUnscheduled = false, ?array $teamsBySport = null, ?array $scheduleOptions = null): array
{
    $db = getDB();
    $sportIds = array_values(array_unique(array_filter(array_map('intval', $sportIds))));
    $details = [];
    $errors = [];
    $total = 0;
    $okSports = 0;
    $totalScheduled = 0;

    $season = getSeasonById($seasonId);
    $startHour = (int) ($scheduleOptions['start_hour'] ?? 7);
    $endHour = (int) ($scheduleOptions['end_hour'] ?? 17);
    $startDate = $scheduleOptions['start_date'] ?? ($season['start_date'] ?? null);
    $endDate = $scheduleOptions['end_date'] ?? ($season['end_date'] ?? null);
    $scheduleSlots = buildScheduleSlots($startDate, $endDate, $startHour, $endHour);
    $scheduleSlotIndex = getNextScheduleSlotIndex($seasonId, $scheduleSlots);

    $regTeamsStmt = $db->prepare('SELECT DISTINCT team_id FROM intramural_registrations WHERE sport_id = ? AND season_id = ?');

    foreach ($sportIds as $sportId) {
        if ($teamsBySport !== null && isset($teamsBySport[$sportId])) {
            $teamIds = array_values(array_unique(array_map('intval', $teamsBySport[$sportId])));
        } elseif ($sharedTeamIds !== null) {
            $teamIds = $sharedTeamIds;
        } else {
            $regTeamsStmt->execute([$sportId, $seasonId]);
            $teamIds = array_map('intval', $regTeamsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }

        $beforeIndex = $scheduleSlotIndex;
        $result = generateMatchesForSport($sportId, $seasonId, $teamIds, $createdBy, $replaceUnscheduled, $scheduleSlots, $scheduleSlotIndex);
        $sportScheduled = $scheduleSlotIndex - $beforeIndex;
        $totalScheduled += $sportScheduled;
        $sportStmt = $db->prepare('SELECT * FROM intramural_sports WHERE id = ?');
        $sportStmt->execute([$sportId]);
        $sport = $sportStmt->fetch() ?: ['name' => 'Sport #' . $sportId, 'category' => 'mixed'];

        if (!empty($result['error'])) {
            $errors[] = sportLabel($sport) . ': ' . $result['error'];
            $details[] = [
                'sport_id' => $sportId,
                'sport' => $sport,
                'created' => 0,
                'format' => $result['format'] ?? '',
                'error' => $result['error'],
            ];
            continue;
        }

        $okSports++;
        $total += (int) $result['created'];
        $details[] = [
            'sport_id' => $sportId,
            'sport' => $sport,
            'created' => (int) $result['created'],
            'format' => $result['format'],
            'error' => null,
        ];
    }

    return [
        'created' => $total,
        'sports' => $okSports,
        'details' => $details,
        'errors' => $errors,
        'scheduled' => $totalScheduled,
    ];
}

function uploadAthletePhoto(array $file): ?string
{
    return uploadToPath($file, UPLOAD_PATH_ATHLETES, 'ath');
}

function uploadTeamLogo(array $file): ?string
{
    return uploadToPath($file, UPLOAD_PATH_TEAMS, 'team');
}

function uploadToPath(array $file, string $path, string $prefix): ?string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowed, true)) {
        return null;
    }

    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return null;
    }

    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = $prefix . '_' . time() . '_' . uniqid() . '.' . $ext;

    if (move_uploaded_file($file['tmp_name'], $path . $filename)) {
        return $filename;
    }

    return null;
}

function deleteUploadedFile(?string $filename, string $path): void
{
    if ($filename && file_exists($path . $filename)) {
        unlink($path . $filename);
    }
}

function sportLabel(array $sport): string
{
    return $sport['name'] . ' (' . ucfirst($sport['category']) . ')';
}

function seasonLabel(array $season): string
{
    return trim(($season['name'] ?? '') . ' (' . ($season['year_label'] ?? '') . ')');
}

function getAllSeasons(bool $includeArchived = true): array
{
    try {
        $db = getDB();
        $sql = 'SELECT * FROM intramural_seasons';
        if (!$includeArchived) {
            $sql .= ' WHERE is_archived = 0';
        }
        $sql .= ' ORDER BY is_active DESC, year_label DESC, id DESC';
        return $db->query($sql)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function getActiveSeason(): ?array
{
    try {
        $db = getDB();
        $season = $db->query('SELECT * FROM intramural_seasons WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetch();
        if ($season) {
            return $season;
        }
        return $db->query('SELECT * FROM intramural_seasons ORDER BY id DESC LIMIT 1')->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function getSeasonById(int $id): ?array
{
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM intramural_seasons WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Season currently being viewed in the UI (defaults to active season).
 */
function getCurrentSeasonId(): ?int
{
    handleSeasonSelection();

    if (!empty($_SESSION['intramural_season_id'])) {
        return (int) $_SESSION['intramural_season_id'];
    }

    $active = getActiveSeason();
    return $active ? (int) $active['id'] : null;
}

function getCurrentSeason(): ?array
{
    $id = getCurrentSeasonId();
    return $id ? getSeasonById($id) : getActiveSeason();
}

function handleSeasonSelection(): void
{
    if (!isset($_GET['season_id']) && !isset($_POST['view_season_id'])) {
        return;
    }

    $raw = $_GET['season_id'] ?? $_POST['view_season_id'] ?? '';
    $id = (int) $raw;
    if ($id > 0 && getSeasonById($id)) {
        $_SESSION['intramural_season_id'] = $id;
    }
}

function setActiveSeason(int $seasonId): bool
{
    $db = getDB();
    $season = getSeasonById($seasonId);
    if (!$season) {
        return false;
    }

    $db->exec('UPDATE intramural_seasons SET is_active = 0');
    $db->prepare('UPDATE intramural_seasons SET is_active = 1, is_archived = 0 WHERE id = ?')->execute([$seasonId]);
    $_SESSION['intramural_season_id'] = $seasonId;
    return true;
}

function isViewingActiveSeason(): bool
{
    $current = getCurrentSeason();
    return $current && !empty($current['is_active']);
}

function requireWritableSeason(): void
{
    if (!isViewingActiveSeason()) {
        flash('error', 'Switch to the active intramurals season before making changes. Historical seasons are view-only.');
        redirect(BASE_URL . '/intramurals/index.php');
    }
}

function determineMatchWinner(?int $scoreA, ?int $scoreB, int $teamAId, int $teamBId, string $status, ?int $forfeitTeamId = null): ?int
{
    if ($status === 'forfeit' && $forfeitTeamId) {
        return $forfeitTeamId === $teamAId ? $teamBId : $teamAId;
    }

    if ($status !== 'completed' && $status !== 'forfeit') {
        return null;
    }

    if ($scoreA === null || $scoreB === null) {
        return null;
    }

    if ($scoreA > $scoreB) {
        return $teamAId;
    }
    if ($scoreB > $scoreA) {
        return $teamBId;
    }

    return null; // draw
}

function getIntramuralsStats(?int $seasonId = null): array
{
    $db = getDB();
    $seasonId = $seasonId ?? getCurrentSeasonId();
    $stats = [
        'total_athletes' => 0,
        'total_teams' => 0,
        'total_sports' => 0,
        'scheduled_games' => 0,
        'completed_games' => 0,
        'ongoing_games' => 0,
        'registered_athletes' => 0,
    ];

    try {
        $stats['total_athletes'] = (int) $db->query('SELECT COUNT(*) FROM intramural_athletes WHERE is_active = 1')->fetchColumn();
        $stats['total_teams'] = (int) $db->query('SELECT COUNT(*) FROM intramural_teams WHERE is_active = 1')->fetchColumn();
        $stats['total_sports'] = (int) $db->query('SELECT COUNT(*) FROM intramural_sports WHERE is_active = 1')->fetchColumn();

        if ($seasonId) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM intramural_matches WHERE status = 'scheduled' AND season_id = ?");
            $stmt->execute([$seasonId]);
            $stats['scheduled_games'] = (int) $stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM intramural_matches WHERE status IN ('completed', 'forfeit') AND season_id = ?");
            $stmt->execute([$seasonId]);
            $stats['completed_games'] = (int) $stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM intramural_matches WHERE status = 'ongoing' AND season_id = ?");
            $stmt->execute([$seasonId]);
            $stats['ongoing_games'] = (int) $stmt->fetchColumn();

            $stmt = $db->prepare('SELECT COUNT(DISTINCT athlete_id) FROM intramural_registrations WHERE season_id = ?');
            $stmt->execute([$seasonId]);
            $stats['registered_athletes'] = (int) $stmt->fetchColumn();
        }
    } catch (Throwable $e) {
        // Tables may not exist yet on older installs
    }

    return $stats;
}

function placementLabels(): array
{
    return [
        1 => 'Champion',
        2 => '1st Runner Up',
        3 => '2nd Runner Up',
        4 => '3rd Runner Up',
        5 => '4th Runner Up',
        6 => '5th Runner Up',
    ];
}

function defaultPointScheme(): array
{
    return [
        'id' => null,
        'name' => 'Default',
        'points_1' => 10,
        'points_2' => 7,
        'points_3' => 5,
        'points_4' => 3,
        'points_5' => 2,
        'points_6' => 1,
    ];
}

function getPointSchemeById(?int $schemeId): array
{
    if (!$schemeId) {
        return defaultPointScheme();
    }

    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM intramural_point_schemes WHERE id = ? AND is_active = 1');
        $stmt->execute([$schemeId]);
        $scheme = $stmt->fetch();
        return $scheme ?: defaultPointScheme();
    } catch (Throwable $e) {
        return defaultPointScheme();
    }
}

function getPointSchemeForSport(array $sport): array
{
    $schemeId = !empty($sport['point_scheme_id']) ? (int) $sport['point_scheme_id'] : null;
    return getPointSchemeById($schemeId);
}

function getAllPointSchemes(bool $activeOnly = true): array
{
    try {
        $db = getDB();
        $sql = 'SELECT * FROM intramural_point_schemes';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name';
        return $db->query($sql)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function getPlacementPoints(array $scheme, int $rank): int
{
    if ($rank < 1 || $rank > 6) {
        return 0;
    }
    return (int) ($scheme['points_' . $rank] ?? 0);
}

function getPlacementLabel(int $rank): string
{
    return placementLabels()[$rank] ?? ('Rank ' . $rank);
}

function formatSchemePoints(array $scheme): string
{
    return implode('/', [
        (int) $scheme['points_1'],
        (int) $scheme['points_2'],
        (int) $scheme['points_3'],
        (int) $scheme['points_4'],
        (int) $scheme['points_5'],
        (int) $scheme['points_6'],
    ]);
}

/**
 * Compute standings for a sport (or all sports if sportId is null).
 * Match W/D/L points determine event ranking; placement points come from the sport's point scheme.
 * Tie-breakers: match points → wins → point differential → points for.
 */
function computeSportStandings(?int $sportId = null, ?int $seasonId = null): array
{
    $db = getDB();
    $seasonId = $seasonId ?? getCurrentSeasonId();

    try {
        if ($sportId) {
            $sports = $db->prepare('SELECT s.*, ps.name as scheme_name, ps.points_1, ps.points_2, ps.points_3, ps.points_4, ps.points_5, ps.points_6
                FROM intramural_sports s
                LEFT JOIN intramural_point_schemes ps ON s.point_scheme_id = ps.id
                WHERE s.id = ? AND s.is_active = 1');
            $sports->execute([$sportId]);
            $sports = $sports->fetchAll();
        } else {
            $sports = $db->query('SELECT s.*, ps.name as scheme_name, ps.points_1, ps.points_2, ps.points_3, ps.points_4, ps.points_5, ps.points_6
                FROM intramural_sports s
                LEFT JOIN intramural_point_schemes ps ON s.point_scheme_id = ps.id
                WHERE s.is_active = 1 ORDER BY s.name, s.category')->fetchAll();
        }
    } catch (Throwable $e) {
        if ($sportId) {
            $sports = $db->prepare('SELECT * FROM intramural_sports WHERE id = ? AND is_active = 1');
            $sports->execute([$sportId]);
            $sports = $sports->fetchAll();
        } else {
            $sports = $db->query('SELECT * FROM intramural_sports WHERE is_active = 1 ORDER BY name, category')->fetchAll();
        }
    }

    $result = [];

    foreach ($sports as $sport) {
        $sid = (int) $sport['id'];
        $scheme = getPointSchemeForSport($sport);
        $teams = $db->query('SELECT * FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
        $standings = [];

        foreach ($teams as $team) {
            $standings[(int) $team['id']] = [
                'team_id' => (int) $team['id'],
                'team_name' => $team['name'],
                'short_name' => $team['short_name'] ?: $team['name'],
                'color' => $team['color'],
                'played' => 0,
                'wins' => 0,
                'losses' => 0,
                'draws' => 0,
                'points' => 0, // match table points (W/D/L)
                'placement_points' => 0,
                'placement_label' => null,
                'score_for' => 0,
                'score_against' => 0,
                'diff' => 0,
            ];
        }

        if ($seasonId) {
            $stmt = $db->prepare("SELECT * FROM intramural_matches WHERE sport_id = ? AND season_id = ? AND status IN ('completed', 'forfeit')");
            $stmt->execute([$sid, $seasonId]);
        } else {
            $stmt = $db->prepare("SELECT * FROM intramural_matches WHERE sport_id = ? AND status IN ('completed', 'forfeit')");
            $stmt->execute([$sid]);
        }
        $matches = $stmt->fetchAll();

        foreach ($matches as $m) {
            $a = (int) $m['team_a_id'];
            $b = (int) $m['team_b_id'];
            if (!isset($standings[$a]) || !isset($standings[$b])) {
                continue;
            }

            $scoreA = (int) ($m['score_a'] ?? 0);
            $scoreB = (int) ($m['score_b'] ?? 0);

            $standings[$a]['played']++;
            $standings[$b]['played']++;
            $standings[$a]['score_for'] += $scoreA;
            $standings[$a]['score_against'] += $scoreB;
            $standings[$b]['score_for'] += $scoreB;
            $standings[$b]['score_against'] += $scoreA;

            $winner = $m['winner_team_id'] ? (int) $m['winner_team_id'] : determineMatchWinner(
                $m['score_a'] !== null ? (int) $m['score_a'] : null,
                $m['score_b'] !== null ? (int) $m['score_b'] : null,
                $a,
                $b,
                $m['status'],
                $m['forfeit_team_id'] ? (int) $m['forfeit_team_id'] : null
            );

            if ($winner === $a) {
                $standings[$a]['wins']++;
                $standings[$b]['losses']++;
                $standings[$a]['points'] += (int) $sport['win_points'];
                $standings[$b]['points'] += (int) $sport['loss_points'];
            } elseif ($winner === $b) {
                $standings[$b]['wins']++;
                $standings[$a]['losses']++;
                $standings[$b]['points'] += (int) $sport['win_points'];
                $standings[$a]['points'] += (int) $sport['loss_points'];
            } else {
                $standings[$a]['draws']++;
                $standings[$b]['draws']++;
                $standings[$a]['points'] += (int) $sport['draw_points'];
                $standings[$b]['points'] += (int) $sport['draw_points'];
            }
        }

        foreach ($standings as &$row) {
            $row['diff'] = $row['score_for'] - $row['score_against'];
        }
        unset($row);

        $rows = array_values($standings);
        usort($rows, function ($x, $y) {
            if ($x['points'] !== $y['points']) {
                return $y['points'] <=> $x['points'];
            }
            if ($x['wins'] !== $y['wins']) {
                return $y['wins'] <=> $x['wins'];
            }
            if ($x['diff'] !== $y['diff']) {
                return $y['diff'] <=> $x['diff'];
            }
            return $y['score_for'] <=> $x['score_for'];
        });

        $rank = 1;
        foreach ($rows as &$row) {
            $row['rank'] = $rank++;
            $row['medal'] = null;
            $row['placement_points'] = 0;
            $row['placement_label'] = null;
            if ($row['played'] > 0) {
                $row['placement_points'] = getPlacementPoints($scheme, $row['rank']);
                $row['placement_label'] = $row['rank'] <= 6 ? getPlacementLabel($row['rank']) : null;
                if ($row['rank'] === 1) {
                    $row['medal'] = 'gold';
                } elseif ($row['rank'] === 2) {
                    $row['medal'] = 'silver';
                } elseif ($row['rank'] === 3) {
                    $row['medal'] = 'bronze';
                }
            }
        }
        unset($row);

        $result[$sid] = [
            'sport' => $sport,
            'scheme' => $scheme,
            'standings' => $rows,
        ];
    }

    return $result;
}

/**
 * Overall intramurals standing uses placement points from each event's point scheme.
 */
function computeOverallStandings(): array
{
    $all = computeSportStandings(null);
    $db = getDB();
    $teams = $db->query('SELECT * FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
    $overall = [];

    foreach ($teams as $team) {
        $overall[(int) $team['id']] = [
            'team_id' => (int) $team['id'],
            'team_name' => $team['name'],
            'short_name' => $team['short_name'] ?: $team['name'],
            'color' => $team['color'],
            'sports' => [],
            'total' => 0,
            'gold' => 0,
            'silver' => 0,
            'bronze' => 0,
        ];
    }

    foreach ($all as $sid => $block) {
        $sportKey = sportLabel($block['sport']);
        foreach ($block['standings'] as $row) {
            $tid = $row['team_id'];
            if (!isset($overall[$tid]) || $row['played'] === 0) {
                continue;
            }
            $pts = (int) $row['placement_points'];
            $overall[$tid]['sports'][$sportKey] = $pts;
            $overall[$tid]['total'] += $pts;
            if ($row['medal'] === 'gold') {
                $overall[$tid]['gold']++;
            } elseif ($row['medal'] === 'silver') {
                $overall[$tid]['silver']++;
            } elseif ($row['medal'] === 'bronze') {
                $overall[$tid]['bronze']++;
            }
        }
    }

    $rows = array_values($overall);
    usort($rows, function ($a, $b) {
        if ($a['total'] !== $b['total']) {
            return $b['total'] <=> $a['total'];
        }
        if ($a['gold'] !== $b['gold']) {
            return $b['gold'] <=> $a['gold'];
        }
        if ($a['silver'] !== $b['silver']) {
            return $b['silver'] <=> $a['silver'];
        }
        return $b['bronze'] <=> $a['bronze'];
    });

    $rank = 1;
    foreach ($rows as &$row) {
        $row['rank'] = $rank++;
    }
    unset($row);

    return [
        'sport_labels' => array_map(fn($b) => sportLabel($b['sport']), $all),
        'standings' => $rows,
    ];
}

function exportCsv(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

function athleteFullName(array $athlete): string
{
    return trim(($athlete['first_name'] ?? '') . ' ' . ($athlete['last_name'] ?? ''));
}

/**
 * Expected headers for athlete roster Excel/CSV import.
 */
function rosterImportHeaders(): array
{
    return [
        'student_id',
        'first_name',
        'last_name',
        'gender',
        'birthdate',
        'department',
        'year_level',
        'team',
        'email',
        'phone',
        'sport',
        'sport_category',
        'jersey_number',
        'position',
        'event_category',
    ];
}

function rosterImportSampleRows(): array
{
    return [
        ['2024-0001', 'Juan', 'Dela Cruz', 'male', '2004-05-12', 'College of Education', '2nd Year', 'Blue Eagles', 'juan@example.com', '09171234567', 'Basketball 5x5', 'mixed', '7', 'Guard', ''],
        ['2024-0002', 'Maria', 'Santos', 'female', '2005-01-20', 'College of Arts and Sciences', '1st Year', 'Red Lions', '', '', 'Volleyball', 'mixed', '10', 'Setter', ''],
    ];
}

/**
 * Headers for bulk athlete import (sport assignment optional).
 */
function athleteImportHeaders(): array
{
    return [
        'student_id',
        'first_name',
        'last_name',
        'gender',
        'birthdate',
        'department',
        'year_level',
        'team',
        'email',
        'phone',
        'sport',
        'sport_category',
        'jersey_number',
        'position',
        'event_category',
    ];
}

/**
 * @return list<list<string>>
 */
function athleteImportSampleRows(?string $teamName = null): array
{
    $team = $teamName ?: 'Blue Eagles';
    return [
        ['2024-0001', 'Juan', 'Dela Cruz', 'male', '2004-05-12', 'College of Education', '2nd Year', $team, 'juan@example.com', '09171234567', 'Basketball 5x5', 'mixed', '7', 'Guard', ''],
        ['2024-0002', 'Maria', 'Santos', 'female', '2005-01-20', 'College of Arts and Sciences', '1st Year', $team, '', '', 'Volleyball', 'mixed', '10', 'Setter', ''],
        ['2024-0003', 'Pedro', 'Reyes', 'male', '2004-08-03', 'College of Business', '3rd Year', $team, '', '', '', '', '', '', ''],
    ];
}

/**
 * Map raw matrix into athlete import rows (team/sport optional).
 *
 * @param list<list<string>> $matrix
 * @return array{headers: string[], rows: array<int, array<string, string>>, error?: string}
 */
function mapAthleteImportMatrix(array $matrix): array
{
    if (count($matrix) < 2) {
        return ['headers' => [], 'rows' => [], 'error' => 'File must include a header row and at least one data row.'];
    }

    $headers = array_map(fn($h) => normalizeImportHeader((string) $h), $matrix[0]);
    foreach (['student_id', 'first_name', 'last_name'] as $key) {
        if (!in_array($key, $headers, true)) {
            return [
                'headers' => $headers,
                'rows' => [],
                'error' => 'Missing required column: ' . $key . '. Download the template and keep the header row.',
            ];
        }
    }

    $rows = [];
    for ($i = 1; $i < count($matrix); $i++) {
        $cells = $matrix[$i];
        $row = [];
        foreach ($headers as $idx => $key) {
            if ($key === '') {
                continue;
            }
            $row[$key] = trim((string) ($cells[$idx] ?? ''));
        }
        if (($row['student_id'] ?? '') === '' && ($row['first_name'] ?? '') === '' && ($row['last_name'] ?? '') === '') {
            continue;
        }
        $rows[] = $row;
    }

    return ['headers' => $headers, 'rows' => $rows];
}

/**
 * Parse uploaded athlete import file (.xlsx or CSV).
 *
 * @return array{headers: string[], rows: array<int, array<string, string>>, error?: string}
 */
function parseAthleteImportFile(string $tmpPath, string $originalName = ''): array
{
    $ext = strtolower(pathinfo($originalName !== '' ? $originalName : $tmpPath, PATHINFO_EXTENSION));

    if ($ext === 'xlsx' || $ext === 'xlsm') {
        if (!class_exists('ZipArchive')) {
            return ['headers' => [], 'rows' => [], 'error' => 'Excel upload requires ZipArchive support on the server.'];
        }
        $matrix = readXlsxRows($tmpPath);
        if (empty($matrix)) {
            return ['headers' => [], 'rows' => [], 'error' => 'Could not read the Excel file. Use the downloadable template (Athletes sheet).'];
        }
        return mapAthleteImportMatrix($matrix);
    }

    $raw = file_get_contents($tmpPath);
    if ($raw === false || $raw === '') {
        return ['headers' => [], 'rows' => [], 'error' => 'Could not read the uploaded file.'];
    }

    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }

    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = array_values(array_filter(explode("\n", $raw), fn($l) => trim($l) !== ''));
    if (count($lines) < 2) {
        return ['headers' => [], 'rows' => [], 'error' => 'File must include a header row and at least one data row.'];
    }

    $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
    $matrix = [];
    foreach ($lines as $line) {
        $matrix[] = str_getcsv($line, $delimiter);
    }

    return mapAthleteImportMatrix($matrix);
}

/**
 * Download Excel/CSV template for bulk athlete import.
 */
function downloadAthleteImportTemplate(string $format = 'xlsx', ?int $scopedTeamId = null): void
{
    $headers = athleteImportHeaders();
    $teamName = null;
    $teamRows = [];

    $db = getDB();
    try {
        if ($scopedTeamId) {
            $stmt = $db->prepare('SELECT name, short_name FROM intramural_teams WHERE id = ? AND is_active = 1');
            $stmt->execute([$scopedTeamId]);
            $t = $stmt->fetch();
            if ($t) {
                $teamName = $t['name'];
                $teamRows[] = [$t['name'], $t['short_name'] ?: ''];
            }
        } else {
            foreach ($db->query('SELECT name, short_name FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll() as $t) {
                $teamRows[] = [$t['name'], $t['short_name'] ?: ''];
            }
        }
    } catch (Throwable $e) {
        $teamRows = [];
    }

    $sampleRows = athleteImportSampleRows($teamName);
    $filenameBase = $scopedTeamId ? 'athlete-import-template' : 'athlete-import-template';

    if ($format === 'csv') {
        exportCsv($filenameBase . '.csv', $headers, $sampleRows);
    }

    $sportRows = [];
    try {
        foreach ($db->query('SELECT name, category FROM intramural_sports WHERE is_active = 1 ORDER BY name, category')->fetchAll() as $s) {
            $sportRows[] = [$s['name'], $s['category']];
        }
    } catch (Throwable $e) {
        $sportRows = [];
    }

    $season = function_exists('getCurrentSeason') ? getCurrentSeason() : null;
    $seasonLabel = $season ? seasonLabel($season) : 'Active season';
    $teamNote = $scopedTeamId && $teamName
        ? 'Your assigned team (' . $teamName . ') is used when the team column is left blank.'
        : 'Use exact team names from the Teams sheet.';

    $instructionRows = [
        ['Athlete Import Template'],
        ['Season', $seasonLabel],
        [''],
        ['How to use'],
        ['1', 'Fill the Athletes sheet only. Do not rename or reorder header columns.'],
        ['2', 'Replace sample rows with real athlete data.'],
        ['3', $teamNote],
        ['4', 'Sport columns are optional — leave blank to register the athlete only.'],
        ['5', 'Save the file, then upload this .xlsx on the Import Athletes page.'],
        [''],
        ['Required columns', 'student_id, first_name, last_name'],
        ['Optional columns', 'gender, birthdate, department, year_level, team, email, phone, sport, sport_category, jersey_number, position, event_category'],
        ['gender values', 'male | female | other'],
        ['birthdate format', 'YYYY-MM-DD (example: 2004-05-12)'],
        [''],
        ['Notes'],
        ['-', 'Existing Student IDs are updated with the new profile data.'],
        ['-', 'When sport is provided, the athlete is also registered for that sport in the active season.'],
    ];

    exportSimpleXlsx($filenameBase . '.xlsx', [
        'Athletes' => [
            'headers' => $headers,
            'rows' => $sampleRows,
        ],
        'Instructions' => [
            'headers' => ['Item', 'Details'],
            'rows' => $instructionRows,
        ],
        'Teams' => [
            'headers' => ['team_name', 'short_name'],
            'rows' => $teamRows,
        ],
        'Sports' => [
            'headers' => ['sport_name', 'sport_category'],
            'rows' => $sportRows,
        ],
    ]);
}

/**
 * Normalize a CSV/Excel header cell to a snake_case key.
 */
function normalizeImportHeader(string $header): string
{
    $header = strtolower(trim($header));
    $header = preg_replace('/[\s\-]+/', '_', $header) ?? $header;
    $header = preg_replace('/[^a-z0-9_]/', '', $header) ?? $header;

    $aliases = [
        'studentid' => 'student_id',
        'student_no' => 'student_id',
        'student_number' => 'student_id',
        'firstname' => 'first_name',
        'lastname' => 'last_name',
        'birth_date' => 'birthdate',
        'dob' => 'birthdate',
        'year' => 'year_level',
        'team_name' => 'team',
        'house' => 'team',
        'sport_name' => 'sport',
        'event' => 'sport',
        'category' => 'sport_category',
        'jersey' => 'jersey_number',
        'jersey_no' => 'jersey_number',
        'jersey_num' => 'jersey_number',
        'event_cat' => 'event_category',
        'eventcategory' => 'event_category',
    ];

    return $aliases[$header] ?? $header;
}

/**
 * Map raw header/data cell matrix into associative import rows.
 *
 * @param list<list<string>> $matrix
 * @return array{headers: string[], rows: array<int, array<string, string>>, error?: string}
 */
function mapRosterImportMatrix(array $matrix): array
{
    if (count($matrix) < 2) {
        return ['headers' => [], 'rows' => [], 'error' => 'File must include a header row and at least one data row.'];
    }

    $headers = array_map(fn($h) => normalizeImportHeader((string) $h), $matrix[0]);
    $required = ['student_id', 'first_name', 'last_name', 'team', 'sport'];
    foreach ($required as $key) {
        if (!in_array($key, $headers, true)) {
            return [
                'headers' => $headers,
                'rows' => [],
                'error' => 'Missing required column: ' . $key . '. Download the template and keep the Roster header row.',
            ];
        }
    }

    $rows = [];
    for ($i = 1; $i < count($matrix); $i++) {
        $cells = $matrix[$i];
        $row = [];
        foreach ($headers as $idx => $key) {
            if ($key === '') {
                continue;
            }
            $row[$key] = trim((string) ($cells[$idx] ?? ''));
        }
        if (($row['student_id'] ?? '') === '' && ($row['first_name'] ?? '') === '' && ($row['last_name'] ?? '') === '') {
            continue;
        }
        $rows[] = $row;
    }

    return ['headers' => $headers, 'rows' => $rows];
}

/**
 * Parse an uploaded CSV or XLSX roster file into associative rows.
 *
 * @return array{headers: string[], rows: array<int, array<string, string>>, error?: string}
 */
function parseRosterImportFile(string $tmpPath, string $originalName = ''): array
{
    $ext = strtolower(pathinfo($originalName !== '' ? $originalName : $tmpPath, PATHINFO_EXTENSION));

    if ($ext === 'xlsx' || $ext === 'xlsm') {
        if (!class_exists('ZipArchive')) {
            return ['headers' => [], 'rows' => [], 'error' => 'Excel upload requires ZipArchive support on the server.'];
        }
        $matrix = readXlsxRows($tmpPath);
        if (empty($matrix)) {
            return ['headers' => [], 'rows' => [], 'error' => 'Could not read the Excel file. Use the downloadable template (Roster sheet).'];
        }
        return mapRosterImportMatrix($matrix);
    }

    $raw = file_get_contents($tmpPath);
    if ($raw === false || $raw === '') {
        return ['headers' => [], 'rows' => [], 'error' => 'Could not read the uploaded file.'];
    }

    // Strip UTF-8 BOM
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }

    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = array_values(array_filter(explode("\n", $raw), fn($l) => trim($l) !== ''));
    if (count($lines) < 2) {
        return ['headers' => [], 'rows' => [], 'error' => 'File must include a header row and at least one data row.'];
    }

    $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
    $matrix = [];
    foreach ($lines as $line) {
        $matrix[] = str_getcsv($line, $delimiter);
    }

    return mapRosterImportMatrix($matrix);
}

/**
 * Escape value for Office Open XML spreadsheet cell.
 */
function xlsxXmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Convert 0-based column index to Excel column letters (A, B, ... AA).
 */
function xlsxColumnLetter(int $index): string
{
    $letter = '';
    $n = $index + 1;
    while ($n > 0) {
        $n--;
        $letter = chr(65 + ($n % 26)) . $letter;
        $n = intdiv($n, 26);
    }
    return $letter;
}

/**
 * Build worksheet XML from a header row + data rows (inline strings).
 *
 * @param list<string> $headers
 * @param list<list<string|int|float|null>> $rows
 */
function buildXlsxSheetXml(array $headers, array $rows): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>';

    $allRows = array_merge([$headers], $rows);
    foreach ($allRows as $rIndex => $row) {
        $rowNum = $rIndex + 1;
        $xml .= '<row r="' . $rowNum . '">';
        foreach (array_values($row) as $cIndex => $value) {
            $ref = xlsxColumnLetter($cIndex) . $rowNum;
            $text = (string) ($value ?? '');
            // Keep numbers as shared-looking inline strings for consistent import round-trip
            $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . xlsxXmlEscape($text) . '</t></is></c>';
        }
        $xml .= '</row>';
    }

    $xml .= '</sheetData></worksheet>';
    return $xml;
}

/**
 * Stream a multi-sheet .xlsx download (no external libraries).
 *
 * @param array<string, array{headers: list<string>, rows: list<list<mixed>>}> $sheets
 */
function exportSimpleXlsx(string $filename, array $sheets): void
{
    if (!class_exists('ZipArchive')) {
        // Fallback: first sheet as CSV
        $first = reset($sheets) ?: ['headers' => [], 'rows' => []];
        exportCsv(preg_replace('/\.xlsx$/i', '.csv', $filename) ?: 'export.csv', $first['headers'] ?? [], $first['rows'] ?? []);
        return;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    if ($tmp === false) {
        flash('error', 'Could not create temporary file for Excel template.');
        redirect(BASE_URL . '/intramurals/roster/import.php');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        flash('error', 'Could not create Excel template.');
        redirect(BASE_URL . '/intramurals/roster/import.php');
    }

    $sheetNames = array_keys($sheets);
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';

    $workbookSheets = '';
    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

    $i = 1;
    foreach ($sheetNames as $name) {
        $sheet = $sheets[$name];
        $headers = $sheet['headers'] ?? [];
        $rows = $sheet['rows'] ?? [];
        $path = 'xl/worksheets/sheet' . $i . '.xml';
        $zip->addFromString($path, buildXlsxSheetXml($headers, $rows));
        $contentTypes .= '<Override PartName="/' . $path . '" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $workbookSheets .= '<sheet name="' . xlsxXmlEscape(mb_substr($name, 0, 31)) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $workbookRels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        $i++;
    }

    $contentTypes .= '</Types>';
    $workbookRels .= '</Relationships>';

    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $workbookSheets . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string) filesize($tmp));
    header('Cache-Control: max-age=0');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/**
 * Read first worksheet rows from a simple .xlsx file (inlineStr / shared strings / plain values).
 *
 * @return list<list<string>>
 */
function readXlsxRows(string $tmpPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
        return [];
    }

    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx) {
            foreach ($sx->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string) $si->t;
                } else {
                    $parts = [];
                    foreach ($si->r as $run) {
                        $parts[] = (string) $run->t;
                    }
                    $shared[] = implode('', $parts);
                }
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        return [];
    }

    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet || !isset($sheet->sheetData)) {
        return [];
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        $maxCol = -1;
        $byCol = [];
        foreach ($row->c as $c) {
            $ref = (string) ($c['r'] ?? '');
            $colLetters = preg_replace('/\d+/', '', $ref) ?: 'A';
            $col = 0;
            foreach (str_split(strtoupper($colLetters)) as $ch) {
                $col = $col * 26 + (ord($ch) - 64);
            }
            $col--; // 0-based
            $maxCol = max($maxCol, $col);

            $type = (string) ($c['t'] ?? '');
            if ($type === 'inlineStr') {
                $val = (string) ($c->is->t ?? '');
            } elseif ($type === 's') {
                $idx = (int) ($c->v ?? -1);
                $val = $shared[$idx] ?? '';
            } else {
                $val = (string) ($c->v ?? '');
            }
            $byCol[$col] = trim($val);
        }
        for ($i = 0; $i <= $maxCol; $i++) {
            $cells[] = $byCol[$i] ?? '';
        }
        if (implode('', $cells) !== '') {
            $rows[] = $cells;
        }
    }

    return $rows;
}

/**
 * Download Excel (.xlsx) import template with Roster + Instructions + reference sheets.
 */
function downloadRosterImportTemplate(string $format = 'xlsx'): void
{
    $headers = rosterImportHeaders();
    $sampleRows = rosterImportSampleRows();

    if ($format === 'csv') {
        exportCsv('athlete-roster-import-template.csv', $headers, $sampleRows);
    }

    $db = getDB();
    $teamRows = [];
    try {
        foreach ($db->query('SELECT name, short_name FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll() as $t) {
            $teamRows[] = [$t['name'], $t['short_name'] ?: ''];
        }
    } catch (Throwable $e) {
        $teamRows = [];
    }

    $sportRows = [];
    try {
        foreach ($db->query('SELECT name, category FROM intramural_sports WHERE is_active = 1 ORDER BY name, category')->fetchAll() as $s) {
            $sportRows[] = [$s['name'], $s['category']];
        }
    } catch (Throwable $e) {
        $sportRows = [];
    }

    $season = function_exists('getCurrentSeason') ? getCurrentSeason() : null;
    $seasonLabel = $season ? seasonLabel($season) : 'Active season';

    $instructionRows = [
        ['Athlete Roster Import Template'],
        ['Season', $seasonLabel],
        [''],
        ['How to use'],
        ['1', 'Fill the Roster sheet only. Do not rename or reorder header columns.'],
        ['2', 'Replace the sample rows with real athlete data.'],
        ['3', 'Use exact Team and Sport names from the Teams and Sports sheets.'],
        ['4', 'Save the file, then upload this .xlsx (or Save As CSV UTF-8) on the Import page.'],
        [''],
        ['Required columns', 'student_id, first_name, last_name, team, sport'],
        ['Optional columns', 'gender, birthdate, department, year_level, email, phone, sport_category, jersey_number, position, event_category'],
        ['gender values', 'male | female | other'],
        ['birthdate format', 'YYYY-MM-DD (example: 2004-05-12)'],
        ['sport_category', 'men | women | mixed (use when a sport name exists in more than one category)'],
        [''],
        ['Notes'],
        ['-', 'Existing Student IDs are updated and registered for the sport in the active season.'],
        ['-', 'One row = one athlete + one sport assignment. Add another row for a second sport.'],
        ['-', 'Unit managers / coaches can only import for their assigned team or events.'],
    ];

    exportSimpleXlsx('athlete-roster-import-template.xlsx', [
        'Roster' => [
            'headers' => $headers,
            'rows' => $sampleRows,
        ],
        'Instructions' => [
            'headers' => ['Item', 'Details'],
            'rows' => $instructionRows,
        ],
        'Teams' => [
            'headers' => ['team_name', 'short_name'],
            'rows' => $teamRows,
        ],
        'Sports' => [
            'headers' => ['sport_name', 'sport_category'],
            'rows' => $sportRows,
        ],
    ]);
}

/**
 * Build lookup maps for teams and sports used during import.
 *
 * @return array{teams: array<string, array>, sports: array<string, array>}
 */
function buildRosterImportLookups(): array
{
    $db = getDB();
    $teams = [];
    foreach ($db->query('SELECT * FROM intramural_teams WHERE is_active = 1')->fetchAll() as $t) {
        $teams[strtolower(trim($t['name']))] = $t;
        if (!empty($t['short_name'])) {
            $teams[strtolower(trim($t['short_name']))] = $t;
        }
    }

    $sports = [];
    foreach ($db->query('SELECT * FROM intramural_sports WHERE is_active = 1')->fetchAll() as $s) {
        $key = strtolower(trim($s['name']));
        $sports[$key . '|' . strtolower($s['category'])] = $s;
        // First match by name alone (if unique enough, resolve later)
        if (!isset($sports[$key])) {
            $sports[$key] = $s;
        } else {
            // Mark ambiguous name-only key
            $sports[$key] = ['_ambiguous' => true];
        }
    }

    return ['teams' => $teams, 'sports' => $sports];
}

function resolveImportSport(array $lookups, string $sportName, string $category = ''): ?array
{
    $name = strtolower(trim($sportName));
    if ($name === '') {
        return null;
    }

    $category = strtolower(trim($category));
    if ($category !== '' && isset($lookups['sports'][$name . '|' . $category]) && empty($lookups['sports'][$name . '|' . $category]['_ambiguous'])) {
        return $lookups['sports'][$name . '|' . $category];
    }

    if (isset($lookups['sports'][$name]) && empty($lookups['sports'][$name]['_ambiguous'])) {
        return $lookups['sports'][$name];
    }

    // Try matching any category when category omitted
    if ($category === '') {
        foreach (['mixed', 'men', 'women'] as $cat) {
            $key = $name . '|' . $cat;
            if (isset($lookups['sports'][$key]) && empty($lookups['sports'][$key]['_ambiguous'])) {
                return $lookups['sports'][$key];
            }
        }
    }

    return null;
}
