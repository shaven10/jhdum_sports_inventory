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
        'team_play_sds_consolation' => 'Team Play SDS (Single Elimination with Consolation)',
        'custom' => 'Custom / Agreed Format',
    ];
}

function isSdsTournamentFormat(?string $format): bool
{
    return in_array((string) $format, ['team_play_sds', 'team_play_sds_consolation'], true);
}

function isBracketTournamentFormat(?string $format): bool
{
    return in_array((string) $format, [
        'single_elimination',
        'single_elimination_consolation',
        'double_elimination',
        'team_play_sds',
        'team_play_sds_consolation',
    ], true);
}

/** Ensure intramural_sports.tournament_format ENUM includes all supported styles. */
function ensureTournamentFormatEnum(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $col = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'intramural_sports'
          AND COLUMN_NAME = 'tournament_format'")->fetchColumn();
    if (!$col) {
        return;
    }

    $needed = array_keys(tournamentFormatLabels());
    foreach ($needed as $val) {
        if (stripos((string) $col, "'" . $val . "'") === false) {
            $enumSql = implode(', ', array_map(static fn($v) => "'" . str_replace("'", '', $v) . "'", $needed));
            $db->exec("ALTER TABLE intramural_sports MODIFY COLUMN tournament_format ENUM({$enumSql}) NOT NULL DEFAULT 'round_robin'");
            return;
        }
    }
}

function tournamentFormatLabel(?string $format): string
{
    $labels = tournamentFormatLabels();
    $key = $format ?: 'round_robin';
    return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
}

function ensurePlayersPerEventColumn(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute(['intramural_sports', 'players_per_event']);
    if ((int) $stmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE intramural_sports ADD COLUMN players_per_event INT DEFAULT NULL AFTER category');
        seedSportPlayersPerEvent($db);
    }

    ensureSportVenueColumn();
    ensureTournamentFormatEnum();
    ensureEventRanksTable();
}

/** Ensure intramural_sports.venue exists for default event venues. */
function ensureSportVenueColumn(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute(['intramural_sports', 'venue']);
    if ((int) $stmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE intramural_sports ADD COLUMN venue VARCHAR(150) DEFAULT NULL AFTER schedule_notes');
    }

    ensureSportGuidelinesColumn();
}

/** Ensure intramural_sports.guidelines exists for per-sport event guidelines. */
function ensureSportGuidelinesColumn(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute(['intramural_sports', 'guidelines']);
    if ((int) $stmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE intramural_sports ADD COLUMN guidelines TEXT NULL AFTER rules');
    }

    ensureSportGameDurationColumn();
}

/** Ensure intramural_sports.game_duration_minutes exists for match scheduling. */
function ensureSportGameDurationColumn(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute(['intramural_sports', 'game_duration_minutes']);
    if ((int) $stmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE intramural_sports ADD COLUMN game_duration_minutes INT NOT NULL DEFAULT 60 AFTER venue');
    }
}

const DEFAULT_GAME_DURATION_MINUTES = 60;
const MIN_GAME_DURATION_MINUTES = 15;
const MAX_GAME_DURATION_MINUTES = 480;

/** Clamp or infer per-sport estimated game length in minutes. */
function normalizeGameDurationMinutes($value, ?array $sport = null): int
{
    $minutes = (int) $value;
    if ($minutes <= 0 && $sport !== null) {
        $minutes = guessDefaultGameDuration($sport);
    }
    if ($minutes <= 0) {
        $minutes = DEFAULT_GAME_DURATION_MINUTES;
    }

    return max(MIN_GAME_DURATION_MINUTES, min(MAX_GAME_DURATION_MINUTES, $minutes));
}

/** Best-effort default duration from sport name / scoring when not configured. */
function guessDefaultGameDuration(array $sport): int
{
    $name = strtolower((string) ($sport['name'] ?? ''));
    if (str_contains($name, 'basketball')) {
        return str_contains($name, '3x3') ? 45 : 90;
    }
    if (str_contains($name, 'volleyball') || str_contains($name, 'sepak')) {
        return 90;
    }
    if (str_contains($name, 'baseball') || str_contains($name, 'softball')) {
        return 120;
    }
    if (str_contains($name, 'athletics') || str_contains($name, 'track')) {
        return 180;
    }
    if (str_contains($name, 'chess')) {
        return 30;
    }
    if (str_contains($name, 'badminton') || str_contains($name, 'table tennis')
        || str_contains($name, 'pickleball') || str_contains($name, 'tennis')) {
        return 45;
    }
    if (str_contains($name, 'dance') || str_contains($name, 'mlbb') || str_contains($name, 'codm')
        || str_contains($name, 'esport') || str_contains($name, 'frisbee')) {
        return 60;
    }

    $scoring = (string) ($sport['scoring_method'] ?? '');
    if ($scoring === 'time') {
        return 120;
    }
    if ($scoring === 'sets') {
        return 90;
    }

    return DEFAULT_GAME_DURATION_MINUTES;
}

function getSportGameDurationMinutes(array $sport): int
{
    ensureSportGameDurationColumn();

    return normalizeGameDurationMinutes($sport['game_duration_minutes'] ?? null, $sport);
}

function formatGameDurationMinutes(int $minutes): string
{
    if ($minutes % 60 === 0) {
        $hours = (int) ($minutes / 60);

        return $hours === 1 ? '1 hour' : $hours . ' hours';
    }
    if ($minutes > 60) {
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return $hours . ' hr ' . $mins . ' min';
    }

    return $minutes . ' min';
}

/** Ensure intramural_seasons has roster lock columns. */
function ensureRosterLockColumns(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');

    $stmt->execute(['intramural_seasons', 'roster_locked']);
    if ((int) $stmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE intramural_seasons ADD COLUMN roster_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER is_archived');
    }

    $stmt->execute(['intramural_seasons', 'roster_locked_at']);
    if ((int) $stmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE intramural_seasons ADD COLUMN roster_locked_at DATETIME DEFAULT NULL AFTER roster_locked');
    }

    $stmt->execute(['intramural_seasons', 'roster_locked_by']);
    if ((int) $stmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE intramural_seasons ADD COLUMN roster_locked_by INT DEFAULT NULL AFTER roster_locked_at');
    }

    $stmt->execute(['intramural_seasons', 'roster_lock_date']);
    if ((int) $stmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE intramural_seasons ADD COLUMN roster_lock_date DATE DEFAULT NULL AFTER roster_locked');
    }
}

/** Ensure intramural_event_managers exists for per-event tournament manager assignments. */
function ensureEventManagersTable(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(['intramural_event_managers']);
    if ((int) $stmt->fetchColumn() === 0) {
        $db->exec("CREATE TABLE intramural_event_managers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            season_id INT NOT NULL,
            sport_id INT NOT NULL,
            manager_user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sport_season_manager (sport_id, season_id),
            FOREIGN KEY (season_id) REFERENCES intramural_seasons(id) ON DELETE RESTRICT,
            FOREIGN KEY (sport_id) REFERENCES intramural_sports(id) ON DELETE CASCADE,
            FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        return;
    }

    $colStmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $colStmt->execute(['intramural_event_managers', 'user_id']);
    if ((int) $colStmt->fetchColumn() > 0) {
        $colStmt->execute(['intramural_event_managers', 'manager_user_id']);
        if ((int) $colStmt->fetchColumn() === 0) {
            $db->exec('ALTER TABLE intramural_event_managers CHANGE user_id manager_user_id INT NOT NULL');
        }
    }

    $idxStmt = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $idxStmt->execute(['intramural_event_managers', 'uq_sport_season_manager']);
    if ((int) $idxStmt->fetchColumn() === 0) {
        try {
            $db->exec('ALTER TABLE intramural_event_managers ADD UNIQUE KEY uq_sport_season_manager (sport_id, season_id)');
        } catch (Throwable $e) {
            // ignore duplicate rows blocking unique index
        }
    }
}

/** Default max players per team for known intramural sports. */
function sportPlayersPerEventDefaults(): array
{
    return [
        'Basketball 5x5' => 12,
        'Basketball 3x3' => 4,
        'Basketball' => 12,
        'Volleyball' => 12,
        'Sepak Takraw' => 6,
        'MLBB/CODM' => 5,
        'Badminton' => 6,
        'Table Tennis' => 4,
        'Pickleball' => 4,
        'Lawn Tennis' => 4,
        'Athletics' => 25,
        'Chess' => 4,
        'Baseball' => 15,
        'Softball' => 15,
        'Frisbee' => 10,
        'Dance Sports' => 8,
        'Mass Power Dance' => 20,
    ];
}

/** Backfill players_per_event for sports that match known names. */
function seedSportPlayersPerEvent(PDO $db, bool $onlyNull = true): int
{
    $sql = $onlyNull
        ? 'UPDATE intramural_sports SET players_per_event = ? WHERE name = ? AND players_per_event IS NULL'
        : 'UPDATE intramural_sports SET players_per_event = ? WHERE name = ?';
    $stmt = $db->prepare($sql);
    $updated = 0;

    foreach (sportPlayersPerEventDefaults() as $name => $count) {
        $stmt->execute([(int) $count, $name]);
        $updated += $stmt->rowCount();
    }

    return $updated;
}

function sportCategoryOptions(): array
{
    return ['men' => 'Men', 'women' => 'Women', 'mixed' => 'Mixed'];
}

function ensureSportCategoryEnum(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $col = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'intramural_sports' AND COLUMN_NAME = 'category'")->fetchColumn();
    if ($col && stripos((string) $col, 'mixed') === false) {
        $db->exec("ALTER TABLE intramural_sports MODIFY COLUMN category ENUM('men', 'women', 'mixed') NOT NULL DEFAULT 'men'");
    }

    removeInactiveSports();
}

/** Remove soft-deactivated sports and drop legacy is_active column. */
function removeInactiveSports(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute(['intramural_sports', 'is_active']);
    if ((int) $stmt->fetchColumn() === 0) {
        return;
    }

    $db->exec('DELETE FROM intramural_sports WHERE is_active = 0');
    $db->exec('ALTER TABLE intramural_sports DROP COLUMN is_active');
}

/** Canonical intramural sport list (seeded for both Men and Women categories). */
function intramuralSportDefinitions(): array
{
    $sdsNotes = 'Team Play SDS — single elimination, each team tie is Singles, Doubles, Singles (best of 3)';

    return [
        ['name' => 'Basketball 5x5', 'description' => '5-on-5 basketball', 'scoring_method' => 'points', 'scheme' => 'Major Team Sports', 'win_points' => 3, 'players_per_event' => 12, 'tournament_format' => 'round_robin', 'format_notes' => null],
        ['name' => 'Basketball 3x3', 'description' => '3-on-3 basketball', 'scoring_method' => 'points', 'scheme' => 'Major Team Sports', 'win_points' => 3, 'players_per_event' => 4, 'tournament_format' => 'round_robin', 'format_notes' => null],
        ['name' => 'Volleyball', 'description' => 'Indoor volleyball', 'scoring_method' => 'sets', 'scheme' => 'Major Team Sports', 'win_points' => 3, 'players_per_event' => 12, 'tournament_format' => 'round_robin', 'format_notes' => null],
        ['name' => 'Sepak Takraw', 'description' => 'Sepak takraw tournament', 'scoring_method' => 'sets', 'scheme' => 'Major Team Sports', 'win_points' => 3, 'players_per_event' => 6, 'tournament_format' => 'round_robin', 'format_notes' => null],
        ['name' => 'MLBB/CODM', 'description' => 'Mobile Legends / Call of Duty Mobile', 'scoring_method' => 'games', 'scheme' => 'Major Team Sports', 'win_points' => 3, 'players_per_event' => 5, 'tournament_format' => 'round_robin', 'format_notes' => null],
        ['name' => 'Badminton', 'description' => 'Badminton singles/doubles', 'scoring_method' => 'games', 'scheme' => 'Racket & Dance Sports', 'win_points' => 3, 'players_per_event' => 6, 'tournament_format' => 'team_play_sds', 'format_notes' => $sdsNotes],
        ['name' => 'Table Tennis', 'description' => 'Table tennis', 'scoring_method' => 'games', 'scheme' => 'Racket & Dance Sports', 'win_points' => 3, 'players_per_event' => 4, 'tournament_format' => 'team_play_sds', 'format_notes' => $sdsNotes],
        ['name' => 'Pickleball', 'description' => 'Pickleball', 'scoring_method' => 'games', 'scheme' => 'Racket & Dance Sports', 'win_points' => 3, 'players_per_event' => 4, 'tournament_format' => 'round_robin', 'format_notes' => null],
        ['name' => 'Lawn Tennis', 'description' => 'Lawn tennis', 'scoring_method' => 'games', 'scheme' => 'Racket & Dance Sports', 'win_points' => 3, 'players_per_event' => 4, 'tournament_format' => 'team_play_sds', 'format_notes' => $sdsNotes],
        ['name' => 'Athletics', 'description' => 'Track and field', 'scoring_method' => 'points', 'scheme' => 'Athletics & Chess', 'win_points' => 3, 'players_per_event' => 25, 'tournament_format' => 'round_robin', 'format_notes' => null],
        ['name' => 'Chess', 'description' => 'Chess', 'scoring_method' => 'games', 'scheme' => 'Athletics & Chess', 'win_points' => 3, 'players_per_event' => 4, 'tournament_format' => 'round_robin', 'format_notes' => null],
        ['name' => 'Baseball', 'description' => 'Baseball', 'scoring_method' => 'points', 'scheme' => 'Major Team Sports', 'win_points' => 3, 'players_per_event' => 15, 'tournament_format' => 'round_robin', 'format_notes' => null],
        ['name' => 'Softball', 'description' => 'Softball', 'scoring_method' => 'points', 'scheme' => 'Major Team Sports', 'win_points' => 3, 'players_per_event' => 15, 'tournament_format' => 'round_robin', 'format_notes' => null],
        ['name' => 'Frisbee', 'description' => 'Ultimate frisbee', 'scoring_method' => 'points', 'scheme' => 'Major Team Sports', 'win_points' => 3, 'players_per_event' => 10, 'tournament_format' => 'round_robin', 'format_notes' => null],
        ['name' => 'Dance Sports', 'description' => 'Dance sports', 'scoring_method' => 'points', 'scheme' => 'Racket & Dance Sports', 'win_points' => 3, 'players_per_event' => 8, 'tournament_format' => 'round_robin', 'format_notes' => null],
        ['name' => 'Mass Power Dance', 'description' => 'Mass power dance', 'scoring_method' => 'points', 'scheme' => 'Major Team Sports', 'win_points' => 3, 'players_per_event' => 20, 'tournament_format' => 'round_robin', 'format_notes' => null],
    ];
}

/** Ensure men and women rows exist for all canonical sports. */
function seedIntramuralSports(PDO $db, bool $onlyMissing = true): int
{
    $schemeMap = [];
    foreach ($db->query('SELECT id, name FROM intramural_point_schemes')->fetchAll() as $row) {
        $schemeMap[$row['name']] = (int) $row['id'];
    }

    $find = $db->prepare('SELECT id FROM intramural_sports WHERE name = ? AND category = ?');
    $insert = $db->prepare('INSERT INTO intramural_sports (name, description, category, players_per_event, scoring_method, tournament_format, format_notes, win_points, draw_points, loss_points, point_scheme_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?)');
    $update = $db->prepare('UPDATE intramural_sports SET description=?, players_per_event=?, scoring_method=?, tournament_format=?, format_notes=?, win_points=?, point_scheme_id=? WHERE id=?');
    $created = 0;

    foreach (intramuralSportDefinitions() as $def) {
        $schemeId = $schemeMap[$def['scheme']] ?? null;

        foreach (array_keys(sportCategoryOptions()) as $category) {
            $find->execute([$def['name'], $category]);
            $existingId = $find->fetchColumn();

            if ($existingId) {
                if (!$onlyMissing) {
                    $update->execute([
                        $def['description'],
                        $def['players_per_event'],
                        $def['scoring_method'],
                        $def['tournament_format'],
                        $def['format_notes'],
                        $def['win_points'],
                        $schemeId,
                        $existingId,
                    ]);
                }
                continue;
            }

            $insert->execute([
                $def['name'],
                $def['description'],
                $category,
                $def['players_per_event'],
                $def['scoring_method'],
                $def['tournament_format'],
                $def['format_notes'],
                $def['win_points'],
                $schemeId,
            ]);
            $created++;
        }
    }

    return $created;
}

/** Convert legacy mixed sports to men and add women counterparts. */
function migrateMixedSportCategories(PDO $db): void
{
    $mixed = $db->query("SELECT * FROM intramural_sports WHERE category = 'mixed'")->fetchAll();
    foreach ($mixed as $sport) {
        $db->prepare("UPDATE intramural_sports SET category = 'men' WHERE id = ?")->execute([$sport['id']]);
    }

    seedIntramuralSports($db);

    $col = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'intramural_sports' AND COLUMN_NAME = 'category'")->fetchColumn();
    if ($col && stripos((string) $col, 'mixed') !== false) {
        $db->exec("ALTER TABLE intramural_sports MODIFY COLUMN category ENUM('men', 'women', 'mixed') NOT NULL DEFAULT 'men'");
    }
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
        case 'team_play_sds_consolation':
            return buildTeamPlaySdsConsolationFixtures($teamIds);
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

/** SDS rubber order for each team tie: Singles → Doubles → Singles. */
function sdsRubberLegs(): array
{
    return [
        ['suffix' => 'Singles 1', 'note' => 'First singles rubber'],
        ['suffix' => 'Doubles', 'note' => 'Doubles rubber'],
        ['suffix' => 'Singles 2', 'note' => 'Second singles rubber'],
    ];
}

/**
 * Expand each team-vs-team tie into SDS rubbers (best of 3).
 *
 * @param list<array<string, mixed>> $ties
 * @return list<array<string, mixed>>
 */
function expandTiesToSdsFixtures(array $ties): array
{
    $legs = sdsRubberLegs();
    $fixtures = [];
    $order = 0;
    $tieNum = 0;

    foreach ($ties as $tie) {
        $tieNum++;
        $roundLabel = (string) ($tie['round_label'] ?? ('Round ' . (int) ($tie['round_number'] ?? 1)));
        $baseRound = (int) ($tie['round_number'] ?? 1);
        $isTbd = empty($tie['team_a_id']) || empty($tie['team_b_id']);
        $baseNotes = trim((string) ($tie['notes'] ?? ''));

        foreach ($legs as $leg) {
            $order++;
            $noteParts = [];
            if ($baseNotes !== '') {
                $noteParts[] = $baseNotes;
            } elseif ($isTbd) {
                $noteParts[] = 'TBD — fill teams after previous SDS ties';
            }
            $noteParts[] = 'SDS tie #' . $tieNum . ' — ' . $leg['note'] . '. Team wins the tie with 2 of 3 rubbers.';
            $fixtures[] = [
                'team_a_id' => $tie['team_a_id'],
                'team_b_id' => $tie['team_b_id'],
                'round_number' => $baseRound,
                'round_label' => $roundLabel . ' — SDS ' . $leg['suffix'],
                'match_order' => $order,
                'notes' => implode('. ', $noteParts),
            ];
        }
    }

    return $fixtures;
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
    if (count($teamIds) < 2) {
        return [];
    }

    return expandTiesToSdsFixtures(buildSingleEliminationFixtures($teamIds));
}

/**
 * Team Play SDS with consolation: championship SDS ties, then consolation /
 * 3rd-place SDS ties, championship Final SDS last.
 *
 * @param list<int> $teamIds
 * @return list<array<string, mixed>>
 */
function buildTeamPlaySdsConsolationFixtures(array $teamIds): array
{
    $teamIds = array_values(array_unique(array_map('intval', $teamIds)));
    sort($teamIds);
    if (count($teamIds) < 2) {
        return [];
    }

    return expandTiesToSdsFixtures(buildSingleEliminationConsolationFixtures($teamIds));
}

function isChessSport(string $sportName): bool
{
    return strcasecmp(trim($sportName), 'Chess') === 0;
}

/** Default chess boards per team tie (from sport roster size or 4). */
function defaultChessBoardsPerTeam(array $sport): int
{
    $fromSport = (int) ($sport['players_per_event'] ?? 0);
    if ($fromSport >= 1 && $fromSport <= 20) {
        return $fromSport;
    }

    return 4;
}

/**
 * Expand each team-vs-team tie into individual chess board matches.
 *
 * @param list<array<string, mixed>> $fixtures
 * @return list<array<string, mixed>>
 */
function expandChessBoardFixtures(array $fixtures, int $boardsPerTeam): array
{
    $boardsPerTeam = max(1, min(20, $boardsPerTeam));
    if (empty($fixtures)) {
        return [];
    }

    $expanded = [];
    $order = 0;
    $tieNum = 0;

    foreach ($fixtures as $tie) {
        $tieNum++;
        $baseRound = (int) ($tie['round_number'] ?? 1);
        $roundLabel = (string) ($tie['round_label'] ?? 'Round Robin');
        $isTbd = empty($tie['team_a_id']) || empty($tie['team_b_id']);

        for ($board = 1; $board <= $boardsPerTeam; $board++) {
            $order++;
            $expanded[] = [
                'team_a_id' => $tie['team_a_id'],
                'team_b_id' => $tie['team_b_id'],
                'round_number' => $baseRound,
                'round_label' => $roundLabel . ' — Board ' . $board,
                'match_order' => $order,
                'notes' => ($isTbd ? 'TBD — fill teams after previous round. ' : '')
                    . 'Chess team tie #' . $tieNum . ' — Board ' . $board . ' of ' . $boardsPerTeam
                    . '. Record each board result; team match score is based on boards won.',
            ];
        }
    }

    return $expanded;
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
 * Sequence (match_order):
 *  1) Championship rounds except the Final
 *  2) First-round loser consolation bracket (8+ teams; 5th–8th place)
 *  3) Consolation Final (3rd Place)
 *  4) Championship Final (always last)
 *
 * @param list<int> $teamIds
 * @return list<array<string, mixed>>
 */
function buildSingleEliminationConsolationFixtures(array $teamIds): array
{
    $championship = buildSingleEliminationFixtures($teamIds);

    $n = count($teamIds);
    $size = 1;
    while ($size < $n) {
        $size *= 2;
    }
    $rounds = (int) log($size, 2);

    $preFinal = [];
    $finals = [];
    foreach ($championship as $f) {
        $label = strtolower(trim((string) ($f['round_label'] ?? '')));
        $isFinal = $label === 'final' || (int) ($f['round_number'] ?? 0) === $rounds;
        if ($isFinal) {
            $finals[] = $f;
        } else {
            $preFinal[] = $f;
        }
    }

    // Fallback: if labels differ, treat the last championship round as the Final.
    if (empty($finals) && !empty($preFinal)) {
        $maxRound = 0;
        foreach ($preFinal as $f) {
            $maxRound = max($maxRound, (int) ($f['round_number'] ?? 0));
        }
        $kept = [];
        foreach ($preFinal as $f) {
            if ((int) ($f['round_number'] ?? 0) === $maxRound) {
                $finals[] = $f;
            } else {
                $kept[] = $f;
            }
        }
        $preFinal = $kept;
    }

    foreach ($preFinal as &$f) {
        $existing = (string) ($f['notes'] ?? '');
        if ($existing === 'TBD — fill teams after previous round results') {
            $f['notes'] = 'TBD — championship bracket';
        } elseif ($existing === '') {
            $f['notes'] = 'Championship bracket';
        }
    }
    unset($f);

    foreach ($finals as &$f) {
        $f['round_label'] = 'Final';
        $f['notes'] = 'Championship Final — scheduled last after consolation games';
    }
    unset($f);

    $consolation = [];
    $order = 0;

    // 5th–8th place bracket (first-round losers) before 3rd-place game
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
            $consolation = array_merge($consolation, buildConsolationBracketShells($r1Losers, $order));
        }
    }

    // 3rd Place after consolation bracket, still before championship Final
    if ($rounds >= 2) {
        $order++;
        $consolation[] = [
            'team_a_id' => null,
            'team_b_id' => null,
            'round_number' => $rounds + 50,
            'round_label' => 'Consolation Final (3rd Place)',
            'match_order' => $order,
            'notes' => 'TBD — losers of championship semi-finals',
        ];
    }

    // Rebuild in playable sequence and renumber match_order / round_number for sorting
    $sequence = array_merge($preFinal, $consolation, $finals);
    $matchOrder = 0;
    $roundCursor = 0;
    $lastLabel = null;
    $resequenced = [];

    foreach ($sequence as $f) {
        $label = (string) ($f['round_label'] ?? '');
        if ($label !== $lastLabel) {
            $roundCursor++;
            $lastLabel = $label;
        }
        $matchOrder++;
        $f['round_number'] = $roundCursor;
        $f['match_order'] = $matchOrder;
        $resequenced[] = $f;
    }

    return $resequenced;
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
 * Schedule window for auto-timing generated matches.
 *
 * @param array{
 *   start_date_start_hour?:int,
 *   start_date_end_hour?:int,
 *   end_date_start_hour?:int,
 *   end_date_end_hour?:int,
 *   daily_start_hour?:int,
 *   daily_end_hour?:int,
 *   start_hour?:int,
 *   end_hour?:int
 * } $hours
 * @return array{
 *   start_date:?string,
 *   end_date:?string,
 *   start_date_start_hour:int,
 *   start_date_end_hour:int,
 *   end_date_start_hour:int,
 *   end_date_end_hour:int,
 *   daily_start_hour:int,
 *   daily_end_hour:int
 * }
 */
function buildScheduleWindow(?string $startDate, ?string $endDate, array $hours = []): array
{
    $legacyStartHour = max(0, min(23, (int) ($hours['start_hour'] ?? 7)));
    $legacyEndHour = max($legacyStartHour + 1, min(24, (int) ($hours['end_hour'] ?? 17)));

    $startDateStartHour = max(0, min(23, (int) ($hours['start_date_start_hour'] ?? $legacyStartHour)));
    $startDateEndHour = max($startDateStartHour + 1, min(24, (int) ($hours['start_date_end_hour'] ?? $legacyEndHour)));
    $endDateStartHour = max(0, min(23, (int) ($hours['end_date_start_hour'] ?? $legacyStartHour)));
    $endDateEndHour = max($endDateStartHour + 1, min(24, (int) ($hours['end_date_end_hour'] ?? $legacyEndHour)));
    $dailyStartHour = max(0, min(23, (int) ($hours['daily_start_hour'] ?? $legacyStartHour)));
    $dailyEndHour = max($dailyStartHour + 1, min(24, (int) ($hours['daily_end_hour'] ?? $legacyEndHour)));

    return [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'start_date_start_hour' => $startDateStartHour,
        'start_date_end_hour' => $startDateEndHour,
        'end_date_start_hour' => $endDateStartHour,
        'end_date_end_hour' => $endDateEndHour,
        'daily_start_hour' => $dailyStartHour,
        'daily_end_hour' => $dailyEndHour,
    ];
}

/** Inclusive playable hours for a calendar day inside the schedule window. */
function scheduleDayBounds(string $dateYmd, array $window): array
{
    $startDate = (string) ($window['start_date'] ?? '');
    $endDate = (string) ($window['end_date'] ?? '');

    if ($startDate !== '' && $dateYmd === $startDate) {
        return [(int) $window['start_date_start_hour'], (int) $window['start_date_end_hour']];
    }
    if ($endDate !== '' && $dateYmd === $endDate) {
        return [(int) $window['end_date_start_hour'], (int) $window['end_date_end_hour']];
    }

    return [(int) $window['daily_start_hour'], (int) $window['daily_end_hour']];
}

function formatScheduleHour(int $hour): string
{
    return sprintf('%02d:00', max(0, min(23, $hour)));
}

function formatScheduleWindowSummary(array $window): string
{
    if (!scheduleWindowIsValid($window)) {
        return '';
    }

    $startDate = (string) $window['start_date'];
    $endDate = (string) $window['end_date'];
    $parts = [formatDate($startDate) . ' → ' . formatDate($endDate)];

    if ($startDate === $endDate) {
        $parts[] = formatScheduleHour((int) $window['start_date_start_hour'])
            . '–' . formatScheduleHour((int) $window['start_date_end_hour']);
    } else {
        $parts[] = 'start date '
            . formatScheduleHour((int) $window['start_date_start_hour'])
            . '–' . formatScheduleHour((int) $window['start_date_end_hour']);
        $parts[] = 'end date '
            . formatScheduleHour((int) $window['end_date_start_hour'])
            . '–' . formatScheduleHour((int) $window['end_date_end_hour']);
        $parts[] = 'regular days '
            . formatScheduleHour((int) $window['daily_start_hour'])
            . '–' . formatScheduleHour((int) $window['daily_end_hour']);
    }

    return implode('; ', $parts);
}

function scheduleWindowIsValid(array $window): bool
{
    if (empty($window['start_date']) || empty($window['end_date'])) {
        return false;
    }

    try {
        $start = new DateTime((string) $window['start_date']);
        $end = new DateTime((string) $window['end_date']);
    } catch (Throwable $e) {
        return false;
    }

    return $end >= $start;
}

/** Earliest allowed start datetime inside the schedule window. */
function scheduleWindowStartDateTime(array $window): ?DateTime
{
    if (!scheduleWindowIsValid($window)) {
        return null;
    }

    try {
        $cursor = new DateTime((string) $window['start_date']);
        [$startHour] = scheduleDayBounds($cursor->format('Y-m-d'), $window);
        $cursor->setTime($startHour, 0, 0);

        return $cursor;
    } catch (Throwable $e) {
        return null;
    }
}

/** Latest allowed end datetime inside the schedule window. */
function scheduleWindowEndDateTime(array $window): ?DateTime
{
    if (!scheduleWindowIsValid($window)) {
        return null;
    }

    try {
        $cursor = new DateTime((string) $window['end_date']);
        [, $endHour] = scheduleDayBounds($cursor->format('Y-m-d'), $window);
        $cursor->setTime($endHour, 0, 0);

        return $cursor;
    } catch (Throwable $e) {
        return null;
    }
}

function createScheduleCursor(array $window, ?DateTime $resume = null): ?DateTime
{
    if (!scheduleWindowIsValid($window)) {
        return null;
    }

    if ($resume !== null) {
        $cursor = clone $resume;
        if (normalizeScheduleCursor($cursor, $window)) {
            return $cursor;
        }

        return null;
    }

    return scheduleWindowStartDateTime($window);
}

/** Move cursor into the daily window; return false if past the schedule end date. */
function normalizeScheduleCursor(DateTime &$cursor, array $window): bool
{
    try {
        $startDate = new DateTime((string) $window['start_date']);
        $startDate->setTime(0, 0, 0);
        $endDate = new DateTime((string) $window['end_date']);
        $endDate->setTime(23, 59, 59);
    } catch (Throwable $e) {
        return false;
    }

    if ($cursor < $startDate) {
        $windowStart = scheduleWindowStartDateTime($window);
        if (!$windowStart) {
            return false;
        }
        $cursor = clone $windowStart;
    }

    while (true) {
        if ($cursor > $endDate) {
            return false;
        }

        [$dayStartHour, $dayEndHour] = scheduleDayBounds($cursor->format('Y-m-d'), $window);
        $timeMinutes = ((int) $cursor->format('G')) * 60 + (int) $cursor->format('i');
        $startMinutes = $dayStartHour * 60;
        $endMinutes = $dayEndHour * 60;

        if ($timeMinutes < $startMinutes) {
            $cursor->setTime($dayStartHour, 0, 0);

            return true;
        }
        if ($timeMinutes >= $endMinutes) {
            $cursor->modify('+1 day');
            if ($cursor > $endDate) {
                return false;
            }
            [$nextDayStartHour] = scheduleDayBounds($cursor->format('Y-m-d'), $window);
            $cursor->setTime($nextDayStartHour, 0, 0);
            continue;
        }

        return true;
    }
}

function matchFitsScheduleWindow(DateTime $start, int $durationMinutes, array $window): bool
{
    $windowStart = scheduleWindowStartDateTime($window);
    $lastDay = scheduleWindowEndDateTime($window);
    if (!$windowStart || !$lastDay) {
        return false;
    }

    if ($start < $windowStart || $start > $lastDay) {
        return false;
    }

    [, $dayEndHour] = scheduleDayBounds($start->format('Y-m-d'), $window);
    $end = clone $start;
    $end->modify('+' . $durationMinutes . ' minutes');
    $dayEnd = clone $start;
    $dayEnd->setTime($dayEndHour, 0, 0);

    return $end <= $dayEnd && $end <= $lastDay;
}

function advanceScheduleCursor(DateTime &$cursor, int $durationMinutes, array $window): bool
{
    $cursor->modify('+' . $durationMinutes . ' minutes');

    return normalizeScheduleCursor($cursor, $window);
}

/**
 * @param list<array{0:int,1:int}> $ranges Unix timestamps [start, end)
 */
function matchOverlapsRanges(DateTime $start, int $durationMinutes, array $ranges): bool
{
    $startTs = (int) $start->format('U');
    $endTs = $startTs + ($durationMinutes * 60);
    foreach ($ranges as $range) {
        if ($startTs < $range[1] && $range[0] < $endTs) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<array{0:int,1:int}> $ranges
 */
function bumpCursorPastRanges(DateTime &$cursor, int $durationMinutes, array $ranges, array $window): bool
{
    for ($attempt = 0; $attempt < 10000; $attempt++) {
        if (!normalizeScheduleCursor($cursor, $window)) {
            return false;
        }
        if (matchOverlapsRanges($cursor, $durationMinutes, $ranges)) {
            $cursor->modify('+15 minutes');
            continue;
        }
        if (matchFitsScheduleWindow($cursor, $durationMinutes, $window)) {
            return true;
        }
        $cursor->modify('+1 day');
        [$dayStartHour] = scheduleDayBounds($cursor->format('Y-m-d'), $window);
        $cursor->setTime($dayStartHour, 0, 0);
    }

    return false;
}

/** Resume scheduling after the latest existing match end time in the season. */
function getScheduleResumeCursor(int $seasonId, array $window): ?DateTime
{
    if (!scheduleWindowIsValid($window)) {
        return null;
    }

    if ($seasonId <= 0) {
        return createScheduleCursor($window);
    }

    ensureSportGameDurationColumn();
    $db = getDB();
    $stmt = $db->prepare('SELECT m.scheduled_at, s.game_duration_minutes
        FROM intramural_matches m
        JOIN intramural_sports s ON m.sport_id = s.id
        WHERE m.season_id = ? AND m.scheduled_at IS NOT NULL AND m.status NOT IN (\'cancelled\')');
    $stmt->execute([$seasonId]);
    $maxEnd = null;
    foreach ($stmt->fetchAll() as $row) {
        $startTs = strtotime((string) $row['scheduled_at']);
        if ($startTs === false) {
            continue;
        }
        $duration = normalizeGameDurationMinutes($row['game_duration_minutes'] ?? null);
        $endTs = $startTs + ($duration * 60);
        if ($maxEnd === null || $endTs > $maxEnd) {
            $maxEnd = $endTs;
        }
    }

    if ($maxEnd === null) {
        return createScheduleCursor($window);
    }

    $cursor = DateTime::createFromFormat('U', (string) $maxEnd);
    if (!$cursor) {
        return createScheduleCursor($window);
    }

    $windowStart = scheduleWindowStartDateTime($window);
    $windowEnd = scheduleWindowEndDateTime($window);
    if ($windowStart && $cursor < $windowStart) {
        return clone $windowStart;
    }
    if ($windowEnd && $cursor > $windowEnd) {
        return clone $windowStart;
    }

    if (!normalizeScheduleCursor($cursor, $window)) {
        return $windowStart ? clone $windowStart : null;
    }

    return $cursor;
}

/**
 * Assign scheduled_at using each sport's estimated game duration.
 *
 * @param list<array<string, mixed>> $fixtures
 * @return int Number of fixtures scheduled
 */
function applyDurationScheduleToFixtures(array &$fixtures, array $sport, array $window, DateTime &$cursor): int
{
    $duration = getSportGameDurationMinutes($sport);
    $scheduled = 0;
    foreach ($fixtures as &$fixture) {
        if (!bumpCursorPastRanges($cursor, $duration, [], $window)) {
            break;
        }
        $fixture['scheduled_at'] = $cursor->format('Y-m-d H:i:s');
        $scheduled++;
        if (!advanceScheduleCursor($cursor, $duration, $window)) {
            break;
        }
    }
    unset($fixture);

    return $scheduled;
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

/** Normalize venue names for grouping (case/spacing insensitive). */
function normalizeVenueKey(?string $venue): string
{
    $v = strtolower(trim(preg_replace('/\s+/', ' ', (string) $venue)));
    return $v !== '' ? $v : '';
}

/**
 * Occupied time ranges for a venue in a season (existing scheduled matches).
 *
 * @return list<array{0:int,1:int}> Unix timestamps [start, end)
 */
function getOccupiedVenueRanges(int $seasonId, string $venueKey): array
{
    if ($venueKey === '' || $seasonId <= 0) {
        return [];
    }

    ensureSportGameDurationColumn();
    $db = getDB();
    $stmt = $db->prepare('SELECT m.scheduled_at, m.venue AS match_venue, s.venue AS sport_venue, s.game_duration_minutes
        FROM intramural_matches m
        JOIN intramural_sports s ON m.sport_id = s.id
        WHERE m.season_id = ?
          AND m.scheduled_at IS NOT NULL
          AND m.status NOT IN (\'cancelled\')');
    $stmt->execute([$seasonId]);
    $occupied = [];
    foreach ($stmt->fetchAll() as $row) {
        $rowVenue = trim((string) ($row['match_venue'] ?? '')) !== ''
            ? $row['match_venue']
            : ($row['sport_venue'] ?? '');
        if (normalizeVenueKey($rowVenue) !== $venueKey) {
            continue;
        }
        $startTs = strtotime((string) $row['scheduled_at']);
        if ($startTs === false) {
            continue;
        }
        $duration = normalizeGameDurationMinutes($row['game_duration_minutes'] ?? null);
        $occupied[] = [$startTs, $startTs + ($duration * 60)];
    }

    return $occupied;
}

/**
 * @deprecated Use getOccupiedVenueRanges() for duration-aware scheduling.
 * @return array<string, true>
 */
function getOccupiedVenueSlots(int $seasonId, string $venueKey): array
{
    $slots = [];
    foreach (getOccupiedVenueRanges($seasonId, $venueKey) as [$startTs]) {
        $slots[date('Y-m-d H:i:s', $startTs)] = true;
    }

    return $slots;
}

/**
 * Smart schedule: group selected events by venue, alternate their games, and
 * avoid overlapping times on the same venue. Spacing uses each sport's estimated
 * game duration. Different venues may share times.
 *
 * @param array<int, list<array<string,mixed>>> $fixturesBySport
 * @param array<int, array> $sportsById
 * @return int Number of fixtures scheduled
 */
function applySmartVenueSchedule(array &$fixturesBySport, array $sportsById, array $window, int $seasonId, ?DateTime $sharedCursor = null): int
{
    if (!scheduleWindowIsValid($window) || empty($fixturesBySport)) {
        return 0;
    }

    $defaultCursor = $sharedCursor ?? getScheduleResumeCursor($seasonId, $window) ?? createScheduleCursor($window);
    if (!$defaultCursor) {
        return 0;
    }

    // venueKey => list of sport ids (stable order)
    $venueGroups = [];
    foreach ($fixturesBySport as $sportId => $fixtures) {
        if (empty($fixtures)) {
            continue;
        }
        $sport = $sportsById[$sportId] ?? [];
        $key = normalizeVenueKey($sport['venue'] ?? null);
        if (!isset($venueGroups[$key])) {
            $venueGroups[$key] = [];
        }
        $venueGroups[$key][] = (int) $sportId;
    }

    $scheduled = 0;

    foreach ($venueGroups as $venueKey => $groupSportIds) {
        // No venue set: schedule each sport independently (no cross-sport blocking)
        if ($venueKey === '') {
            foreach ($groupSportIds as $sportId) {
                $cursor = clone $defaultCursor;
                $scheduled += applyDurationScheduleToFixtures(
                    $fixturesBySport[$sportId],
                    $sportsById[$sportId] ?? [],
                    $window,
                    $cursor
                );
            }
            continue;
        }

        $occupied = getOccupiedVenueRanges($seasonId, $venueKey);
        $cursor = clone $defaultCursor;
        $queues = [];
        foreach ($groupSportIds as $sportId) {
            $queues[$sportId] = array_keys($fixturesBySport[$sportId]);
        }

        $active = true;
        while ($active) {
            $active = false;
            foreach ($groupSportIds as $sportId) {
                if (empty($queues[$sportId])) {
                    continue;
                }
                $active = true;
                $sport = $sportsById[$sportId] ?? [];
                $duration = getSportGameDurationMinutes($sport);
                if (!bumpCursorPastRanges($cursor, $duration, $occupied, $window)) {
                    break 2;
                }

                $idx = array_shift($queues[$sportId]);
                $startTs = (int) $cursor->format('U');
                $when = $cursor->format('Y-m-d H:i:s');
                $fixturesBySport[$sportId][$idx]['scheduled_at'] = $when;
                $fixturesBySport[$sportId][$idx]['venue'] = $sport['venue'] ?? null;
                $occupied[] = [$startTs, $startTs + ($duration * 60)];
                $scheduled++;
                if (!advanceScheduleCursor($cursor, $duration, $window)) {
                    break 2;
                }
            }
        }
    }

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
function generateMatchesForSport(int $sportId, int $seasonId, array $teamIds, ?int $createdBy = null, bool $replaceUnscheduled = false, ?array $scheduleWindow = null, ?DateTime $scheduleCursor = null, ?array $prebuiltFixtures = null): array
{
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM intramural_sports WHERE id = ?');
    $stmt->execute([$sportId]);
    $sport = $stmt->fetch();
    if (!$sport) {
        return ['created' => 0, 'format' => '', 'error' => 'Sport not found.'];
    }

    $format = $sport['tournament_format'] ?? 'round_robin';
    if ($format === 'rank_first_to_last' && count($teamIds) % 2 !== 0) {
        return ['created' => 0, 'format' => $format, 'error' => 'Rank from First to Last requires an even number of teams (each team plays one match).'];
    }

    if ($prebuiltFixtures !== null) {
        $fixtures = $prebuiltFixtures;
    } else {
        $fixtures = buildTournamentFixtures($format, $teamIds);
        if (empty($fixtures)) {
            return ['created' => 0, 'format' => $format, 'error' => 'Select at least 2 teams to generate matches.'];
        }
        if ($scheduleWindow !== null && $scheduleCursor !== null && scheduleWindowIsValid($scheduleWindow)) {
            $cursor = clone $scheduleCursor;
            applyDurationScheduleToFixtures($fixtures, $sport, $scheduleWindow, $cursor);
        }
    }

    if (empty($fixtures)) {
        return ['created' => 0, 'format' => $format, 'error' => 'Select at least 2 teams to generate matches.'];
    }

    if ($replaceUnscheduled) {
        // Remove only generated matches that have no score yet and are not ongoing/completed
        $db->prepare("DELETE FROM intramural_matches
            WHERE season_id = ? AND sport_id = ? AND is_generated = 1
              AND status IN ('scheduled', 'cancelled')
              AND score_a IS NULL AND score_b IS NULL")
            ->execute([$seasonId, $sportId]);
    }

    $defaultVenue = trim((string) ($sport['venue'] ?? '')) ?: null;

    $insert = $db->prepare('INSERT INTO intramural_matches
        (season_id, sport_id, round_number, round_label, match_order, is_generated, team_a_id, team_b_id, scheduled_at, venue, status, notes, created_by)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, \'scheduled\', ?, ?)');

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
            $f['venue'] ?? $defaultVenue,
            $f['notes'],
            $createdBy,
        ]);
        $created++;
    }

    return ['created' => $created, 'format' => $format];
}

/**
 * Count generated matches for the active season (optionally one event).
 */
function countGeneratedMatches(int $seasonId, ?int $sportId = null, bool $includeCompleted = false): int
{
    $db = getDB();
    $sql = 'SELECT COUNT(*) FROM intramural_matches WHERE season_id = ? AND is_generated = 1';
    $params = [$seasonId];

    if ($sportId) {
        $sql .= ' AND sport_id = ?';
        $params[] = $sportId;
    }

    if (!$includeCompleted) {
        $sql .= " AND status IN ('scheduled', 'cancelled') AND score_a IS NULL AND score_b IS NULL";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Delete generated fixtures (admin). Manual matches (is_generated = 0) are never removed.
 *
 * @return array{deleted: int, scope: string}
 */
function deleteGeneratedMatches(int $seasonId, ?int $sportId = null, bool $includeCompleted = false): array
{
    $db = getDB();
    $sql = 'DELETE FROM intramural_matches WHERE season_id = ? AND is_generated = 1';
    $params = [$seasonId];

    if ($sportId) {
        $sql .= ' AND sport_id = ?';
        $params[] = $sportId;
    }

    if (!$includeCompleted) {
        $sql .= " AND status IN ('scheduled', 'cancelled') AND score_a IS NULL AND score_b IS NULL";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return [
        'deleted' => $stmt->rowCount(),
        'scope' => $includeCompleted ? 'all_generated' : 'unplayed_generated',
    ];
}

/** Delete one generated match by id (admin). */
function deleteGeneratedMatchById(int $matchId): bool
{
    $db = getDB();
    $stmt = $db->prepare('DELETE FROM intramural_matches WHERE id = ? AND is_generated = 1');
    $stmt->execute([$matchId]);
    return $stmt->rowCount() > 0;
}

/** Count all matches for a season (optionally one event). */
function countSeasonMatches(int $seasonId, ?int $sportId = null): int
{
    $db = getDB();
    $sql = 'SELECT COUNT(*) FROM intramural_matches WHERE season_id = ?';
    $params = [$seasonId];

    if ($sportId) {
        $sql .= ' AND sport_id = ?';
        $params[] = $sportId;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Delete all matches for a season (generated and manual).
 *
 * @return array{deleted: int, scope: string}
 */
function deleteAllMatches(int $seasonId, ?int $sportId = null): array
{
    $db = getDB();
    $sql = 'DELETE FROM intramural_matches WHERE season_id = ?';
    $params = [$seasonId];

    if ($sportId) {
        $sql .= ' AND sport_id = ?';
        $params[] = $sportId;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return [
        'deleted' => $stmt->rowCount(),
        'scope' => 'all_matches',
    ];
}

/** Delete any match by id (admin). */
function deleteMatchById(int $matchId): bool
{
    $db = getDB();
    $stmt = $db->prepare('DELETE FROM intramural_matches WHERE id = ?');
    $stmt->execute([$matchId]);
    return $stmt->rowCount() > 0;
}

/**
 * Generate fixtures for multiple sports in one pass.
 *
 * @param list<int> $sportIds
 * @param list<int>|null $sharedTeamIds  When set, used for every sport. When null, teams come from season registrations per sport.
 * @param array<int, list<int>>|null $teamsBySport  Per-sport team IDs (sport_id => team ids)
 * @param array{
 *   start_date?:?string,
 *   end_date?:?string,
 *   start_date_start_hour?:int,
 *   start_date_end_hour?:int,
 *   end_date_start_hour?:int,
 *   end_date_end_hour?:int,
 *   daily_start_hour?:int,
 *   daily_end_hour?:int,
 *   start_hour?:int,
 *   end_hour?:int,
 *   smart_venue?:bool,
 *   boards_by_sport?:array<int,int>
 * }|null $scheduleOptions Custom auto-schedule window (optional)
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
    $startDate = $scheduleOptions['start_date'] ?? ($season['start_date'] ?? null);
    $endDate = $scheduleOptions['end_date'] ?? ($season['end_date'] ?? null);
    $smartVenue = !empty($scheduleOptions['smart_venue']);
    $scheduleWindow = buildScheduleWindow($startDate, $endDate, $scheduleOptions ?? []);
    $scheduleCursor = getScheduleResumeCursor($seasonId, $scheduleWindow);

    $regTeamsStmt = $db->prepare('SELECT DISTINCT team_id FROM intramural_registrations WHERE sport_id = ? AND season_id = ?');
    $sportsById = [];
    $teamIdsBySport = [];
    $fixturesBySport = [];

    foreach ($sportIds as $sportId) {
        $sportStmt = $db->prepare('SELECT * FROM intramural_sports WHERE id = ?');
        $sportStmt->execute([$sportId]);
        $sport = $sportStmt->fetch();
        if (!$sport) {
            $errors[] = 'Sport #' . $sportId . ': not found.';
            continue;
        }
        $sportsById[$sportId] = $sport;

        if ($teamsBySport !== null && isset($teamsBySport[$sportId])) {
            $teamIds = array_values(array_unique(array_map('intval', $teamsBySport[$sportId])));
        } elseif ($sharedTeamIds !== null) {
            $teamIds = $sharedTeamIds;
        } else {
            $regTeamsStmt->execute([$sportId, $seasonId]);
            $teamIds = array_map('intval', $regTeamsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }
        $teamIdsBySport[$sportId] = $teamIds;

        if (count($teamIds) < 2) {
            $errors[] = sportLabel($sport) . ': select at least 2 teams.';
            continue;
        }

        $format = $sport['tournament_format'] ?? 'round_robin';
        if ($format === 'rank_first_to_last' && count($teamIds) % 2 !== 0) {
            $errors[] = sportLabel($sport) . ': Rank from First to Last requires an even number of teams.';
            continue;
        }

        $fixtures = buildTournamentFixtures($format, $teamIds);
        if (empty($fixtures)) {
            $errors[] = sportLabel($sport) . ': no fixtures to generate.';
            continue;
        }

        $boardsBySport = $scheduleOptions['boards_by_sport'] ?? [];
        if (isChessSport((string) ($sport['name'] ?? ''))) {
            $boards = (int) ($boardsBySport[$sportId] ?? defaultChessBoardsPerTeam($sport));
            $fixtures = expandChessBoardFixtures($fixtures, $boards);
        }

        $fixturesBySport[$sportId] = $fixtures;
    }

    if ($smartVenue && scheduleWindowIsValid($scheduleWindow) && $fixturesBySport) {
        $totalScheduled = applySmartVenueSchedule($fixturesBySport, $sportsById, $scheduleWindow, $seasonId, $scheduleCursor);
    } elseif (scheduleWindowIsValid($scheduleWindow) && $fixturesBySport) {
        if (!$scheduleCursor) {
            $scheduleCursor = createScheduleCursor($scheduleWindow);
        }
        if ($scheduleCursor) {
            foreach ($fixturesBySport as $sportId => &$fixtures) {
                $totalScheduled += applyDurationScheduleToFixtures(
                    $fixtures,
                    $sportsById[$sportId] ?? [],
                    $scheduleWindow,
                    $scheduleCursor
                );
            }
            unset($fixtures);
        }
    }

    $unscheduledCount = 0;
    foreach ($fixturesBySport as $fixtures) {
        foreach ($fixtures as $fixture) {
            if (empty($fixture['scheduled_at'])) {
                $unscheduledCount++;
            }
        }
    }
    if ($unscheduledCount > 0 && scheduleWindowIsValid($scheduleWindow)) {
        $errors[] = $unscheduledCount . ' match(es) could not be auto-scheduled within '
            . formatScheduleWindowSummary($scheduleWindow)
            . '. Widen the date range or hours, shorten game durations, or set times manually after generating.';
    }

    foreach ($fixturesBySport as $sportId => $fixtures) {
        $sport = $sportsById[$sportId];
        $result = generateMatchesForSport(
            $sportId,
            $seasonId,
            $teamIdsBySport[$sportId],
            $createdBy,
            $replaceUnscheduled,
            null,
            null,
            $fixtures
        );

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
        'smart_venue' => $smartVenue,
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

function isRosterLocked(?int $seasonId = null): bool
{
    $status = getRosterLockStatus($seasonId);
    return $status['is_locked'];
}

/**
 * @return array{
 *   is_locked: bool,
 *   is_scheduled: bool,
 *   lock_date: ?string,
 *   locked_at: ?string,
 *   locked_by: ?int,
 *   locked_by_name: ?string,
 *   season_id: ?int,
 *   year_label: ?string,
 *   season_name: ?string
 * }
 */
function getRosterLockStatus(?int $seasonId = null): array
{
    ensureRosterLockColumns();
    applyScheduledRosterLocks();

    $empty = [
        'is_locked' => false,
        'is_scheduled' => false,
        'lock_date' => null,
        'locked_at' => null,
        'locked_by' => null,
        'locked_by_name' => null,
        'season_id' => null,
        'year_label' => null,
        'season_name' => null,
    ];

    $season = $seasonId ? getSeasonById($seasonId) : getCurrentSeason();
    if (!$season) {
        return $empty;
    }

    $lockDate = !empty($season['roster_lock_date']) ? (string) $season['roster_lock_date'] : null;
    $isLocked = !empty($season['roster_locked']);
    $today = date('Y-m-d');
    $isScheduled = !$isLocked && $lockDate !== null && $lockDate > $today;

    $status = [
        'is_locked' => $isLocked,
        'is_scheduled' => $isScheduled,
        'lock_date' => $lockDate,
        'locked_at' => $season['roster_locked_at'] ?? null,
        'locked_by' => !empty($season['roster_locked_by']) ? (int) $season['roster_locked_by'] : null,
        'locked_by_name' => null,
        'season_id' => (int) $season['id'],
        'year_label' => $season['year_label'],
        'season_name' => $season['name'],
    ];

    if ($status['locked_by']) {
        $db = getDB();
        $stmt = $db->prepare('SELECT first_name, last_name, username FROM users WHERE id = ?');
        $stmt->execute([$status['locked_by']]);
        $user = $stmt->fetch();
        if ($user) {
            $status['locked_by_name'] = trim($user['first_name'] . ' ' . $user['last_name']) ?: $user['username'];
        }
    }

    return $status;
}

function getRosterLockInfo(?int $seasonId = null): ?array
{
    $status = getRosterLockStatus($seasonId);
    if (!$status['is_locked']) {
        return null;
    }

    return [
        'season_id' => $status['season_id'],
        'year_label' => $status['year_label'],
        'season_name' => $status['season_name'],
        'lock_date' => $status['lock_date'],
        'locked_at' => $status['locked_at'],
        'locked_by' => $status['locked_by'],
        'locked_by_name' => $status['locked_by_name'],
    ];
}

function applyScheduledRosterLocks(): void
{
    ensureRosterLockColumns();
    $db = getDB();
    $db->exec("UPDATE intramural_seasons
        SET roster_locked = 1,
            roster_locked_at = COALESCE(roster_locked_at, CONCAT(roster_lock_date, ' 00:00:00'))
        WHERE roster_lock_date IS NOT NULL
          AND roster_lock_date <= CURDATE()
          AND roster_locked = 0");
}

function requireUnlockedRoster(?int $seasonId = null): void
{
    if (isRosterLocked($seasonId)) {
        flash('error', 'The athlete roster is locked for this season. Contact the administrator to unlock it before making changes.');
        redirect(BASE_URL . '/intramurals/roster/index.php');
    }
}

function lockSeasonRoster(int $seasonId, int $userId, ?string $lockDate = null): bool
{
    $lockDate = $lockDate ?: date('Y-m-d');
    return setSeasonRosterLockDate($seasonId, $userId, $lockDate);
}

function setSeasonRosterLockDate(int $seasonId, int $userId, string $lockDate): bool
{
    ensureRosterLockColumns();
    if (!getSeasonById($seasonId)) {
        return false;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lockDate)) {
        return false;
    }

    $today = date('Y-m-d');
    $db = getDB();

    if ($lockDate <= $today) {
        $stmt = $db->prepare('UPDATE intramural_seasons SET roster_locked = 1, roster_lock_date = ?, roster_locked_at = NOW(), roster_locked_by = ? WHERE id = ?');
        $stmt->execute([$lockDate, $userId, $seasonId]);
    } else {
        $stmt = $db->prepare('UPDATE intramural_seasons SET roster_locked = 0, roster_lock_date = ?, roster_locked_at = NULL, roster_locked_by = ? WHERE id = ?');
        $stmt->execute([$lockDate, $userId, $seasonId]);
    }

    return $stmt->rowCount() > 0;
}

function unlockSeasonRoster(int $seasonId): bool
{
    ensureRosterLockColumns();
    if (!getSeasonById($seasonId)) {
        return false;
    }

    $db = getDB();
    $stmt = $db->prepare('UPDATE intramural_seasons SET roster_locked = 0, roster_lock_date = NULL, roster_locked_at = NULL, roster_locked_by = NULL WHERE id = ?');
    $stmt->execute([$seasonId]);
    return $stmt->rowCount() > 0;
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

/**
 * Winner / loser for a finished match.
 *
 * @return array{winner:?int,loser:?int}|null
 */
function getMatchOutcome(array $match): ?array
{
    $teamA = (int) ($match['team_a_id'] ?? 0);
    $teamB = (int) ($match['team_b_id'] ?? 0);
    if ($teamA <= 0 || $teamB <= 0) {
        return null;
    }

    $status = (string) ($match['status'] ?? '');
    $winner = !empty($match['winner_team_id'])
        ? (int) $match['winner_team_id']
        : determineMatchWinner(
            $match['score_a'] !== null ? (int) $match['score_a'] : null,
            $match['score_b'] !== null ? (int) $match['score_b'] : null,
            $teamA,
            $teamB,
            $status,
            !empty($match['forfeit_team_id']) ? (int) $match['forfeit_team_id'] : null
        );

    if (!$winner) {
        return null;
    }

    return [
        'winner' => $winner,
        'loser' => $winner === $teamA ? $teamB : $teamA,
    ];
}

function isConsolationMatch(array $match): bool
{
    $label = strtolower((string) ($match['round_label'] ?? ''));
    return str_contains($label, 'consolation');
}

function isChampionshipFinalMatch(array $match): bool
{
    $label = strtolower(trim((string) ($match['round_label'] ?? '')));
    $base = trim(preg_replace('/\s*—\s*SDS.*$/i', '', $label) ?? $label);
    return ($base === 'final' || str_starts_with($label, 'final')) && !isConsolationMatch($match);
}

/**
 * Whether a generated match slot can still receive bracket teams.
 */
function canUpdateBracketTeams(array $match): bool
{
    if (empty($match['is_generated'])) {
        return false;
    }
    $status = (string) ($match['status'] ?? 'scheduled');
    if (in_array($status, ['completed', 'forfeit', 'ongoing'], true)) {
        return false;
    }
    if ($match['score_a'] !== null || $match['score_b'] !== null) {
        return false;
    }
    return true;
}

/**
 * Assign team into team_a or team_b of a TBD/generated match.
 *
 * @return bool true when a change was written
 */
function assignTeamToBracketSlot(PDO $db, array &$match, int $teamId, string $side): bool
{
    if ($teamId <= 0 || !canUpdateBracketTeams($match)) {
        return false;
    }

    $field = $side === 'b' ? 'team_b_id' : 'team_a_id';
    $current = (int) ($match[$field] ?? 0);
    if ($current === $teamId) {
        return false;
    }

    // Avoid putting the same team on both sides
    $otherField = $field === 'team_a_id' ? 'team_b_id' : 'team_a_id';
    if ((int) ($match[$otherField] ?? 0) === $teamId) {
        return false;
    }

    $db->prepare("UPDATE intramural_matches SET {$field} = ? WHERE id = ?")->execute([$teamId, (int) $match['id']]);
    $match[$field] = $teamId;
    return true;
}

/**
 * Fill next-round slots from ordered previous-round winners (classic bracket pairing).
 *
 * @param list<array> $sourceMatches
 * @param list<array> $targetMatches
 * @return int updates count
 */
function fillNextRoundFromWinners(PDO $db, array $sourceMatches, array &$targetMatches, string $use = 'winner'): int
{
    $outcomes = [];
    foreach ($sourceMatches as $m) {
        $out = getMatchOutcome($m);
        if (!$out || empty($out[$use])) {
            return 0; // wait until the whole prior round is decided
        }
        $outcomes[] = (int) $out[$use];
    }

    $needed = count($targetMatches) * 2;
    if (count($outcomes) < $needed) {
        return 0;
    }

    $updated = 0;
    foreach ($targetMatches as $i => &$target) {
        $a = $outcomes[$i * 2] ?? 0;
        $b = $outcomes[$i * 2 + 1] ?? 0;
        if ($a && assignTeamToBracketSlot($db, $target, $a, 'a')) {
            $updated++;
        }
        if ($b && assignTeamToBracketSlot($db, $target, $b, 'b')) {
            $updated++;
        }
    }
    unset($target);

    return $updated;
}

/**
 * Collapse SDS rubber triples into virtual ties with a team winner/loser.
 *
 * @param list<array> $matches
 * @return list<array{winner:int,loser:int,matches:list<array>}>
 */
function collapseSdsTies(array $matches): array
{
    $ties = [];
    $buffer = [];

    $flush = static function () use (&$ties, &$buffer): void {
        if (count($buffer) < 1) {
            return;
        }
        $wins = [];
        foreach ($buffer as $m) {
            $out = getMatchOutcome($m);
            if (!$out) {
                $buffer = [];
                return;
            }
            $w = (int) $out['winner'];
            $wins[$w] = ($wins[$w] ?? 0) + 1;
        }
        arsort($wins);
        $winner = (int) array_key_first($wins);
        $teamA = (int) ($buffer[0]['team_a_id'] ?? 0);
        $teamB = (int) ($buffer[0]['team_b_id'] ?? 0);
        $loser = $winner === $teamA ? $teamB : $teamA;
        if ($winner > 0 && $loser > 0) {
            $ties[] = ['winner' => $winner, 'loser' => $loser, 'matches' => $buffer];
        }
        $buffer = [];
    };

    foreach ($matches as $m) {
        $label = (string) ($m['round_label'] ?? '');
        if (!str_contains($label, 'SDS')) {
            $flush();
            continue;
        }
        if ($buffer && (
            (int) ($buffer[0]['team_a_id'] ?? 0) !== (int) ($m['team_a_id'] ?? 0)
            || (int) ($buffer[0]['team_b_id'] ?? 0) !== (int) ($m['team_b_id'] ?? 0)
            || preg_replace('/\s*—\s*SDS.*/', '', (string) ($buffer[0]['round_label'] ?? ''))
                !== preg_replace('/\s*—\s*SDS.*/', '', $label)
        )) {
            $flush();
        }
        $buffer[] = $m;
        if (count($buffer) >= 3) {
            $flush();
        }
    }
    $flush();

    return $ties;
}

/**
 * Update generated TBD matches from completed previous-round results.
 *
 * @return array{updated:int,details:list<string>,error?:string}
 */
function advanceBracketFromResults(int $sportId, int $seasonId): array
{
    $db = getDB();
    $sportStmt = $db->prepare('SELECT * FROM intramural_sports WHERE id = ?');
    $sportStmt->execute([$sportId]);
    $sport = $sportStmt->fetch();
    if (!$sport) {
        return ['updated' => 0, 'details' => [], 'error' => 'Sport not found.'];
    }

    $format = (string) ($sport['tournament_format'] ?? 'round_robin');
    if (!isBracketTournamentFormat($format)) {
        return [
            'updated' => 0,
            'details' => [],
            'error' => 'Bracket update applies to elimination / SDS formats only.',
        ];
    }

    $stmt = $db->prepare('SELECT * FROM intramural_matches
        WHERE season_id = ? AND sport_id = ? AND is_generated = 1
        ORDER BY match_order ASC, id ASC');
    $stmt->execute([$seasonId, $sportId]);
    $matches = $stmt->fetchAll();
    if (!$matches) {
        return ['updated' => 0, 'details' => ['No generated matches found.']];
    }

    $updated = 0;
    $details = [];

    if ($format === 'team_play_sds') {
        $updated += advanceSdsBracket($db, $matches, $details);
        return ['updated' => $updated, 'details' => $details];
    }
    if ($format === 'team_play_sds_consolation') {
        $updated += advanceSdsConsolationBracket($db, $matches, $details);
        return ['updated' => $updated, 'details' => $details];
    }

    $championship = [];
    $consolation = [];
    foreach ($matches as $m) {
        if (isConsolationMatch($m)) {
            $consolation[] = $m;
        } else {
            $championship[] = $m;
        }
    }

    // Group championship matches by round label (preserving match_order)
    $champRounds = [];
    foreach ($championship as $m) {
        $label = (string) ($m['round_label'] ?: ('Round ' . (int) $m['round_number']));
        if (!isset($champRounds[$label])) {
            $champRounds[$label] = [];
        }
        $champRounds[$label][] = $m;
    }
    $champLabels = array_keys($champRounds);

    for ($i = 0; $i < count($champLabels) - 1; $i++) {
        $srcLabel = $champLabels[$i];
        $dstLabel = $champLabels[$i + 1];
        // Skip pairing into Final from non-semi when consolation exists between — labels are already championship-only
        $src = $champRounds[$srcLabel];
        $dst = $champRounds[$dstLabel];
        $count = fillNextRoundFromWinners($db, $src, $dst, 'winner');
        if ($count > 0) {
            $updated += $count;
            $details[] = "Advanced {$count} team slot(s) from {$srcLabel} → {$dstLabel}.";
            $champRounds[$dstLabel] = $dst;
        }
    }

    // 3rd-place game: losers of championship Semi-finals
    if ($format === 'single_elimination_consolation') {
        $semiLabel = null;
        foreach ($champLabels as $label) {
            if (stripos($label, 'semi') !== false) {
                $semiLabel = $label;
                break;
            }
        }
        $thirdIdx = null;
        foreach ($consolation as $idx => $cm) {
            if (stripos((string) $cm['round_label'], '3rd') !== false) {
                $thirdIdx = $idx;
                break;
            }
        }

        if ($semiLabel && $thirdIdx !== null) {
            $semis = $champRounds[$semiLabel];
            if (count($semis) >= 2) {
                $losers = [];
                foreach (array_slice($semis, 0, 2) as $semi) {
                    $out = getMatchOutcome($semi);
                    if ($out) {
                        $losers[] = (int) $out['loser'];
                    }
                }
                if (count($losers) === 2) {
                    $before = $updated;
                    if (assignTeamToBracketSlot($db, $consolation[$thirdIdx], $losers[0], 'a')) {
                        $updated++;
                    }
                    if (assignTeamToBracketSlot($db, $consolation[$thirdIdx], $losers[1], 'b')) {
                        $updated++;
                    }
                    if ($updated > $before) {
                        $details[] = 'Filled Consolation Final (3rd Place) from semi-final losers.';
                    }
                }
            }
        }

        // First-round losers → first consolation round (excluding 3rd place)
        $consolationRounds = [];
        foreach ($consolation as $cm) {
            if (stripos((string) $cm['round_label'], '3rd') !== false) {
                continue;
            }
            $label = (string) ($cm['round_label'] ?: ('Consolation ' . (int) $cm['round_number']));
            if (!isset($consolationRounds[$label])) {
                $consolationRounds[$label] = [];
            }
            $consolationRounds[$label][] = $cm;
        }
        $consolationLabels = array_keys($consolationRounds);

        if (!empty($champLabels) && !empty($consolationLabels)) {
            $firstChamp = $champRounds[$champLabels[0]];
            $firstConsolation = $consolationRounds[$consolationLabels[0]];
            $count = fillNextRoundFromWinners($db, $firstChamp, $firstConsolation, 'loser');
            if ($count > 0) {
                $updated += $count;
                $details[] = "Filled {$count} consolation slot(s) from first-round losers.";
                $consolationRounds[$consolationLabels[0]] = $firstConsolation;
            }
        }

        for ($i = 0; $i < count($consolationLabels) - 1; $i++) {
            $srcLabel = $consolationLabels[$i];
            $dstLabel = $consolationLabels[$i + 1];
            $src = $consolationRounds[$srcLabel];
            $dst = $consolationRounds[$dstLabel];
            $count = fillNextRoundFromWinners($db, $src, $dst, 'winner');
            if ($count > 0) {
                $updated += $count;
                $details[] = "Advanced {$count} consolation slot(s) from {$srcLabel} → {$dstLabel}.";
                $consolationRounds[$dstLabel] = $dst;
            }
        }
    }

    if ($updated === 0 && empty($details)) {
        $details[] = 'No TBD slots were ready to update. Complete previous round matches first.';
    }

    return ['updated' => $updated, 'details' => $details];
}

/**
 * Group SDS matches by championship/consolation round label (strip SDS suffix).
 *
 * @param list<array> $matches
 * @return array<string, list<array>>
 */
function groupSdsRoundsByBaseLabel(array $matches): array
{
    $rounds = [];
    foreach ($matches as $m) {
        $base = trim(preg_replace('/\s*—\s*SDS.*$/i', '', (string) ($m['round_label'] ?? '')));
        if ($base === '') {
            $base = 'Round ' . (int) ($m['round_number'] ?? 1);
        }
        if (!isset($rounds[$base])) {
            $rounds[$base] = [];
        }
        $rounds[$base][] = $m;
    }

    return $rounds;
}

/**
 * Group destination SDS rubbers into tie shells (3 legs each).
 *
 * @param list<array> $dstMatches
 * @return list<array{anchor: array, all: list<array>}>
 */
function groupSdsTieShells(array $dstMatches): array
{
    $dstTies = [];
    foreach ($dstMatches as $m) {
        $label = (string) ($m['round_label'] ?? '');
        if (!preg_match('/SDS Singles 1/i', $label)) {
            continue;
        }
        $dstTies[] = ['anchor' => $m, 'all' => []];
    }

    if (empty($dstTies)) {
        foreach (array_chunk($dstMatches, 3) as $chunk) {
            if ($chunk) {
                $dstTies[] = ['anchor' => $chunk[0], 'all' => $chunk];
            }
        }
        return $dstTies;
    }

    foreach ($dstTies as &$tie) {
        $anchorOrder = (int) $tie['anchor']['match_order'];
        $tie['all'] = array_values(array_filter($dstMatches, static function ($m) use ($anchorOrder) {
            $mo = (int) $m['match_order'];
            return $mo >= $anchorOrder && $mo <= $anchorOrder + 2;
        }));
    }
    unset($tie);

    return $dstTies;
}

/**
 * Fill SDS rubber shells from ordered team pairs.
 *
 * @param list<array> $dstMatches
 * @param list<array{0:int,1:int}> $teamPairs
 */
function fillSdsTiesFromTeams(PDO $db, array $dstMatches, array $teamPairs): int
{
    $dstTies = groupSdsTieShells($dstMatches);
    $updated = 0;
    foreach ($dstTies as $ti => $dstTie) {
        $pair = $teamPairs[$ti] ?? null;
        if (!$pair) {
            continue;
        }
        $a = (int) ($pair[0] ?? 0);
        $b = (int) ($pair[1] ?? 0);
        foreach ($dstTie['all'] as $slot) {
            $row = $slot;
            if ($a && assignTeamToBracketSlot($db, $row, $a, 'a')) {
                $updated++;
            }
            if ($b && assignTeamToBracketSlot($db, $row, $b, 'b')) {
                $updated++;
            }
        }
    }

    return $updated;
}

/**
 * Advance SDS single-elim ties (best of 3 rubbers) into later TBD ties.
 *
 * @param list<array> $matches
 * @param list<string> $details
 */
function advanceSdsBracket(PDO $db, array $matches, array &$details, bool $quiet = false): int
{
    $rounds = groupSdsRoundsByBaseLabel($matches);
    $labels = array_keys($rounds);
    $updated = 0;

    for ($i = 0; $i < count($labels) - 1; $i++) {
        $srcTies = collapseSdsTies($rounds[$labels[$i]]);
        if (empty($srcTies)) {
            continue;
        }

        $winners = array_map(static fn($t) => (int) $t['winner'], $srcTies);
        $dstTies = groupSdsTieShells($rounds[$labels[$i + 1]]);
        $needed = count($dstTies) * 2;
        if ($needed < 1 || count($winners) < $needed) {
            continue;
        }

        $pairs = [];
        for ($p = 0; $p < count($dstTies); $p++) {
            $pairs[] = [$winners[$p * 2] ?? 0, $winners[$p * 2 + 1] ?? 0];
        }
        $count = fillSdsTiesFromTeams($db, $rounds[$labels[$i + 1]], $pairs);
        if ($count > 0) {
            $updated += $count;
            $details[] = 'Advanced SDS winners from ' . $labels[$i] . ' → ' . $labels[$i + 1] . '.';
        }
    }

    if ($updated === 0 && !$quiet) {
        $details[] = 'No SDS TBD ties were ready to update.';
    }

    return $updated;
}

/**
 * Advance SDS championship + consolation ties (3rd place and 5th–8th).
 *
 * @param list<array> $matches
 * @param list<string> $details
 */
function advanceSdsConsolationBracket(PDO $db, array $matches, array &$details): int
{
    $championship = [];
    $consolation = [];
    foreach ($matches as $m) {
        if (isConsolationMatch($m)) {
            $consolation[] = $m;
        } else {
            $championship[] = $m;
        }
    }

    $updated = advanceSdsBracket($db, $championship, $details, true);
    $champRounds = groupSdsRoundsByBaseLabel($championship);
    $champLabels = array_keys($champRounds);

    $semiLabel = null;
    foreach ($champLabels as $label) {
        if (stripos($label, 'semi') !== false) {
            $semiLabel = $label;
            break;
        }
    }

    $thirdMatches = [];
    $consolationPlain = [];
    foreach ($consolation as $cm) {
        if (stripos((string) $cm['round_label'], '3rd') !== false) {
            $thirdMatches[] = $cm;
        } else {
            $consolationPlain[] = $cm;
        }
    }

    if ($semiLabel && $thirdMatches) {
        $semiTies = collapseSdsTies($champRounds[$semiLabel]);
        if (count($semiTies) >= 2) {
            $count = fillSdsTiesFromTeams($db, $thirdMatches, [[
                (int) $semiTies[0]['loser'],
                (int) $semiTies[1]['loser'],
            ]]);
            if ($count > 0) {
                $updated += $count;
                $details[] = 'Filled Consolation Final (3rd Place) SDS from semi-final losers.';
            }
        }
    }

    $consolationRounds = groupSdsRoundsByBaseLabel($consolationPlain);
    $consolationLabels = array_keys($consolationRounds);

    if (!empty($champLabels) && !empty($consolationLabels)) {
        $firstChampTies = collapseSdsTies($champRounds[$champLabels[0]]);
        $firstConsolation = $consolationRounds[$consolationLabels[0]];
        $neededLosers = count(groupSdsTieShells($firstConsolation)) * 2;
        if ($neededLosers > 0 && count($firstChampTies) >= $neededLosers) {
            $pairs = [];
            for ($i = 0; $i < $neededLosers; $i += 2) {
                $pairs[] = [
                    (int) $firstChampTies[$i]['loser'],
                    (int) ($firstChampTies[$i + 1]['loser'] ?? 0),
                ];
            }
            $count = fillSdsTiesFromTeams($db, $firstConsolation, $pairs);
            if ($count > 0) {
                $updated += $count;
                $details[] = "Filled {$count} consolation SDS slot(s) from first-round losers.";
            }
        }
    }

    $updated += advanceSdsBracket($db, $consolationPlain, $details, true);

    if ($updated === 0) {
        $details[] = 'No SDS TBD ties were ready to update.';
    }

    return $updated;
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
        $stats['total_sports'] = (int) $db->query('SELECT COUNT(*) FROM intramural_sports')->fetchColumn();

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

/** Recent completed matches for the main dashboard (scoped for tournament managers). */
function getDashboardRecentMatchResults(int $limit = 8, ?int $seasonId = null): array
{
    $db = getDB();
    $seasonId = $seasonId ?? getCurrentSeasonId();
    if (!$seasonId) {
        return [];
    }

    $where = ['m.season_id = ?', "m.status IN ('completed', 'forfeit')"];
    $params = [$seasonId];

    if (isTournamentManager() && !canManageIntramurals()) {
        $tmSportIds = getTmSportIds();
        if (empty($tmSportIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($tmSportIds), '?'));
        $where[] = "m.sport_id IN ($placeholders)";
        $params = array_merge($params, $tmSportIds);
    }

    $whereClause = implode(' AND ', $where);
    $stmt = $db->prepare("
        SELECT m.*, s.name AS sport_name, s.category AS sport_category,
               ta.name AS team_a_name, ta.color AS team_a_color,
               tb.name AS team_b_name, tb.color AS team_b_color
        FROM intramural_matches m
        JOIN intramural_sports s ON m.sport_id = s.id
        LEFT JOIN intramural_teams ta ON m.team_a_id = ta.id
        LEFT JOIN intramural_teams tb ON m.team_b_id = tb.id
        WHERE $whereClause
        ORDER BY m.updated_at DESC, m.scheduled_at DESC
        LIMIT " . (int) $limit . "
    ");
    $stmt->execute($params);

    return $stmt->fetchAll();
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

/** Manual per-event team ranks that feed medals and overall standing. */
function ensureEventRanksTable(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(['intramural_event_ranks']);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $db->exec("CREATE TABLE intramural_event_ranks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        season_id INT NOT NULL,
        sport_id INT NOT NULL,
        team_id INT NOT NULL,
        place_rank INT NOT NULL,
        notes VARCHAR(255) DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_event_rank_team (season_id, sport_id, team_id),
        UNIQUE KEY uq_event_rank_place (season_id, sport_id, place_rank),
        FOREIGN KEY (season_id) REFERENCES intramural_seasons(id) ON DELETE CASCADE,
        FOREIGN KEY (sport_id) REFERENCES intramural_sports(id) ON DELETE CASCADE,
        FOREIGN KEY (team_id) REFERENCES intramural_teams(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
}

/**
 * @return array<int, array{team_id:int,place_rank:int,notes:?string}>
 */
function getEventRanks(int $sportId, int $seasonId): array
{
    ensureEventRanksTable();
    if ($sportId <= 0 || $seasonId <= 0) {
        return [];
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT team_id, place_rank, notes FROM intramural_event_ranks
        WHERE sport_id = ? AND season_id = ? ORDER BY place_rank ASC');
    $stmt->execute([$sportId, $seasonId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['team_id']] = [
            'team_id' => (int) $row['team_id'],
            'place_rank' => (int) $row['place_rank'],
            'notes' => $row['notes'] ?? null,
        ];
    }

    return $out;
}

function eventHasManualRanks(int $sportId, int $seasonId): bool
{
    return getEventRanks($sportId, $seasonId) !== [];
}

/**
 * Whether the event already has a match schedule (any non-cancelled fixture).
 * Those events use match results for placement instead of manual ranks.
 */
function eventHasScheduledMatches(int $sportId, int $seasonId): bool
{
    if ($sportId <= 0 || $seasonId <= 0) {
        return false;
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM intramural_matches
        WHERE sport_id = ? AND season_id = ? AND status <> 'cancelled'");
    $stmt->execute([$sportId, $seasonId]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Team IDs that belong in the event ranking UI (roster first, else all active teams).
 *
 * @return list<int>
 */
function getEventParticipatingTeamIds(int $sportId, int $seasonId): array
{
    $db = getDB();
    $ids = [];
    if ($seasonId > 0 && $sportId > 0) {
        $stmt = $db->prepare('SELECT DISTINCT team_id FROM intramural_registrations WHERE sport_id = ? AND season_id = ? ORDER BY team_id');
        $stmt->execute([$sportId, $seasonId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
    foreach (getEventRanks($sportId, $seasonId) as $tid => $_row) {
        if (!in_array((int) $tid, $ids, true)) {
            $ids[] = (int) $tid;
        }
    }

    return array_values(array_filter($ids, static fn($id) => $id > 0));
}

/**
 * Save official event ranks. Empty/zero rank removes that team. Duplicate places are rejected.
 *
 * @param array<int, int> $ranksByTeam team_id => place_rank (0 = clear)
 * @return list<string> validation errors
 */
function saveEventRanks(int $sportId, int $seasonId, array $ranksByTeam, ?int $userId = null): array
{
    ensureEventRanksTable();
    $errors = [];
    if ($sportId <= 0 || $seasonId <= 0) {
        return ['Select a season and event before saving ranks.'];
    }
    if (eventHasScheduledMatches($sportId, $seasonId)) {
        return ['Manual ranking is not allowed for events that already have scheduled matches. Placement follows match results.'];
    }

    $clean = [];
    $usedPlaces = [];
    foreach ($ranksByTeam as $teamId => $place) {
        $teamId = (int) $teamId;
        $place = (int) $place;
        if ($teamId <= 0 || $place <= 0) {
            continue;
        }
        if ($place > 99) {
            $errors[] = 'Rank must be between 1 and 99.';
            continue;
        }
        if (isset($usedPlaces[$place])) {
            $errors[] = 'Each place can only be assigned to one team (duplicate rank ' . $place . ').';
            continue;
        }
        $usedPlaces[$place] = $teamId;
        $clean[$teamId] = $place;
    }

    if ($errors) {
        return $errors;
    }

    $db = getDB();
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM intramural_event_ranks WHERE sport_id = ? AND season_id = ?')
            ->execute([$sportId, $seasonId]);
        $insert = $db->prepare('INSERT INTO intramural_event_ranks (season_id, sport_id, team_id, place_rank, created_by) VALUES (?, ?, ?, ?, ?)');
        foreach ($clean as $teamId => $place) {
            $insert->execute([$seasonId, $sportId, $teamId, $place, $userId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        return ['Could not save event ranks. ' . $e->getMessage()];
    }

    return [];
}

function medalForRank(int $rank): ?string
{
    if ($rank === 1) {
        return 'gold';
    }
    if ($rank === 2) {
        return 'silver';
    }
    if ($rank === 3) {
        return 'bronze';
    }

    return null;
}

/**
 * Apply Champion / medal / event points onto a standings row.
 */
function applyEventPlacement(array &$row, int $rank, array $scheme, bool $manual = false): void
{
    $row['rank'] = $rank;
    $row['manual_rank'] = $manual;
    $row['medal'] = medalForRank($rank);
    $row['placement_points'] = getPlacementPoints($scheme, $rank);
    $row['placement_label'] = $rank <= 6 ? getPlacementLabel($rank) : ('Rank ' . $rank);
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
                WHERE s.id = ?');
            $sports->execute([$sportId]);
            $sports = $sports->fetchAll();
        } else {
            $sports = $db->query('SELECT s.*, ps.name as scheme_name, ps.points_1, ps.points_2, ps.points_3, ps.points_4, ps.points_5, ps.points_6
                FROM intramural_sports s
                LEFT JOIN intramural_point_schemes ps ON s.point_scheme_id = ps.id
                ORDER BY s.name, s.category')->fetchAll();
        }
    } catch (Throwable $e) {
        if ($sportId) {
            $sports = $db->prepare('SELECT * FROM intramural_sports WHERE id = ?');
            $sports->execute([$sportId]);
            $sports = $sports->fetchAll();
        } else {
            $sports = $db->query('SELECT * FROM intramural_sports ORDER BY name, category')->fetchAll();
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
                'manual_rank' => false,
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

        $manualRanks = $seasonId ? getEventRanks($sid, (int) $seasonId) : [];
        if ($manualRanks && $seasonId && eventHasScheduledMatches($sid, (int) $seasonId)) {
            $manualRanks = [];
        }
        $rank = 1;
        foreach ($rows as &$row) {
            $row['rank'] = $rank++;
            $row['medal'] = null;
            $row['placement_points'] = 0;
            $row['placement_label'] = null;
            $row['manual_rank'] = false;
            if ($row['played'] > 0) {
                applyEventPlacement($row, $row['rank'], $scheme, false);
            }
        }
        unset($row);

        if ($manualRanks) {
            foreach ($rows as &$row) {
                $tid = (int) $row['team_id'];
                if (isset($manualRanks[$tid])) {
                    applyEventPlacement($row, (int) $manualRanks[$tid]['place_rank'], $scheme, true);
                } else {
                    $row['rank'] = 1000;
                    $row['medal'] = null;
                    $row['placement_points'] = 0;
                    $row['placement_label'] = null;
                    $row['manual_rank'] = false;
                }
            }
            unset($row);
            usort($rows, static function ($x, $y) {
                if ($x['rank'] !== $y['rank']) {
                    return $x['rank'] <=> $y['rank'];
                }
                return strcasecmp((string) $x['team_name'], (string) $y['team_name']);
            });
        }

        $result[$sid] = [
            'sport' => $sport,
            'scheme' => $scheme,
            'standings' => $rows,
            'manual_ranks' => $manualRanks !== [],
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
            if (!isset($overall[$tid])) {
                continue;
            }
            $hasPlacement = !empty($row['manual_rank']) || (int) $row['placement_points'] > 0 || !empty($row['medal']);
            if ($row['played'] === 0 && !$hasPlacement) {
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

function athleteFullName(array $athlete, bool $uppercase = false): string
{
    $name = trim(($athlete['first_name'] ?? '') . ' ' . ($athlete['last_name'] ?? ''));
    if ($uppercase && $name !== '') {
        return function_exists('mb_strtoupper') ? mb_strtoupper($name, 'UTF-8') : strtoupper($name);
    }

    return $name;
}

/** Athlete display name for printable reports and official forms. */
function athleteFullNameReport(array $athlete): string
{
    return athleteFullName($athlete, true);
}

/** Official JHCSC course / program options for athlete records. */
function athleteCourseOptions(): array
{
    return [
        'Bachelor of Elementary Education (BEEd)',
        'Bachelor of Secondary Education (BSEd)',
        'Bachelor of Physical Education (BPEd)',
        'Bachelor of Early Childhood Education (BECEd)',
        'Bachelor of Technology and Livelihood Education (BTLEd)',
        'Bachelor of Science in Information Technology (BSIT)',
        'Bachelor of Science in Computer Science (BSCS)',
        'Bachelor of Science in Agriculture (BSA)',
        'Bachelor of Science in Forestry (BSF)',
        'Bachelor of Science in Environmental Science (BSES)',
        'Bachelor of Agricultural Technology (BAT)',
        'Bachelor of Science in Criminology (BSCrim)',
        'Bachelor of Science in Business Administration (BSBA)',
        'Bachelor of Science in Accountancy (BSA)',
        'Bachelor of Science in Hospitality Management (BSHM)',
        'Bachelor of Science in Tourism Management (BSTM)',
        'Diploma / Certificate Program',
    ];
}

function athleteYearLevelOptions(): array
{
    return ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year', 'Graduate'];
}

function renderAthleteCourseSelect(string $selected = '', string $name = 'department'): string
{
    $options = athleteCourseOptions();
    $html = '<select name="' . sanitize($name) . '" class="form-select">';
    $html .= '<option value="">Select course</option>';
    $matched = false;
    foreach ($options as $opt) {
        $isSelected = $selected === $opt;
        if ($isSelected) {
            $matched = true;
        }
        $html .= '<option value="' . sanitize($opt) . '"' . ($isSelected ? ' selected' : '') . '>' . sanitize($opt) . '</option>';
    }
    if ($selected !== '' && !$matched) {
        $html .= '<option value="' . sanitize($selected) . '" selected>' . sanitize($selected) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

/**
 * Official event roster for one team (locked/registered athletes only).
 *
 * @return list<array<string, mixed>>
 */
function getOfficialEventRoster(int $sportId, int $teamId, ?int $seasonId = null): array
{
    $seasonId = $seasonId ?? getCurrentSeasonId();
    if ($sportId <= 0 || $teamId <= 0 || !$seasonId) {
        return [];
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT r.id, r.jersey_number, r.position, r.event_category, r.athlete_id,
            a.first_name, a.last_name, a.student_id, a.athlete_code, a.gender, a.year_level, a.department, a.photo, a.birthdate
        FROM intramural_registrations r
        JOIN intramural_athletes a ON r.athlete_id = a.id
        WHERE r.sport_id = ? AND r.team_id = ? AND r.season_id = ? AND a.is_active = 1
        ORDER BY CAST(r.jersey_number AS UNSIGNED), a.last_name, a.first_name');
    $stmt->execute([$sportId, $teamId, $seasonId]);

    return $stmt->fetchAll() ?: [];
}

/**
 * Clickable team name that opens the official-roster dialog for a match.
 */
function matchTeamRosterTrigger(array $match, string $side = 'a'): string
{
    $isB = $side === 'b';
    $teamId = (int) ($isB ? ($match['team_b_id'] ?? 0) : ($match['team_a_id'] ?? 0));
    $name = (string) ($isB ? ($match['team_b_name'] ?? '') : ($match['team_a_name'] ?? ''));
    $color = (string) ($isB ? ($match['team_b_color'] ?? '') : ($match['team_a_color'] ?? ''));
    if ($teamId <= 0 || $name === '') {
        return '<span class="text-muted">TBD</span>';
    }

    $style = $color !== '' ? ' style="color:' . sanitize($color) . '"' : '';
    return '<button type="button" class="btn btn-link p-0 align-baseline fw-semibold text-decoration-underline match-roster-trigger"'
        . ' data-match-id="' . (int) ($match['id'] ?? 0) . '"'
        . ' data-team-id="' . $teamId . '"'
        . $style
        . ' title="View official roster for ' . sanitize($name) . '">'
        . sanitize($name)
        . '</button>';
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
        ['2024-0001', 'Juan', 'Dela Cruz', 'male', '2004-05-12', 'Bachelor of Elementary Education (BEEd)', '2nd Year', 'Blue Eagles', 'juan@example.com', '09171234567', 'Basketball 5x5', 'men', '7', 'Guard', ''],
        ['2024-0002', 'Maria', 'Santos', 'female', '2005-01-20', 'Bachelor of Science in Criminology (BSCrim)', '1st Year', 'Red Lions', '', '', 'Volleyball', 'women', '10', 'Setter', ''],
    ];
}

/**
 * Build roster template rows from active sports (events) and players_per_event limits.
 *
 * @return list<list<string>>
 */
function buildRosterImportTemplateRows(?int $scopedTeamId = null): array
{
    $db = getDB();
    $rows = [];

    $teams = [];
    if ($scopedTeamId) {
        $stmt = $db->prepare('SELECT name FROM intramural_teams WHERE id = ? AND is_active = 1');
        $stmt->execute([$scopedTeamId]);
        $team = $stmt->fetch();
        if ($team) {
            $teams[] = $team;
        }
    } else {
        $teams = $db->query('SELECT name FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
    }

    $sports = $db->query('SELECT name, category, players_per_event FROM intramural_sports ORDER BY name, category')->fetchAll();

    foreach ($teams as $team) {
        foreach ($sports as $sport) {
            $slots = (int) ($sport['players_per_event'] ?? 0);
            if ($slots < 1) {
                $slots = 1;
            }

            for ($slot = 1; $slot <= $slots; $slot++) {
                $rows[] = [
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    $team['name'],
                    '',
                    '',
                    $sport['name'],
                    $sport['category'],
                    (string) $slot,
                    '',
                    '',
                ];
            }
        }
    }

    return $rows ?: rosterImportSampleRows();
}

function getActiveSportEventRows(): array
{
    $db = getDB();
    $eventRows = [];

    try {
        foreach ($db->query('SELECT name, category, players_per_event, scoring_method, tournament_format, venue FROM intramural_sports ORDER BY name, category')->fetchAll() as $s) {
            $eventRows[] = [
                $s['name'],
                $s['category'],
                (int) ($s['players_per_event'] ?? 0) ?: '',
                ucfirst($s['scoring_method']),
                tournamentFormatLabel($s['tournament_format'] ?? 'round_robin'),
                $s['venue'] ?? '',
            ];
        }
    } catch (Throwable $e) {
        $eventRows = [];
    }

    return $eventRows;
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
        ['2024-0001', 'Juan', 'Dela Cruz', 'male', '2004-05-12', 'Bachelor of Elementary Education (BEEd)', '2nd Year', $team, 'juan@example.com', '09171234567', 'Basketball 5x5', 'men', '7', 'Guard', ''],
        ['2024-0002', 'Maria', 'Santos', 'female', '2005-01-20', 'Bachelor of Science in Criminology (BSCrim)', '1st Year', $team, '', '', 'Volleyball', 'women', '10', 'Setter', ''],
        ['2024-0003', 'Pedro', 'Reyes', 'male', '2004-08-03', 'Bachelor of Science in Business Administration (BSBA)', '3rd Year', $team, '', '', '', '', '', '', ''],
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
        foreach ($db->query('SELECT name, category FROM intramural_sports ORDER BY name, category')->fetchAll() as $s) {
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
        'course' => 'department',
        'program' => 'department',
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
function downloadRosterImportTemplate(string $format = 'xlsx', ?int $scopedTeamId = null): void
{
    $headers = rosterImportHeaders();
    $templateRows = buildRosterImportTemplateRows($scopedTeamId);

    if ($format === 'csv') {
        exportCsv('athlete-roster-import-template.csv', $headers, $templateRows);
    }

    $db = getDB();
    $teamRows = [];
    try {
        if ($scopedTeamId) {
            $stmt = $db->prepare('SELECT name, short_name FROM intramural_teams WHERE id = ? AND is_active = 1');
            $stmt->execute([$scopedTeamId]);
            $t = $stmt->fetch();
            if ($t) {
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

    $eventRows = getActiveSportEventRows();
    $totalSlots = count($templateRows);

    $season = function_exists('getCurrentSeason') ? getCurrentSeason() : null;
    $seasonLabel = $season ? seasonLabel($season) : 'Active season';
    $teamNote = $scopedTeamId && !empty($teamRows[0][0])
        ? 'Template rows are limited to your assigned team (' . $teamRows[0][0] . ').'
        : 'Each team has pre-filled rows for every event in Sports Management.';

    $instructionRows = [
        ['Athlete Roster Import Template'],
        ['Season', $seasonLabel],
        ['Template rows', (string) $totalSlots],
        [''],
        ['How to use'],
        ['1', 'Fill the Roster sheet only. Do not rename or reorder header columns.'],
        ['2', 'Each row is one athlete slot for a team + event + category. Team, sport, and sport_category are pre-filled.'],
        ['3', 'Enter student_id, first_name, last_name (and optional profile fields) on rows you need. Leave unused slots blank — blank rows are skipped on import.'],
        ['4', $teamNote],
        ['5', 'Use the Events sheet for events and players_per_event limits from Sports Management.'],
        ['6', 'Save the file, then upload this .xlsx (or Save As CSV UTF-8) on Import Roster.'],
        [''],
        ['Required columns', 'student_id, first_name, last_name, team, sport'],
        ['Optional columns', 'gender, birthdate, department, year_level, email, phone, sport_category, jersey_number, position, event_category'],
        ['gender values', 'male | female | other'],
        ['birthdate format', 'YYYY-MM-DD (example: 2004-05-12)'],
        ['sport_category', 'men | women | mixed — must match the Events sheet for that sport'],
        [''],
        ['Notes'],
        ['-', 'Athlete records are created or updated from roster import (admin and unit managers).'],
        ['-', 'Existing Student IDs are updated and registered for the sport in the active season.'],
        ['-', 'Row count per event follows players_per_event set under Sports Management.'],
    ];

    exportSimpleXlsx('athlete-roster-import-template.xlsx', [
        'Roster' => [
            'headers' => $headers,
            'rows' => $templateRows,
        ],
        'Instructions' => [
            'headers' => ['Item', 'Details'],
            'rows' => $instructionRows,
        ],
        'Teams' => [
            'headers' => ['team_name', 'short_name'],
            'rows' => $teamRows,
        ],
        'Events' => [
            'headers' => ['sport_name', 'sport_category', 'players_per_event', 'scoring_method', 'tournament_style', 'venue'],
            'rows' => $eventRows,
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
    foreach ($db->query('SELECT * FROM intramural_sports')->fetchAll() as $s) {
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
        foreach (['men', 'women', 'mixed'] as $cat) {
            $key = $name . '|' . $cat;
            if (isset($lookups['sports'][$key]) && empty($lookups['sports'][$key]['_ambiguous'])) {
                return $lookups['sports'][$key];
            }
        }
    }

    return null;
}
