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
        'modified_single_elimination_consolation' => 'Modified Single Elimination w Consolation',
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
        'modified_single_elimination_consolation',
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

/** Ensure intramural_seasons has results lock columns. */
function ensureResultsLockColumns(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');

    $stmt->execute(['intramural_seasons', 'results_locked']);
    if ((int) $stmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE intramural_seasons ADD COLUMN results_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER roster_locked_by');
    }

    $stmt->execute(['intramural_seasons', 'results_lock_date']);
    if ((int) $stmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE intramural_seasons ADD COLUMN results_lock_date DATE DEFAULT NULL AFTER results_locked');
    }

    $stmt->execute(['intramural_seasons', 'results_locked_at']);
    if ((int) $stmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE intramural_seasons ADD COLUMN results_locked_at DATETIME DEFAULT NULL AFTER results_lock_date');
    }

    $stmt->execute(['intramural_seasons', 'results_locked_by']);
    if ((int) $stmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE intramural_seasons ADD COLUMN results_locked_by INT DEFAULT NULL AFTER results_locked_at');
    }

    ensureEventResultsLockTable();
}

/** Per-event results locks (within a season). */
function ensureEventResultsLockTable(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(['intramural_event_results_locks']);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $db->exec("CREATE TABLE intramural_event_results_locks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        season_id INT NOT NULL,
        sport_id INT NOT NULL,
        locked_at DATETIME DEFAULT NULL,
        locked_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_event_results_lock (season_id, sport_id),
        INDEX idx_event_results_lock_sport (sport_id),
        FOREIGN KEY (season_id) REFERENCES intramural_seasons(id) ON DELETE CASCADE,
        FOREIGN KEY (sport_id) REFERENCES intramural_sports(id) ON DELETE CASCADE,
        FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
}

/** Per-event: admin can let tournament managers use Event Rankings for that sport. */
function ensureTmRankingAccessTable(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(['intramural_event_tm_ranking']);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $db->exec("CREATE TABLE intramural_event_tm_ranking (
        id INT AUTO_INCREMENT PRIMARY KEY,
        season_id INT NOT NULL,
        sport_id INT NOT NULL,
        enabled_at DATETIME DEFAULT NULL,
        enabled_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_event_tm_ranking (season_id, sport_id),
        INDEX idx_event_tm_ranking_sport (sport_id),
        FOREIGN KEY (season_id) REFERENCES intramural_seasons(id) ON DELETE CASCADE,
        FOREIGN KEY (sport_id) REFERENCES intramural_sports(id) ON DELETE CASCADE,
        FOREIGN KEY (enabled_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
}

/** Ensure division tables and team.division_id for HS/College (or custom) groupings. */
function ensureIntramuralDivisionsSchema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $tableStmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $colStmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');

    $tableStmt->execute(['intramural_divisions']);
    if ((int) $tableStmt->fetchColumn() === 0) {
        $db->exec("CREATE TABLE intramural_divisions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_division_name (name)
        ) ENGINE=InnoDB");
    }

    $tableStmt->execute(['intramural_division_sports']);
    if ((int) $tableStmt->fetchColumn() === 0) {
        $db->exec("CREATE TABLE intramural_division_sports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            division_id INT NOT NULL,
            sport_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_division_sport (division_id, sport_id),
            FOREIGN KEY (division_id) REFERENCES intramural_divisions(id) ON DELETE CASCADE,
            FOREIGN KEY (sport_id) REFERENCES intramural_sports(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
    } else {
        ensureDivisionSportsAutoIncrement($db);
    }

    $colStmt->execute(['intramural_teams', 'division_id']);
    if ((int) $colStmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE intramural_teams ADD COLUMN division_id INT DEFAULT NULL AFTER department');
        try {
            $db->exec('ALTER TABLE intramural_teams ADD CONSTRAINT fk_teams_division FOREIGN KEY (division_id) REFERENCES intramural_divisions(id) ON DELETE SET NULL');
        } catch (Throwable $e) {
            // FK may already exist or engine may not support it mid-migration
        }
    }
}

/**
 * @return list<array<string,mixed>>
 */
function getDivisions(bool $activeOnly = false): array
{
    ensureIntramuralDivisionsSchema();
    $db = getDB();
    $sql = 'SELECT d.*,
        (SELECT COUNT(*) FROM intramural_teams t WHERE t.division_id = d.id AND t.is_active = 1) AS team_count,
        (SELECT COUNT(*) FROM intramural_division_sports ds WHERE ds.division_id = d.id) AS sport_count
        FROM intramural_divisions d';
    if ($activeOnly) {
        $sql .= ' WHERE d.is_active = 1';
    }
    $sql .= ' ORDER BY d.sort_order ASC, d.name ASC';
    return $db->query($sql)->fetchAll() ?: [];
}

function getDivisionById(int $id): ?array
{
    ensureIntramuralDivisionsSchema();
    if ($id <= 0) {
        return null;
    }
    $stmt = getDB()->prepare('SELECT * FROM intramural_divisions WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * @return list<int>
 */
function getDivisionSportIds(int $divisionId): array
{
    ensureIntramuralDivisionsSchema();
    if ($divisionId <= 0) {
        return [];
    }
    $stmt = getDB()->prepare('SELECT sport_id FROM intramural_division_sports WHERE division_id = ? ORDER BY sport_id');
    $stmt->execute([$divisionId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/**
 * @param list<int> $sportIds
 */
function saveDivisionSports(int $divisionId, array $sportIds): void
{
    ensureIntramuralDivisionsSchema();
    $db = getDB();
    $db->prepare('DELETE FROM intramural_division_sports WHERE division_id = ?')->execute([$divisionId]);
    $sportIds = array_values(array_unique(array_filter(array_map('intval', $sportIds))));
    if ($sportIds === []) {
        return;
    }
    $ins = $db->prepare('INSERT INTO intramural_division_sports (division_id, sport_id) VALUES (?, ?)');
    foreach ($sportIds as $sportId) {
        if ($sportId > 0) {
            $ins->execute([$divisionId, $sportId]);
        }
    }
}

/**
 * Assign checked teams to this division; clear division on teams that were in it but unchecked.
 *
 * @param list<int> $teamIds
 */
function saveDivisionTeams(int $divisionId, array $teamIds): void
{
    ensureIntramuralDivisionsSchema();
    $db = getDB();
    $teamIds = array_values(array_unique(array_filter(array_map('intval', $teamIds))));
    $db->prepare('UPDATE intramural_teams SET division_id = NULL WHERE division_id = ?')->execute([$divisionId]);
    if ($teamIds === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $params = array_merge([$divisionId], $teamIds);
    $db->prepare("UPDATE intramural_teams SET division_id = ? WHERE id IN ($placeholders)")->execute($params);
}

/**
 * Teams with no division may play any event. Teams in a division may only play that division's events.
 */
function getTeamDivisionId(int $teamId): ?int
{
    ensureIntramuralDivisionsSchema();
    if ($teamId <= 0) {
        return null;
    }
    $stmt = getDB()->prepare('SELECT division_id FROM intramural_teams WHERE id = ?');
    $stmt->execute([$teamId]);
    $divisionId = $stmt->fetchColumn();
    if ($divisionId === false || $divisionId === null || $divisionId === '') {
        return null;
    }
    return (int) $divisionId;
}

function teamCanPlaySport(int $teamId, int $sportId): bool
{
    ensureIntramuralDivisionsSchema();
    if ($teamId <= 0 || $sportId <= 0) {
        return false;
    }
    $divisionId = getTeamDivisionId($teamId);
    if ($divisionId === null) {
        return true;
    }
    return in_array($sportId, getDivisionSportIds($divisionId), true);
}

/**
 * @param list<array<string,mixed>> $sports
 * @return list<array<string,mixed>>
 */
function filterSportsForTeamDivision(array $sports, ?int $teamId): array
{
    if (!$teamId) {
        return $sports;
    }
    ensureIntramuralDivisionsSchema();
    $stmt = getDB()->prepare('SELECT division_id FROM intramural_teams WHERE id = ?');
    $stmt->execute([$teamId]);
    $divisionId = $stmt->fetchColumn();
    if ($divisionId === false || $divisionId === null || $divisionId === '') {
        return $sports;
    }
    $allowed = getDivisionSportIds((int) $divisionId);
    if ($allowed === []) {
        return [];
    }
    return array_values(array_filter($sports, static function ($sport) use ($allowed) {
        return in_array((int) ($sport['id'] ?? 0), $allowed, true);
    }));
}

/**
 * Active team IDs allowed to compete in a sport under division rules.
 *
 * @return list<int>
 */
function getTeamIdsEligibleForSport(int $sportId): array
{
    ensureIntramuralDivisionsSchema();
    if ($sportId <= 0) {
        return [];
    }
    $db = getDB();
    $stmt = $db->prepare("SELECT t.id FROM intramural_teams t
        WHERE t.is_active = 1
          AND (
            t.division_id IS NULL
            OR EXISTS (
                SELECT 1 FROM intramural_division_sports ds
                WHERE ds.division_id = t.division_id AND ds.sport_id = ?
            )
          )
        ORDER BY t.name");
    $stmt->execute([$sportId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/** Ensure intramural_matches.division_id exists for per-division brackets. */
function ensureMatchDivisionColumn(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    ensureIntramuralDivisionsSchema();
    $db = getDB();
    $colStmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $colStmt->execute(['intramural_matches', 'division_id']);
    if ((int) $colStmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE intramural_matches ADD COLUMN division_id INT DEFAULT NULL AFTER sport_id');
        try {
            $db->exec('ALTER TABLE intramural_matches ADD CONSTRAINT fk_matches_division FOREIGN KEY (division_id) REFERENCES intramural_divisions(id) ON DELETE SET NULL');
        } catch (Throwable $e) {
            // ignore if FK cannot be added
        }
        try {
            $db->exec('ALTER TABLE intramural_matches ADD INDEX idx_matches_division (division_id)');
        } catch (Throwable $e) {
            // ignore
        }
    }

    ensureMatchesAutoIncrement($db);
    ensureMatchGameNumberColumn($db);
}

/** Per-venue daily game sequence (Game 1, 2, 3… on each date at each venue). */
function ensureMatchGameNumberColumn(?PDO $db = null): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = $db ?? getDB();
    $colStmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $colStmt->execute(['intramural_matches', 'game_number']);
    if ((int) $colStmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE intramural_matches ADD COLUMN game_number INT DEFAULT NULL AFTER venue');
    }
}

/** Repair intramural_matches.id when missing AUTO_INCREMENT (legacy migrations). */
function ensureMatchesAutoIncrement(PDO $db): void
{
    ensureTablePrimaryAutoIncrement($db, 'intramural_matches');
}

/** Repair intramural_division_sports.id when missing AUTO_INCREMENT (legacy migrations). */
function ensureDivisionSportsAutoIncrement(PDO $db): void
{
    ensureTablePrimaryAutoIncrement($db, 'intramural_division_sports');
}

/** Ensure per-event team position table (Team 1…N within a division). */
function ensureEventTeamPositionsTable(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    ensureIntramuralDivisionsSchema();
    $db = getDB();
    $tableStmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $tableStmt->execute(['intramural_event_team_positions']);
    if ((int) $tableStmt->fetchColumn() === 0) {
        $db->exec("CREATE TABLE intramural_event_team_positions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            division_id INT NOT NULL,
            sport_id INT NOT NULL,
            team_id INT NOT NULL,
            position TINYINT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_div_sport_position (division_id, sport_id, position),
            UNIQUE KEY uq_div_sport_team (division_id, sport_id, team_id),
            FOREIGN KEY (division_id) REFERENCES intramural_divisions(id) ON DELETE CASCADE,
            FOREIGN KEY (sport_id) REFERENCES intramural_sports(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES intramural_teams(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
    } else {
        ensureEventTeamPositionsAutoIncrement($db);
    }
}

/** Repair intramural_event_team_positions.id when missing AUTO_INCREMENT (legacy migrations). */
function ensureEventTeamPositionsAutoIncrement(PDO $db): void
{
    ensureTablePrimaryAutoIncrement($db, 'intramural_event_team_positions');
}

function divisionHasPositioning(int $divisionId): bool
{
    return count(getTeamIdsInDivision($divisionId, true)) > 2;
}

/** Number of position slots for a division (= active team count). */
function getDivisionPositionSlotCount(int $divisionId): int
{
    return count(getTeamIdsInDivision($divisionId, true));
}

/**
 * Position numbers 1..N for a division.
 *
 * @return list<int>
 */
function getDivisionPositionSlots(int $divisionId): array
{
    $n = getDivisionPositionSlotCount($divisionId);
    return $n > 0 ? range(1, $n) : [];
}

/**
 * @return array<int, int> position (1..N) => team_id
 */
function getEventTeamPositions(int $divisionId, int $sportId): array
{
    ensureEventTeamPositionsTable();
    if ($divisionId <= 0 || $sportId <= 0) {
        return [];
    }
    $maxPos = getDivisionPositionSlotCount($divisionId);
    $stmt = getDB()->prepare('SELECT position, team_id FROM intramural_event_team_positions
        WHERE division_id = ? AND sport_id = ? ORDER BY position ASC');
    $stmt->execute([$divisionId, $sportId]);
    $map = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $pos = (int) $row['position'];
        if ($pos >= 1 && ($maxPos <= 0 || $pos <= $maxPos)) {
            $map[$pos] = (int) $row['team_id'];
        }
    }
    return $map;
}

/**
 * Save Team 1…N for a division + event. Empty/zero clears that slot.
 * Slot count matches the number of active teams in the division.
 *
 * @param array<int, int> $positionsBySlot position => team_id
 * @return list<string> validation errors
 */
function saveEventTeamPositions(int $divisionId, int $sportId, array $positionsBySlot): array
{
    ensureEventTeamPositionsTable();
    $errors = [];
    if ($divisionId <= 0 || $sportId <= 0) {
        return ['Invalid division or event.'];
    }
    if (!divisionHasPositioning($divisionId)) {
        return ['Team positioning is only available for divisions with more than 2 teams.'];
    }
    $allowedSports = getDivisionSportIds($divisionId);
    if (!in_array($sportId, $allowedSports, true)) {
        return ['That event is not assigned to this division.'];
    }
    $allowedTeamIds = getTeamIdsInDivision($divisionId, true);
    $allowedTeams = array_flip($allowedTeamIds);
    $slots = getDivisionPositionSlots($divisionId);
    if ($slots === []) {
        return ['This division has no active teams.'];
    }

    $clean = [];
    $seenTeams = [];
    foreach ($slots as $pos) {
        $tid = (int) ($positionsBySlot[$pos] ?? 0);
        if ($tid <= 0) {
            continue;
        }
        if (!isset($allowedTeams[$tid])) {
            $errors[] = 'Team ' . $pos . ': selected team is not in this division.';
            continue;
        }
        if (isset($seenTeams[$tid])) {
            $errors[] = 'Each team can only occupy one position for this event.';
            continue;
        }
        $seenTeams[$tid] = $pos;
        $clean[$pos] = $tid;
    }
    if ($errors) {
        return $errors;
    }

    $db = getDB();
    $db->prepare('DELETE FROM intramural_event_team_positions WHERE division_id = ? AND sport_id = ?')
        ->execute([$divisionId, $sportId]);
    if ($clean === []) {
        return [];
    }
    $ins = $db->prepare('INSERT INTO intramural_event_team_positions (division_id, sport_id, team_id, position) VALUES (?, ?, ?, ?)');
    foreach ($clean as $pos => $tid) {
        $ins->execute([$divisionId, $sportId, $tid, $pos]);
    }
    return [];
}

/**
 * Reorder team IDs using saved event positions (Team 1…N first), then any remaining teams.
 *
 * @param list<int> $teamIds
 * @return list<int>
 */
function orderTeamIdsByEventPosition(?int $divisionId, int $sportId, array $teamIds): array
{
    $teamIds = array_values(array_unique(array_filter(array_map('intval', $teamIds))));
    if ($divisionId === null || $divisionId <= 0 || $sportId <= 0 || $teamIds === []) {
        return $teamIds;
    }
    if (!divisionHasPositioning($divisionId)) {
        return $teamIds;
    }

    $positions = getEventTeamPositions($divisionId, $sportId);
    if ($positions === []) {
        return $teamIds;
    }

    ksort($positions, SORT_NUMERIC);
    $set = array_flip($teamIds);
    $ordered = [];
    foreach ($positions as $pos => $tid) {
        $tid = (int) $tid;
        if ($tid > 0 && isset($set[$tid])) {
            $ordered[] = $tid;
            unset($set[$tid]);
        }
    }
    foreach ($teamIds as $tid) {
        if (isset($set[$tid])) {
            $ordered[] = $tid;
        }
    }
    return $ordered;
}

/**
 * Active team IDs in a division. Pass null for teams with no division assigned.
 *
 * @return list<int>
 */
function getTeamIdsInDivision(?int $divisionId, bool $activeOnly = true): array
{
    ensureIntramuralDivisionsSchema();
    $db = getDB();
    $sql = 'SELECT id FROM intramural_teams WHERE ';
    $params = [];
    if ($divisionId === null) {
        $sql .= 'division_id IS NULL';
    } else {
        $sql .= 'division_id = ?';
        $params[] = $divisionId;
    }
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY name';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/**
 * Teams in a division that may play the given sport.
 *
 * @return list<int>
 */
function getDivisionTeamIdsForSport(int $sportId, ?int $divisionId): array
{
    $ids = getTeamIdsInDivision($divisionId, true);
    if ($sportId <= 0) {
        return $ids;
    }
    return array_values(array_filter($ids, static fn($tid) => teamCanPlaySport((int) $tid, $sportId)));
}

/**
 * Group eligible teams for a sport by division (for separate brackets).
 * Key 0 = unassigned teams. Only returns groups with at least one team.
 *
 * @param list<int>|null $candidateTeamIds When set, only consider these team IDs
 * @return array<int, array{division_id: ?int, division_name: string, team_ids: list<int>}>
 */
function groupEligibleTeamsByDivisionForSport(int $sportId, ?array $candidateTeamIds = null): array
{
    ensureIntramuralDivisionsSchema();
    $db = getDB();

    if ($candidateTeamIds !== null) {
        $candidateTeamIds = array_values(array_unique(array_filter(array_map('intval', $candidateTeamIds))));
        if ($candidateTeamIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($candidateTeamIds), '?'));
        $stmt = $db->prepare("SELECT id, division_id FROM intramural_teams WHERE is_active = 1 AND id IN ($placeholders)");
        $stmt->execute($candidateTeamIds);
        $rows = $stmt->fetchAll() ?: [];
    } else {
        $eligible = getTeamIdsEligibleForSport($sportId);
        if ($eligible === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($eligible), '?'));
        $stmt = $db->prepare("SELECT id, division_id FROM intramural_teams WHERE id IN ($placeholders)");
        $stmt->execute($eligible);
        $rows = $stmt->fetchAll() ?: [];
    }

    $groups = [];
    foreach ($rows as $row) {
        $tid = (int) $row['id'];
        if ($sportId > 0 && !teamCanPlaySport($tid, $sportId)) {
            continue;
        }
        $divId = $row['division_id'] !== null && $row['division_id'] !== '' ? (int) $row['division_id'] : 0;
        if (!isset($groups[$divId])) {
            $name = 'Unassigned';
            if ($divId > 0) {
                $div = getDivisionById($divId);
                $name = $div['name'] ?? ('Division #' . $divId);
            }
            $groups[$divId] = [
                'division_id' => $divId > 0 ? $divId : null,
                'division_name' => $name,
                'team_ids' => [],
            ];
        }
        $groups[$divId]['team_ids'][] = $tid;
    }

    ksort($groups);
    return $groups;
}

/**
 * Build per-division team groups for match generation.
 * Includes every active division that has this event enabled.
 * Pass null $candidateTeamIds to use every eligible house in the division.
 * Pass roster IDs only when generating from the season roster (narrows a
 * division when at least 2 of those teams belong to it).
 *
 * @param list<int>|null $candidateTeamIds Season roster or other filter; null = all division teams
 * @return list<array{division_id: ?int, division_name: string, team_ids: list<int>}>
 */
function buildSportDivisionGroups(int $sportId, int $seasonId = 0, ?array $candidateTeamIds = null): array
{
    ensureIntramuralDivisionsSchema();
    if ($sportId <= 0) {
        return [];
    }

    if ($candidateTeamIds !== null) {
        $candidateTeamIds = array_values(array_unique(array_filter(
            array_map('intval', $candidateTeamIds),
            static fn(int $tid): bool => $tid > 0 && teamCanPlaySport($tid, $sportId)
        )));
    }

    $groups = [];

    foreach (getDivisions(true) as $div) {
        $divId = (int) $div['id'];
        $allowedSports = getDivisionSportIds($divId);
        if ($allowedSports === [] || !in_array($sportId, $allowedSports, true)) {
            continue;
        }

        $divTeams = getDivisionTeamIdsForSport($sportId, $divId);
        if ($divTeams === []) {
            continue;
        }

        $teamIds = $divTeams;
        if ($candidateTeamIds !== null) {
            $rosterInDiv = array_values(array_intersect($candidateTeamIds, $divTeams));
            if (count($rosterInDiv) >= 2) {
                $teamIds = $rosterInDiv;
            }
        }

        $groups[] = [
            'division_id' => $divId,
            'division_name' => $div['name'] ?? ('Division #' . $divId),
            'team_ids' => $teamIds,
        ];
    }

    $unassigned = getDivisionTeamIdsForSport($sportId, null);
    if ($unassigned !== []) {
        $teamIds = $unassigned;
        if ($candidateTeamIds !== null) {
            $rosterUnassigned = array_values(array_intersect($candidateTeamIds, $unassigned));
            if (count($rosterUnassigned) >= 2) {
                $teamIds = $rosterUnassigned;
            }
        }
        $groups[] = [
            'division_id' => null,
            'division_name' => 'Unassigned',
            'team_ids' => $teamIds,
        ];
    }

    return $groups;
}

/**
 * Resolve team IDs for one division + event.
 * Null candidates → every eligible house in the division. Roster IDs are used
 * only when at least two of them belong to the division; otherwise all division teams.
 *
 * @return list<int>
 */
function resolveDivisionSportTeamIds(int $sportId, ?int $divisionId, int $seasonId = 0, ?array $candidateTeamIds = null): array
{
    $divTeams = getDivisionTeamIdsForSport($sportId, $divisionId);
    if ($divTeams === []) {
        return [];
    }
    if ($candidateTeamIds === null) {
        return $divTeams;
    }
    $candidateTeamIds = array_values(array_unique(array_filter(
        array_map('intval', $candidateTeamIds),
        static fn(int $tid): bool => $tid > 0 && teamCanPlaySport($tid, $sportId)
    )));
    $rosterInDiv = array_values(array_intersect($candidateTeamIds, $divTeams));
    return count($rosterInDiv) >= 2 ? $rosterInDiv : $divTeams;
}

/**
 * Prefix fixture round labels with a division name.
 *
 * @param list<array<string,mixed>> $fixtures
 */
function prefixFixtureDivisionLabels(array &$fixtures, string $divisionName): void
{
    $divisionName = trim($divisionName);
    if ($divisionName === '') {
        return;
    }
    foreach ($fixtures as &$f) {
        $label = trim((string) ($f['round_label'] ?? 'Round'));
        if ($label === '' || stripos($label, $divisionName . ' —') === 0) {
            continue;
        }
        $f['round_label'] = $divisionName . ' — ' . $label;
    }
    unset($f);
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
        ['name' => 'Sepak Takraw', 'description' => 'Sepak takraw tournament', 'scoring_method' => 'sets', 'scheme' => 'Major Team Sports', 'win_points' => 3, 'players_per_event' => 6, 'tournament_format' => 'single_elimination_consolation', 'format_notes' => 'Single elimination with consolation. Each team tie is 1st, 2nd, and 3rd Regu (best of 3). Final winner = Champion, Final loser = 1st Runner Up; consolation winner = 3rd, loser = 4th.'],
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
    // Preserve the caller's order. For division fixtures this is the saved
    // Team 1…N position order and therefore controls first-round matchmaking.

    if (count($teamIds) < 2) {
        return [];
    }

    switch ($format) {
        case 'single_elimination':
            return buildSingleEliminationFixtures($teamIds);
        case 'single_elimination_consolation':
            return buildSingleEliminationConsolationFixtures($teamIds);
        case 'modified_single_elimination_consolation':
            return buildModifiedSingleElimConsolationFixtures($teamIds);
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
 * Circle-method round robin: each team plays once per round, and round 1 is
 * Team 1 vs Team 2, Team 3 vs Team 4, … (saved event-position order).
 *
 * The old nested loop dumped every pairing as Round 1 (1v2, 1v3, 1v4…), so the
 * same house could be scheduled for several games in a row — unusable for long
 * events such as baseball.
 *
 * @param list<int> $teamIds
 * @return list<array<string, mixed>>
 */
function buildRoundRobinFixtures(array $teamIds, string $roundLabel = 'Round Robin'): array
{
    $teams = array_values($teamIds);
    $n = count($teams);
    if ($n < 2) {
        return [];
    }
    if ($n === 2) {
        return [[
            'team_a_id' => $teams[0],
            'team_b_id' => $teams[1],
            'round_number' => 1,
            'round_label' => $roundLabel . ' — Round 1',
            'match_order' => 1,
            'notes' => null,
        ]];
    }

    $rotation = $teams;
    if ($n % 2 === 1) {
        $rotation[] = null;
    }
    $size = count($rotation);

    // Place Team 1 vs Team 2, Team 3 vs Team 4, … in round 1.
    $odds = [];
    $evens = [];
    for ($i = 0; $i < $size; $i++) {
        if ($i % 2 === 0) {
            $odds[] = $rotation[$i];
        } else {
            $evens[] = $rotation[$i];
        }
    }
    $rotation = array_merge($odds, array_reverse($evens));

    $rounds = $size - 1;
    $half = (int) ($size / 2);
    $fixtures = [];
    $order = 0;

    for ($round = 1; $round <= $rounds; $round++) {
        for ($i = 0; $i < $half; $i++) {
            $a = $rotation[$i];
            $b = $rotation[$size - 1 - $i];
            if ($a === null || $b === null) {
                continue;
            }
            $order++;
            $fixtures[] = [
                'team_a_id' => $a,
                'team_b_id' => $b,
                'round_number' => $round,
                'round_label' => $roundLabel . ' — Round ' . $round,
                'match_order' => $order,
                'notes' => null,
            ];
        }

        $fixed = $rotation[0];
        $rest = array_slice($rotation, 1);
        $last = array_pop($rest);
        array_unshift($rest, $last);
        $rotation = array_merge([$fixed], $rest);
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
        ['suffix' => 'Singles 2', 'note' => 'Deciding singles rubber if tied 1–1 after Singles 1 and Doubles'],
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
            $noteParts[] = 'SDS tie #' . $tieNum . ' — ' . $leg['note'] . '. Team wins the tie with 2 of 3 rubbers; Singles 2 plays only if tied 1–1 after Singles 1 and Doubles.';
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

function isSepakTakrawSport(string $sportName): bool
{
    return str_contains(strtolower(trim($sportName)), 'sepak');
}

/** Elimination formats where Sepak Takraw team ties expand into 1st/2nd/3rd Regu (best of 3). */
function isSepakTakrawReguFormat(?string $format): bool
{
    return in_array((string) $format, [
        'single_elimination',
        'single_elimination_consolation',
        'modified_single_elimination_consolation',
    ], true);
}

function shouldExpandSepakTakrawRegus(array $sport): bool
{
    $name = (string) ($sport['name'] ?? $sport['sport_name'] ?? '');
    return isSepakTakrawSport($name)
        && isSepakTakrawReguFormat($sport['tournament_format'] ?? null);
}

/** Attach sport name / format to a match row when only sport_id is present. */
function enrichMatchWithSport(PDO $db, array $match): array
{
    $name = (string) ($match['name'] ?? $match['sport_name'] ?? '');
    $format = $match['tournament_format'] ?? null;
    if ($name !== '' && $format !== null) {
        return $match;
    }
    $sportId = (int) ($match['sport_id'] ?? 0);
    if ($sportId <= 0) {
        return $match;
    }

    static $cache = [];
    if (!isset($cache[$sportId])) {
        $stmt = $db->prepare('SELECT name, tournament_format FROM intramural_sports WHERE id = ?');
        $stmt->execute([$sportId]);
        $cache[$sportId] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    if ($name === '') {
        $match['name'] = $cache[$sportId]['name'] ?? '';
        $match['sport_name'] = $match['name'];
    }
    if ($format === null) {
        $match['tournament_format'] = $cache[$sportId]['tournament_format'] ?? null;
    }

    return $match;
}

/** Regu order for each Sepak Takraw team tie (best of 3; 3rd regu only if tied 1–1). */
function sepakTakrawReguLegs(): array
{
    return [
        ['suffix' => '1st Regu', 'note' => 'First regu'],
        ['suffix' => '2nd Regu', 'note' => 'Second regu'],
        ['suffix' => '3rd Regu', 'note' => 'Deciding regu if tied 1–1 after the first two'],
    ];
}

/**
 * Expand each team-vs-team tie into Sepak Takraw regus (best of 3).
 *
 * @param list<array<string, mixed>> $ties
 * @return list<array<string, mixed>>
 */
function expandTiesToReguFixtures(array $ties): array
{
    $legs = sepakTakrawReguLegs();
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
                $noteParts[] = 'TBD — fill teams after previous regu ties';
            }
            $noteParts[] = 'Regu tie #' . $tieNum . ' — ' . $leg['note']
                . '. Team wins the tie with 2 regus; 3rd regu plays only if tied 1–1 after the first two.';
            $fixtures[] = [
                'team_a_id' => $tie['team_a_id'],
                'team_b_id' => $tie['team_b_id'],
                'round_number' => $baseRound,
                'round_label' => $roundLabel . ' — ' . $leg['suffix'],
                'match_order' => $order,
                'notes' => implode('. ', $noteParts),
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
 * 4-team Modified Single Elimination with Consolation.
 *
 * Game 1: Team 1 vs Team 2
 * Game 2: Team 3 vs Team 4
 * Game 3: Loser G1 vs Loser G2 (loser of Game 3 = 4th place)
 * Game 4: Winner G1 vs Winner G2
 * Game 5: Loser G4 vs Winner G3
 * Game 6: Winner G5 vs Winner G4 (remaining undefeated team)
 *
 * @param list<int> $teamIds
 * @return list<array<string, mixed>>
 */
function buildModifiedSingleElimConsolationFixtures(array $teamIds): array
{
    $teamIds = array_values(array_unique(array_map('intval', $teamIds)));
    $teamIds = array_values(array_filter($teamIds, static fn($id) => $id > 0));
    if (count($teamIds) !== 4) {
        return [];
    }

    return [
        [
            'team_a_id' => $teamIds[0],
            'team_b_id' => $teamIds[1],
            'round_number' => 1,
            'round_label' => 'Game 1',
            'match_order' => 1,
            'notes' => 'Opening round — Team 1 vs Team 2',
        ],
        [
            'team_a_id' => $teamIds[2],
            'team_b_id' => $teamIds[3],
            'round_number' => 1,
            'round_label' => 'Game 2',
            'match_order' => 2,
            'notes' => 'Opening round — Team 3 vs Team 4',
        ],
        [
            'team_a_id' => null,
            'team_b_id' => null,
            'round_number' => 2,
            'round_label' => 'Game 3 — Consolation (4th Place)',
            'match_order' => 3,
            'notes' => 'TBD — losers of Games 1 and 2. Loser of this game is 4th place.',
        ],
        [
            'team_a_id' => null,
            'team_b_id' => null,
            'round_number' => 3,
            'round_label' => 'Game 4 — Winners',
            'match_order' => 4,
            'notes' => 'TBD — winners of Games 1 and 2. Winner stays undefeated; loser faces the Game 3 winner.',
        ],
        [
            'team_a_id' => null,
            'team_b_id' => null,
            'round_number' => 4,
            'round_label' => 'Game 5 — Consolation',
            'match_order' => 5,
            'notes' => 'TBD — loser of Game 4 vs winner of Game 3',
        ],
        [
            'team_a_id' => null,
            'team_b_id' => null,
            'round_number' => 5,
            'round_label' => 'Game 6 — Championship Final',
            'match_order' => 6,
            'notes' => 'TBD — winner of Game 5 vs remaining undefeated team (winner of Game 4)',
        ],
    ];
}

function modifiedConsolationGameNumber(array $match): int
{
    $label = (string) ($match['round_label'] ?? '');
    if (preg_match('/\bGame\s+(\d+)\b/i', $label, $m)) {
        return (int) $m[1];
    }
    return (int) ($match['match_order'] ?? 0);
}

/**
 * Fill Games 3–6 from completed results for Modified Single Elimination w Consolation.
 *
 * @param list<array> $matches
 * @param list<string> $details
 */
function advanceModifiedSingleElimConsolationBracket(PDO $db, array &$matches, array &$details): int
{
    $indexesByDivision = [];
    foreach ($matches as $i => $m) {
        $divKey = (string) ((int) ($m['division_id'] ?? 0));
        $indexesByDivision[$divKey][] = $i;
    }

    $updated = 0;
    foreach ($indexesByDivision as $indexes) {
        $updated += advanceModifiedConsolationGroup($db, $matches, $indexes, $details);
    }

    $details = array_values(array_unique($details));
    return $updated;
}

/**
 * @param list<int> $indexes
 * @param list<string> $details
 */
function advanceModifiedConsolationGroup(PDO $db, array &$matches, array $indexes, array &$details): int
{
    $indexByGame = [];
    foreach ($indexes as $i) {
        $n = modifiedConsolationGameNumber($matches[$i]);
        if ($n >= 1 && $n <= 6 && !isset($indexByGame[$n])) {
            $indexByGame[$n] = $i;
        }
    }

    $updated = 0;
    $fill = static function (int $game, int $teamId, string $side, string $note) use ($db, &$matches, $indexByGame, &$updated, &$details): void {
        if ($teamId <= 0 || !isset($indexByGame[$game])) {
            return;
        }
        if (assignTeamToBracketSlot($db, $matches[$indexByGame[$game]], $teamId, $side)) {
            $updated++;
            $details[] = $note;
        }
    };

    $outcome = static function (int $game) use (&$matches, $indexByGame): ?array {
        if (!isset($indexByGame[$game])) {
            return null;
        }
        return getMatchOutcome($matches[$indexByGame[$game]]);
    };

    $g1 = $outcome(1);
    $g2 = $outcome(2);
    if ($g1 && $g2) {
        $fill(3, (int) $g1['loser'], 'a', 'Filled Game 3 with losers of Games 1 and 2.');
        $fill(3, (int) $g2['loser'], 'b', 'Filled Game 3 with losers of Games 1 and 2.');
        $fill(4, (int) $g1['winner'], 'a', 'Filled Game 4 with winners of Games 1 and 2.');
        $fill(4, (int) $g2['winner'], 'b', 'Filled Game 4 with winners of Games 1 and 2.');
    }

    $g3 = $outcome(3);
    $g4 = $outcome(4);
    if ($g3 && $g4) {
        $fill(5, (int) $g4['loser'], 'a', 'Filled Game 5 with the Game 4 loser and Game 3 winner.');
        $fill(5, (int) $g3['winner'], 'b', 'Filled Game 5 with the Game 4 loser and Game 3 winner.');
    }

    $g5 = $outcome(5);
    if ($g4 && $g5) {
        $fill(6, (int) $g4['winner'], 'a', 'Filled Game 6 with the undefeated Game 4 winner and Game 5 winner.');
        $fill(6, (int) $g5['winner'], 'b', 'Filled Game 6 with the undefeated Game 4 winner and Game 5 winner.');
    }

    return $updated;
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

/** Extra hours past the configured end hour when auto-scheduling generated matches. */
const SCHEDULE_GENERATION_END_HOUR_ALLOWANCE = 1;

function scheduleEffectiveEndHour(int $configuredEndHour, int $endHourAllowance = 0): int
{
    if ($endHourAllowance <= 0) {
        return $configuredEndHour;
    }

    return min(24, $configuredEndHour + $endHourAllowance);
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
function scheduleWindowEndDateTime(array $window, int $endHourAllowance = 0): ?DateTime
{
    if (!scheduleWindowIsValid($window)) {
        return null;
    }

    try {
        $cursor = new DateTime((string) $window['end_date']);
        [, $endHour] = scheduleDayBounds($cursor->format('Y-m-d'), $window);
        $endHour = scheduleEffectiveEndHour($endHour, $endHourAllowance);
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
function normalizeScheduleCursor(DateTime &$cursor, array $window, int $endHourAllowance = 0): bool
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
        $dayEndHour = scheduleEffectiveEndHour($dayEndHour, $endHourAllowance);
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

function matchFitsScheduleWindow(DateTime $start, int $durationMinutes, array $window, int $endHourAllowance = 0): bool
{
    $windowStart = scheduleWindowStartDateTime($window);
    $lastDay = scheduleWindowEndDateTime($window, $endHourAllowance);
    if (!$windowStart || !$lastDay) {
        return false;
    }

    if ($start < $windowStart || $start > $lastDay) {
        return false;
    }

    [, $dayEndHour] = scheduleDayBounds($start->format('Y-m-d'), $window);
    $dayEndHour = scheduleEffectiveEndHour($dayEndHour, $endHourAllowance);
    $end = clone $start;
    $end->modify('+' . $durationMinutes . ' minutes');
    $dayEnd = clone $start;
    $dayEnd->setTime($dayEndHour, 0, 0);

    return $end <= $dayEnd && $end <= $lastDay;
}

function advanceScheduleCursor(DateTime &$cursor, int $durationMinutes, array $window, int $endHourAllowance = 0): bool
{
    $cursor->modify('+' . $durationMinutes . ' minutes');

    return normalizeScheduleCursor($cursor, $window, $endHourAllowance);
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
function bumpCursorPastRanges(DateTime &$cursor, int $durationMinutes, array $ranges, array $window, int $endHourAllowance = 0): bool
{
    for ($attempt = 0; $attempt < 10000; $attempt++) {
        if (!normalizeScheduleCursor($cursor, $window, $endHourAllowance)) {
            return false;
        }
        if (matchOverlapsRanges($cursor, $durationMinutes, $ranges)) {
            $cursor->modify('+15 minutes');
            continue;
        }
        if (matchFitsScheduleWindow($cursor, $durationMinutes, $window, $endHourAllowance)) {
            return true;
        }
        $cursor->modify('+1 day');
        [$dayStartHour] = scheduleDayBounds($cursor->format('Y-m-d'), $window);
        $cursor->setTime($dayStartHour, 0, 0);
    }

    return false;
}

/** Resume scheduling after the latest existing match end time within the schedule window. */
function getScheduleResumeCursor(int $seasonId, array $window): ?DateTime
{
    if (!scheduleWindowIsValid($window)) {
        return null;
    }

    $windowStart = scheduleWindowStartDateTime($window);
    $windowEnd = scheduleWindowEndDateTime($window);
    if (!$windowStart || !$windowEnd) {
        return null;
    }

    if ($seasonId <= 0) {
        return clone $windowStart;
    }

    $windowStartTs = (int) $windowStart->format('U');
    $windowEndTs = (int) $windowEnd->format('U');

    ensureSportGameDurationColumn();
    $db = getDB();
    $stmt = $db->prepare('SELECT m.scheduled_at, s.game_duration_minutes
        FROM intramural_matches m
        JOIN intramural_sports s ON m.sport_id = s.id
        WHERE m.season_id = ? AND m.scheduled_at IS NOT NULL AND m.status NOT IN (\'cancelled\')
          AND m.scheduled_at >= ? AND m.scheduled_at <= ?');
    $stmt->execute([
        $seasonId,
        $windowStart->format('Y-m-d H:i:s'),
        $windowEnd->format('Y-m-d H:i:s'),
    ]);
    $maxEnd = null;
    foreach ($stmt->fetchAll() as $row) {
        $startTs = strtotime((string) $row['scheduled_at']);
        if ($startTs === false || $startTs < $windowStartTs || $startTs > $windowEndTs) {
            continue;
        }
        $duration = normalizeGameDurationMinutes($row['game_duration_minutes'] ?? null);
        $endTs = $startTs + ($duration * 60);
        if ($maxEnd === null || $endTs > $maxEnd) {
            $maxEnd = $endTs;
        }
    }

    if ($maxEnd === null) {
        return clone $windowStart;
    }

    $cursor = DateTime::createFromFormat('U', (string) $maxEnd);
    if (!$cursor) {
        return clone $windowStart;
    }

    if ($cursor < $windowStart) {
        return clone $windowStart;
    }
    if ($cursor > $windowEnd) {
        return clone $windowStart;
    }

    if (!normalizeScheduleCursor($cursor, $window)) {
        return clone $windowStart;
    }

    return $cursor;
}

/** Start cursor for newly generated fixtures: fill from the beginning of the schedule window. */
function getScheduleGenerationCursor(array $window): ?DateTime
{
    return createScheduleCursor($window);
}

/**
 * Assign scheduled_at using each sport's estimated game duration.
 *
 * @param list<array<string, mixed>> $fixtures
 * @return int Number of fixtures scheduled
 */
function applyDurationScheduleToFixtures(array &$fixtures, array $sport, array $window, DateTime &$cursor, int $seasonId = 0): int
{
    $duration = getSportGameDurationMinutes($sport);
    $venueKey = normalizeVenueKey($sport['venue'] ?? null);
    $occupied = ($seasonId > 0 && $venueKey !== '') ? getOccupiedVenueRanges($seasonId, $venueKey) : [];
    $endHourAllowance = SCHEDULE_GENERATION_END_HOUR_ALLOWANCE;
    $scheduled = 0;
    foreach ($fixtures as &$fixture) {
        if (!bumpCursorPastRanges($cursor, $duration, $occupied, $window, $endHourAllowance)) {
            break;
        }
        $fixture['scheduled_at'] = $cursor->format('Y-m-d H:i:s');
        $scheduled++;
        if (!advanceScheduleCursor($cursor, $duration, $window, $endHourAllowance)) {
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

/** Effective venue key for a match row (match venue, else sport default). */
function matchEffectiveVenueKey(array $match, ?array $sport = null): string
{
    $venue = trim((string) ($match['venue'] ?? ''));
    if ($venue === '' && $sport !== null) {
        $venue = trim((string) ($sport['venue'] ?? ''));
    }
    if ($venue === '' && !empty($match['sport_venue'])) {
        $venue = trim((string) $match['sport_venue']);
    }

    return normalizeVenueKey($venue);
}

/**
 * Assign game_number on in-memory fixtures grouped by play date + venue.
 *
 * @param array<int, list<array<string,mixed>>> $fixturesBySport
 * @param array<int, array> $sportsById
 */
function assignVenueGameNumbersToFixtures(array &$fixturesBySport, array $sportsById): void
{
    $entries = [];
    foreach ($fixturesBySport as $sportId => $fixtures) {
        $sport = $sportsById[(int) $sportId] ?? [];
        foreach ($fixtures as $idx => $fixture) {
            if (empty($fixture['scheduled_at'])) {
                continue;
            }
            $entries[] = [
                'sport_id' => (int) $sportId,
                'idx' => (int) $idx,
                'ts' => strtotime((string) $fixture['scheduled_at']),
                'day' => date('Y-m-d', strtotime((string) $fixture['scheduled_at'])),
                'venue' => matchEffectiveVenueKey($fixture, $sport),
            ];
        }
    }

    usort($entries, static function (array $a, array $b): int {
        if ($a['ts'] !== $b['ts']) {
            return $a['ts'] <=> $b['ts'];
        }

        return $a['idx'] <=> $b['idx'];
    });

    $counters = [];
    foreach ($entries as $entry) {
        $groupKey = $entry['day'] . '|' . $entry['venue'];
        $counters[$groupKey] = ($counters[$groupKey] ?? 0) + 1;
        $fixturesBySport[$entry['sport_id']][$entry['idx']]['game_number'] = $counters[$groupKey];
    }
}

/**
 * Recompute game_number for all scheduled matches in a season (by date + venue, ordered by time).
 */
function recalculateVenueGameNumbers(int $seasonId): int
{
    if ($seasonId <= 0) {
        return 0;
    }

    ensureMatchGameNumberColumn();
    $db = getDB();

    $db->prepare("UPDATE intramural_matches SET game_number = NULL
        WHERE season_id = ? AND (scheduled_at IS NULL OR status = 'cancelled')")
        ->execute([$seasonId]);

    $stmt = $db->prepare("SELECT m.id, m.scheduled_at,
            COALESCE(NULLIF(TRIM(m.venue), ''), NULLIF(TRIM(s.venue), '')) AS effective_venue
        FROM intramural_matches m
        JOIN intramural_sports s ON s.id = m.sport_id
        WHERE m.season_id = ?
          AND m.scheduled_at IS NOT NULL
          AND m.status <> 'cancelled'
        ORDER BY m.scheduled_at ASC, m.id ASC");
    $stmt->execute([$seasonId]);
    $rows = $stmt->fetchAll() ?: [];

    $groups = [];
    foreach ($rows as $row) {
        $day = date('Y-m-d', strtotime((string) $row['scheduled_at']));
        $venueKey = normalizeVenueKey($row['effective_venue'] ?? null);
        $groups[$day . '|' . $venueKey][] = (int) $row['id'];
    }

    $update = $db->prepare('UPDATE intramural_matches SET game_number = ? WHERE id = ?');
    $updated = 0;
    foreach ($groups as $ids) {
        $num = 1;
        foreach ($ids as $matchId) {
            $update->execute([$num, $matchId]);
            $num++;
            $updated++;
        }
    }

    return $updated;
}

/** Backfill game numbers when scheduled matches are missing them. */
function ensureVenueGameNumbersCurrent(int $seasonId): void
{
    static $done = [];
    if ($seasonId <= 0 || isset($done[$seasonId])) {
        return;
    }
    $done[$seasonId] = true;

    ensureMatchGameNumberColumn();
    $db = getDB();
    $stmt = $db->prepare("SELECT 1 FROM intramural_matches
        WHERE season_id = ? AND scheduled_at IS NOT NULL AND status <> 'cancelled' AND game_number IS NULL
        LIMIT 1");
    $stmt->execute([$seasonId]);
    if ($stmt->fetch()) {
        recalculateVenueGameNumbers($seasonId);
    }
}

function formatVenueGameNumber(?int $gameNumber): string
{
    if ($gameNumber === null || $gameNumber <= 0) {
        return '';
    }

    return 'Game ' . $gameNumber;
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

    $defaultCursor = $sharedCursor ?? getScheduleGenerationCursor($window) ?? createScheduleCursor($window);
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
    $endHourAllowance = SCHEDULE_GENERATION_END_HOUR_ALLOWANCE;

    foreach ($venueGroups as $venueKey => $groupSportIds) {
        // No venue set: schedule each sport independently (no cross-sport blocking)
        if ($venueKey === '') {
            foreach ($groupSportIds as $sportId) {
                $cursor = clone $defaultCursor;
                $scheduled += applyDurationScheduleToFixtures(
                    $fixturesBySport[$sportId],
                    $sportsById[$sportId] ?? [],
                    $window,
                    $cursor,
                    $seasonId
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
                if (!bumpCursorPastRanges($cursor, $duration, $occupied, $window, $endHourAllowance)) {
                    break 2;
                }

                $idx = array_shift($queues[$sportId]);
                $startTs = (int) $cursor->format('U');
                $when = $cursor->format('Y-m-d H:i:s');
                $fixturesBySport[$sportId][$idx]['scheduled_at'] = $when;
                $fixturesBySport[$sportId][$idx]['venue'] = $sport['venue'] ?? null;
                $occupied[] = [$startTs, $startTs + ($duration * 60)];
                $scheduled++;
                if (!advanceScheduleCursor($cursor, $duration, $window, $endHourAllowance)) {
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
 * @param list<array<string,mixed>>|null $prebuiltFixtures
 * @return array{created: int, format: string, error?: string}
 */
function generateMatchesForSport(int $sportId, int $seasonId, array $teamIds, ?int $createdBy = null, bool $replaceUnscheduled = false, ?array $scheduleWindow = null, ?DateTime $scheduleCursor = null, ?array $prebuiltFixtures = null, ?int $divisionId = null, bool $scopedReplace = false): array
{
    ensureMatchDivisionColumn();
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM intramural_sports WHERE id = ?');
    $stmt->execute([$sportId]);
    $sport = $stmt->fetch();
    if (!$sport) {
        return ['created' => 0, 'format' => '', 'error' => 'Sport not found.'];
    }

    $format = $sport['tournament_format'] ?? 'round_robin';
    $teamIds = array_values(array_unique(array_filter(array_map('intval', $teamIds))));
    if ($format === 'rank_first_to_last' && count($teamIds) % 2 !== 0) {
        return ['created' => 0, 'format' => $format, 'error' => 'Rank from First to Last requires an even number of teams (each team plays one match).'];
    }
    if ($format === 'modified_single_elimination_consolation' && count($teamIds) !== 4) {
        return ['created' => 0, 'format' => $format, 'error' => 'Modified Single Elimination w Consolation requires exactly 4 teams (Team 1–4 positions).'];
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
            applyDurationScheduleToFixtures($fixtures, $sport, $scheduleWindow, $cursor, $seasonId);
        }
    }

    if (empty($fixtures)) {
        return ['created' => 0, 'format' => $format, 'error' => 'Select at least 2 teams to generate matches.'];
    }

    if ($replaceUnscheduled) {
        if ($scopedReplace && $teamIds) {
            $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
            if ($divisionId !== null && $divisionId > 0) {
                $db->prepare("DELETE FROM intramural_matches
                    WHERE season_id = ? AND sport_id = ? AND is_generated = 1
                      AND status IN ('scheduled', 'cancelled')
                      AND score_a IS NULL AND score_b IS NULL
                      AND (
                        division_id = ?
                        OR (
                            division_id IS NULL
                            AND team_a_id IN ($placeholders)
                            AND (team_b_id IS NULL OR team_b_id IN ($placeholders))
                        )
                      )")->execute(array_merge([$seasonId, $sportId, $divisionId], $teamIds, $teamIds));
            } else {
                $db->prepare("DELETE FROM intramural_matches
                    WHERE season_id = ? AND sport_id = ? AND is_generated = 1
                      AND status IN ('scheduled', 'cancelled')
                      AND score_a IS NULL AND score_b IS NULL
                      AND (
                        (division_id IS NULL OR division_id = 0)
                        AND team_a_id IN ($placeholders)
                        AND (team_b_id IS NULL OR team_b_id IN ($placeholders))
                      )")->execute(array_merge([$seasonId, $sportId], $teamIds, $teamIds));
            }
        } else {
            $db->prepare("DELETE FROM intramural_matches
                WHERE season_id = ? AND sport_id = ? AND is_generated = 1
                  AND status IN ('scheduled', 'cancelled')
                  AND score_a IS NULL AND score_b IS NULL")
                ->execute([$seasonId, $sportId]);
        }
    }

    $defaultVenue = trim((string) ($sport['venue'] ?? '')) ?: null;

    $insert = $db->prepare('INSERT INTO intramural_matches
        (season_id, sport_id, division_id, round_number, round_label, match_order, is_generated, team_a_id, team_b_id, scheduled_at, venue, status, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, \'scheduled\', ?, ?)');

    $created = 0;
    foreach ($fixtures as $f) {
        $insert->execute([
            $seasonId,
            $sportId,
            $divisionId && $divisionId > 0 ? $divisionId : null,
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
 * Append sport_id / sport_id IN (...) filter for match queries.
 *
 * @param int|list<int>|null $sportFilter
 */
function appendMatchSportFilter(string &$sql, array &$params, $sportFilter): void
{
    if ($sportFilter === null || $sportFilter === '') {
        return;
    }

    $ids = is_array($sportFilter)
        ? array_values(array_unique(array_filter(array_map('intval', $sportFilter))))
        : [(int) $sportFilter];
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
    if ($ids === []) {
        return;
    }

    if (count($ids) === 1) {
        $sql .= ' AND sport_id = ?';
        $params[] = $ids[0];
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql .= " AND sport_id IN ($placeholders)";
    $params = array_merge($params, $ids);
}

/**
 * Count generated matches for the active season (optionally one or more events).
 *
 * @param int|list<int>|null $sportId
 */
function countGeneratedMatches(int $seasonId, $sportId = null, bool $includeCompleted = false): int
{
    $db = getDB();
    $sql = 'SELECT COUNT(*) FROM intramural_matches WHERE season_id = ? AND is_generated = 1';
    $params = [$seasonId];
    appendMatchSportFilter($sql, $params, $sportId);

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
 * @param int|list<int>|null $sportId
 * @return array{deleted: int, scope: string}
 */
function deleteGeneratedMatches(int $seasonId, $sportId = null, bool $includeCompleted = false): array
{
    $db = getDB();
    $sql = 'DELETE FROM intramural_matches WHERE season_id = ? AND is_generated = 1';
    $params = [$seasonId];
    appendMatchSportFilter($sql, $params, $sportId);

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

/**
 * Count all matches for a season (optionally one or more events).
 *
 * @param int|list<int>|null $sportId
 */
function countSeasonMatches(int $seasonId, $sportId = null): int
{
    $db = getDB();
    $sql = 'SELECT COUNT(*) FROM intramural_matches WHERE season_id = ?';
    $params = [$seasonId];
    appendMatchSportFilter($sql, $params, $sportId);

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Delete all matches for a season (generated and manual).
 *
 * @param int|list<int>|null $sportId
 * @return array{deleted: int, scope: string}
 */
function deleteAllMatches(int $seasonId, $sportId = null): array
{
    $db = getDB();
    $sql = 'DELETE FROM intramural_matches WHERE season_id = ?';
    $params = [$seasonId];
    appendMatchSportFilter($sql, $params, $sportId);

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
    ensureMatchDivisionColumn();
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
    $groupByDivision = !empty($scheduleOptions['group_by_division']);
    $divisionFilter = (string) ($scheduleOptions['division_filter'] ?? '');
    $fixedDivisionId = isset($scheduleOptions['division_id']) ? (int) $scheduleOptions['division_id'] : 0;
    $useRosterTeams = !empty($scheduleOptions['use_roster_teams']);
    $scheduleWindow = buildScheduleWindow($startDate, $endDate, $scheduleOptions ?? []);
    $scheduleCursor = getScheduleGenerationCursor($scheduleWindow);

    $regTeamsStmt = $db->prepare('SELECT DISTINCT team_id FROM intramural_registrations WHERE sport_id = ? AND season_id = ?');
    $sportsById = [];
    $batches = [];

    foreach ($sportIds as $sportId) {
        $sportStmt = $db->prepare('SELECT * FROM intramural_sports WHERE id = ?');
        $sportStmt->execute([$sportId]);
        $sport = $sportStmt->fetch();
        if (!$sport) {
            $errors[] = 'Sport #' . $sportId . ': not found.';
            continue;
        }
        $sportsById[$sportId] = $sport;

        $explicitTeams = false;
        if ($teamsBySport !== null && isset($teamsBySport[$sportId])) {
            $teamIds = array_values(array_unique(array_map('intval', $teamsBySport[$sportId])));
            $explicitTeams = true;
        } elseif ($sharedTeamIds !== null) {
            $teamIds = array_values(array_unique(array_map('intval', $sharedTeamIds)));
            $explicitTeams = true;
        } elseif ($useRosterTeams) {
            $regTeamsStmt->execute([$sportId, $seasonId]);
            $teamIds = array_map('intval', $regTeamsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } else {
            $teamIds = getTeamIdsEligibleForSport($sportId);
        }

        $teamIds = array_values(array_filter(
            $teamIds,
            static fn(int $tid): bool => teamCanPlaySport($tid, $sportId)
        ));

        // Division mode: null candidates → every house in the division.
        // Roster / explicit slots: pass those IDs so groups can narrow.
        $groupCandidates = ($useRosterTeams || $explicitTeams) ? $teamIds : null;

        $groups = [];
        if ($groupByDivision) {
            if ($divisionFilter === 'none') {
                $ids = resolveDivisionSportTeamIds($sportId, null, $seasonId, $groupCandidates);
                $groups[] = ['division_id' => null, 'division_name' => 'Unassigned', 'team_ids' => $ids];
            } elseif ($fixedDivisionId > 0) {
                $ids = resolveDivisionSportTeamIds($sportId, $fixedDivisionId, $seasonId, $groupCandidates);
                $div = getDivisionById($fixedDivisionId);
                $groups[] = [
                    'division_id' => $fixedDivisionId,
                    'division_name' => $div['name'] ?? ('Division #' . $fixedDivisionId),
                    'team_ids' => $ids,
                ];
            } else {
                foreach (buildSportDivisionGroups($sportId, $seasonId, $groupCandidates) as $g) {
                    $groups[] = $g;
                }
            }
        } else {
            if ($explicitTeams) {
                if ($divisionFilter === 'none') {
                    $teamIds = array_values(array_intersect($teamIds, getTeamIdsInDivision(null)));
                } elseif ($fixedDivisionId > 0) {
                    $teamIds = array_values(array_intersect($teamIds, getTeamIdsInDivision($fixedDivisionId)));
                }
            } elseif ($divisionFilter === 'none') {
                $teamIds = resolveDivisionSportTeamIds($sportId, null, $seasonId, $groupCandidates);
            } elseif ($fixedDivisionId > 0) {
                $teamIds = resolveDivisionSportTeamIds($sportId, $fixedDivisionId, $seasonId, $groupCandidates);
            }
            $divName = '';
            $divId = null;
            if ($fixedDivisionId > 0) {
                $div = getDivisionById($fixedDivisionId);
                $divName = $div['name'] ?? '';
                $divId = $fixedDivisionId;
            } elseif ($divisionFilter === 'none') {
                $divName = 'Unassigned';
            }
            $groups[] = ['division_id' => $divId, 'division_name' => $divName, 'team_ids' => $teamIds];
        }

        if ($groupByDivision && $groups === []) {
            $errors[] = sportLabel($sport) . ': this event is not activated for any division (assign it under Admin → Divisions).';
            continue;
        }

        $format = $sport['tournament_format'] ?? 'round_robin';
        $boardsBySport = $scheduleOptions['boards_by_sport'] ?? [];

        foreach ($groups as $group) {
            $groupTeams = array_values(array_unique(array_filter(array_map('intval', $group['team_ids']))));
            $groupTeams = orderTeamIdsByEventPosition(
                isset($group['division_id']) ? ($group['division_id'] !== null ? (int) $group['division_id'] : null) : null,
                $sportId,
                $groupTeams
            );
            if (count($groupTeams) < 2) {
                $label = sportLabel($sport);
                if ($group['division_name'] !== '') {
                    $label .= ' (' . $group['division_name'] . ')';
                }
                if ($groupByDivision || $fixedDivisionId > 0 || $divisionFilter === 'none') {
                    $errors[] = $label . ': need at least 2 teams in this division.';
                } else {
                    $errors[] = $label . ': select at least 2 teams.';
                }
                continue;
            }

            if ($format === 'rank_first_to_last' && count($groupTeams) % 2 !== 0) {
                $label = sportLabel($sport);
                if ($group['division_name'] !== '') {
                    $label .= ' (' . $group['division_name'] . ')';
                }
                $errors[] = $label . ': Rank from First to Last requires an even number of teams.';
                continue;
            }

            if ($format === 'modified_single_elimination_consolation' && count($groupTeams) !== 4) {
                $label = sportLabel($sport);
                if ($group['division_name'] !== '') {
                    $label .= ' (' . $group['division_name'] . ')';
                }
                $errors[] = $label . ': Modified Single Elimination w Consolation requires exactly 4 teams.';
                continue;
            }

            $fixtures = buildTournamentFixtures($format, $groupTeams);
            if (empty($fixtures)) {
                $errors[] = sportLabel($sport) . ': no fixtures to generate.';
                continue;
            }

            if (!empty($group['division_name'])) {
                prefixFixtureDivisionLabels($fixtures, (string) $group['division_name']);
            }

            if (isChessSport((string) ($sport['name'] ?? ''))) {
                $boards = (int) ($boardsBySport[$sportId] ?? defaultChessBoardsPerTeam($sport));
                $fixtures = expandChessBoardFixtures($fixtures, $boards);
            }

            if (shouldExpandSepakTakrawRegus($sport)) {
                $fixtures = expandTiesToReguFixtures($fixtures);
            }

            $batches[] = [
                'sport_id' => $sportId,
                'division_id' => $group['division_id'],
                'division_name' => (string) ($group['division_name'] ?? ''),
                'team_ids' => $groupTeams,
                'fixtures' => $fixtures,
            ];
        }
    }

    $fixturesBySport = [];
    $batchRanges = [];
    foreach ($batches as $bi => $batch) {
        $sid = $batch['sport_id'];
        if (!isset($fixturesBySport[$sid])) {
            $fixturesBySport[$sid] = [];
        }
        $start = count($fixturesBySport[$sid]);
        foreach ($batch['fixtures'] as $fx) {
            $fixturesBySport[$sid][] = $fx;
        }
        $batchRanges[$bi] = [$sid, $start, count($batch['fixtures'])];
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
                    $scheduleCursor,
                    $seasonId
                );
            }
            unset($fixtures);
        }
    }

    foreach ($batchRanges as $bi => [$sid, $start, $len]) {
        for ($j = 0; $j < $len; $j++) {
            if (isset($fixturesBySport[$sid][$start + $j]['scheduled_at'])) {
                $batches[$bi]['fixtures'][$j]['scheduled_at'] = $fixturesBySport[$sid][$start + $j]['scheduled_at'];
            }
            if (isset($fixturesBySport[$sid][$start + $j]['venue'])) {
                $batches[$bi]['fixtures'][$j]['venue'] = $fixturesBySport[$sid][$start + $j]['venue'];
            }
        }
    }

    $unscheduledCount = 0;
    foreach ($batches as $batch) {
        foreach ($batch['fixtures'] as $fixture) {
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

    $scopedReplace = $groupByDivision || $fixedDivisionId > 0 || $divisionFilter === 'none';
    $okSportIds = [];

    foreach ($batches as $batch) {
        $sportId = $batch['sport_id'];
        $sport = $sportsById[$sportId];
        $result = generateMatchesForSport(
            $sportId,
            $seasonId,
            $batch['team_ids'],
            $createdBy,
            $replaceUnscheduled,
            null,
            null,
            $batch['fixtures'],
            $batch['division_id'],
            $scopedReplace
        );

        if (!empty($result['error'])) {
            $label = sportLabel($sport);
            if ($batch['division_name'] !== '') {
                $label .= ' (' . $batch['division_name'] . ')';
            }
            $errors[] = $label . ': ' . $result['error'];
            $details[] = [
                'sport_id' => $sportId,
                'sport' => $sport,
                'division_id' => $batch['division_id'],
                'created' => 0,
                'format' => $result['format'] ?? '',
                'error' => $result['error'],
            ];
            continue;
        }

        $okSportIds[$sportId] = true;
        $total += (int) $result['created'];
        $details[] = [
            'sport_id' => $sportId,
            'sport' => $sport,
            'division_id' => $batch['division_id'],
            'division_name' => $batch['division_name'],
            'created' => (int) $result['created'],
            'format' => $result['format'],
            'error' => null,
        ];
    }

    $okSports = count($okSportIds);

    if ($seasonId > 0 && ($total > 0 || $totalScheduled > 0)) {
        recalculateVenueGameNumbers($seasonId);
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

function isResultsLocked(?int $seasonId = null, ?int $sportId = null): bool
{
    $status = getResultsLockStatus($seasonId);
    if ($status['is_locked']) {
        return true;
    }
    if ($sportId !== null && $sportId > 0) {
        return isEventResultsLocked($sportId, $seasonId ?? ($status['season_id'] ? (int) $status['season_id'] : null));
    }
    return false;
}

/**
 * Whether a specific event's results are locked (ignores season-wide lock).
 */
function isEventResultsLocked(int $sportId, ?int $seasonId = null): bool
{
    $status = getEventResultsLockStatus($sportId, $seasonId);
    return !empty($status['is_locked']);
}

/**
 * @return array{is_locked:bool,locked_at:?string,locked_by:?int,locked_by_name:?string,season_id:?int,sport_id:int}
 */
function getEventResultsLockStatus(int $sportId, ?int $seasonId = null): array
{
    ensureEventResultsLockTable();
    $seasonId = $seasonId ?? getCurrentSeasonId();
    $empty = [
        'is_locked' => false,
        'locked_at' => null,
        'locked_by' => null,
        'locked_by_name' => null,
        'season_id' => $seasonId ? (int) $seasonId : null,
        'sport_id' => $sportId,
    ];
    if ($sportId <= 0 || !$seasonId) {
        return $empty;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT l.locked_at, l.locked_by, u.first_name, u.last_name, u.username
        FROM intramural_event_results_locks l
        LEFT JOIN users u ON u.id = l.locked_by
        WHERE l.season_id = ? AND l.sport_id = ?');
    $stmt->execute([(int) $seasonId, $sportId]);
    $row = $stmt->fetch();
    if (!$row) {
        return $empty;
    }

    $name = null;
    if (!empty($row['locked_by'])) {
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        if ($name === '') {
            $name = $row['username'] ?? null;
        }
    }

    return [
        'is_locked' => true,
        'locked_at' => $row['locked_at'] ?? null,
        'locked_by' => !empty($row['locked_by']) ? (int) $row['locked_by'] : null,
        'locked_by_name' => $name,
        'season_id' => (int) $seasonId,
        'sport_id' => $sportId,
    ];
}

/**
 * @return array<int, array{is_locked:bool,locked_at:?string,locked_by:?int,locked_by_name:?string}>
 */
function getEventResultsLocksMap(?int $seasonId = null): array
{
    ensureEventResultsLockTable();
    $seasonId = $seasonId ?? getCurrentSeasonId();
    if (!$seasonId) {
        return [];
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT l.sport_id, l.locked_at, l.locked_by, u.first_name, u.last_name, u.username
        FROM intramural_event_results_locks l
        LEFT JOIN users u ON u.id = l.locked_by
        WHERE l.season_id = ?');
    $stmt->execute([(int) $seasonId]);
    $map = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $name = null;
        if (!empty($row['locked_by'])) {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            if ($name === '') {
                $name = $row['username'] ?? null;
            }
        }
        $map[(int) $row['sport_id']] = [
            'is_locked' => true,
            'locked_at' => $row['locked_at'] ?? null,
            'locked_by' => !empty($row['locked_by']) ? (int) $row['locked_by'] : null,
            'locked_by_name' => $name,
        ];
    }
    return $map;
}

function lockEventResults(int $sportId, int $seasonId, int $userId): bool
{
    ensureEventResultsLockTable();
    if ($sportId <= 0 || !getSeasonById($seasonId)) {
        return false;
    }

    $db = getDB();
    $stmt = $db->prepare('INSERT INTO intramural_event_results_locks (season_id, sport_id, locked_at, locked_by)
        VALUES (?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE locked_at = NOW(), locked_by = VALUES(locked_by)');
    $stmt->execute([$seasonId, $sportId, $userId]);
    return true;
}

function unlockEventResults(int $sportId, int $seasonId): bool
{
    ensureEventResultsLockTable();
    if ($sportId <= 0 || !$seasonId) {
        return false;
    }

    $db = getDB();
    $stmt = $db->prepare('DELETE FROM intramural_event_results_locks WHERE season_id = ? AND sport_id = ?');
    $stmt->execute([$seasonId, $sportId]);
    return true;
}

function isTmRankingEnabled(int $sportId, ?int $seasonId = null): bool
{
    ensureTmRankingAccessTable();
    $seasonId = $seasonId ?? getCurrentSeasonId();
    if ($sportId <= 0 || !$seasonId) {
        return false;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT 1 FROM intramural_event_tm_ranking WHERE season_id = ? AND sport_id = ?');
    $stmt->execute([(int) $seasonId, $sportId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * @return list<int>
 */
function getTmRankingEnabledSportIds(?int $seasonId = null): array
{
    ensureTmRankingAccessTable();
    $seasonId = $seasonId ?? getCurrentSeasonId();
    if (!$seasonId) {
        return [];
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT sport_id FROM intramural_event_tm_ranking WHERE season_id = ?');
    $stmt->execute([(int) $seasonId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/**
 * @return array<int, array{enabled_at:?string,enabled_by:?int,enabled_by_name:?string}>
 */
function getTmRankingAccessMap(?int $seasonId = null): array
{
    ensureTmRankingAccessTable();
    $seasonId = $seasonId ?? getCurrentSeasonId();
    if (!$seasonId) {
        return [];
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT r.sport_id, r.enabled_at, r.enabled_by, u.first_name, u.last_name, u.username
        FROM intramural_event_tm_ranking r
        LEFT JOIN users u ON u.id = r.enabled_by
        WHERE r.season_id = ?');
    $stmt->execute([(int) $seasonId]);
    $map = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $name = null;
        if (!empty($row['enabled_by'])) {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            if ($name === '') {
                $name = $row['username'] ?? null;
            }
        }
        $map[(int) $row['sport_id']] = [
            'enabled_at' => $row['enabled_at'] ?? null,
            'enabled_by' => !empty($row['enabled_by']) ? (int) $row['enabled_by'] : null,
            'enabled_by_name' => $name,
        ];
    }
    return $map;
}

function enableTmRanking(int $sportId, int $seasonId, int $userId): bool
{
    ensureTmRankingAccessTable();
    if ($sportId <= 0 || !getSeasonById($seasonId)) {
        return false;
    }

    $db = getDB();
    $stmt = $db->prepare('INSERT INTO intramural_event_tm_ranking (season_id, sport_id, enabled_at, enabled_by)
        VALUES (?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE enabled_at = NOW(), enabled_by = VALUES(enabled_by)');
    $stmt->execute([$seasonId, $sportId, $userId]);
    return true;
}

function disableTmRanking(int $sportId, int $seasonId): bool
{
    ensureTmRankingAccessTable();
    if ($sportId <= 0 || !$seasonId) {
        return false;
    }

    $db = getDB();
    $stmt = $db->prepare('DELETE FROM intramural_event_tm_ranking WHERE season_id = ? AND sport_id = ?');
    $stmt->execute([$seasonId, $sportId]);
    return true;
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
function getResultsLockStatus(?int $seasonId = null): array
{
    ensureResultsLockColumns();
    applyScheduledResultsLocks();

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

    $lockDate = !empty($season['results_lock_date']) ? (string) $season['results_lock_date'] : null;
    $isLocked = !empty($season['results_locked']);
    $today = date('Y-m-d');
    $isScheduled = !$isLocked && $lockDate !== null && $lockDate > $today;

    $status = [
        'is_locked' => $isLocked,
        'is_scheduled' => $isScheduled,
        'lock_date' => $lockDate,
        'locked_at' => $season['results_locked_at'] ?? null,
        'locked_by' => !empty($season['results_locked_by']) ? (int) $season['results_locked_by'] : null,
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

function applyScheduledResultsLocks(): void
{
    ensureResultsLockColumns();
    $db = getDB();
    $db->exec("UPDATE intramural_seasons
        SET results_locked = 1,
            results_locked_at = COALESCE(results_locked_at, CONCAT(results_lock_date, ' 00:00:00'))
        WHERE results_lock_date IS NOT NULL
          AND results_lock_date <= CURDATE()
          AND results_locked = 0");
}

function requireUnlockedResults(?int $seasonId = null, ?int $sportId = null): void
{
    if (isResultsLocked($seasonId, $sportId)) {
        $msg = $sportId
            ? 'Match results are locked for this event. Contact the administrator to unlock them before making changes.'
            : 'Match results are locked for this season. Contact the administrator to unlock them before making changes.';
        flash('error', $msg);
        redirect(BASE_URL . '/intramurals/matches/index.php' . ($sportId ? '?sport=' . (int) $sportId : ''));
    }
}

function setSeasonResultsLockDate(int $seasonId, int $userId, string $lockDate): bool
{
    ensureResultsLockColumns();
    if (!getSeasonById($seasonId)) {
        return false;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lockDate)) {
        return false;
    }

    $today = date('Y-m-d');
    $db = getDB();

    if ($lockDate <= $today) {
        $stmt = $db->prepare('UPDATE intramural_seasons SET results_locked = 1, results_lock_date = ?, results_locked_at = NOW(), results_locked_by = ? WHERE id = ?');
        $stmt->execute([$lockDate, $userId, $seasonId]);
    } else {
        $stmt = $db->prepare('UPDATE intramural_seasons SET results_locked = 0, results_lock_date = ?, results_locked_at = NULL, results_locked_by = ? WHERE id = ?');
        $stmt->execute([$lockDate, $userId, $seasonId]);
    }

    return $stmt->rowCount() > 0;
}

function unlockSeasonResults(int $seasonId): bool
{
    ensureResultsLockColumns();
    if (!getSeasonById($seasonId)) {
        return false;
    }

    $db = getDB();
    $stmt = $db->prepare('UPDATE intramural_seasons SET results_locked = 0, results_lock_date = NULL, results_locked_at = NULL, results_locked_by = NULL WHERE id = ?');
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
 * Notify secretariat (all finished matches) and unit managers (only when their team played).
 * Skips if the match was already finished before this update.
 */
function notifyMatchFinished(int $matchId, ?string $previousStatus = null): void
{
    $finished = ['completed', 'forfeit'];
    if ($previousStatus !== null && in_array($previousStatus, $finished, true)) {
        return;
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT m.*, s.name AS sport_name, s.category AS sport_category,
            ta.name AS team_a_name, tb.name AS team_b_name
        FROM intramural_matches m
        JOIN intramural_sports s ON m.sport_id = s.id
        LEFT JOIN intramural_teams ta ON m.team_a_id = ta.id
        LEFT JOIN intramural_teams tb ON m.team_b_id = tb.id
        WHERE m.id = ?");
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();
    if (!$match || !in_array((string) ($match['status'] ?? ''), $finished, true)) {
        return;
    }

    $teamAId = (int) ($match['team_a_id'] ?? 0);
    $teamBId = (int) ($match['team_b_id'] ?? 0);
    $teamA = (string) ($match['team_a_name'] ?? 'TBD');
    $teamB = (string) ($match['team_b_name'] ?? 'TBD');
    $sport = sportLabel([
        'name' => $match['sport_name'] ?? '',
        'category' => $match['sport_category'] ?? '',
    ]);
    $scoreLine = ((string) ($match['status'] ?? '') === 'forfeit')
        ? 'Forfeit'
        : ((int) ($match['score_a'] ?? 0) . '–' . (int) ($match['score_b'] ?? 0));

    $title = 'Match finished: ' . $sport;
    $message = $teamA . ' vs ' . $teamB . ' · ' . $scoreLine;
    $link = BASE_URL . '/intramurals/matches/view.php?id=' . $matchId;

    $recipientIds = [];

    $secStmt = $db->query("SELECT id FROM users WHERE is_active = 1 AND role = 'secretariat'");
    foreach ($secStmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        $recipientIds[(int) $uid] = true;
    }

    $teamIds = array_values(array_filter([$teamAId, $teamBId], static fn($id) => $id > 0));
    if ($teamIds) {
        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        $umStmt = $db->prepare("SELECT DISTINCT u.id
            FROM users u
            WHERE u.is_active = 1
              AND u.role = 'unit_manager'
              AND (
                  u.team_id IN ($placeholders)
                  OR u.id IN (
                      SELECT t.unit_manager_id FROM intramural_teams t
                      WHERE t.unit_manager_id IS NOT NULL AND t.id IN ($placeholders)
                  )
              )");
        $umStmt->execute(array_merge($teamIds, $teamIds));
        foreach ($umStmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            $recipientIds[(int) $uid] = true;
        }
    }

    // Don't notify the person who just recorded the result.
    $actorId = (int) ($_SESSION['user_id'] ?? 0);
    if ($actorId > 0) {
        unset($recipientIds[$actorId]);
    }

    foreach (array_keys($recipientIds) as $userId) {
        createNotification((int) $userId, $title, $message, 'success', $link);
    }
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
    $scoreA = array_key_exists('score_a', $match) && $match['score_a'] !== null ? (int) $match['score_a'] : null;
    $scoreB = array_key_exists('score_b', $match) && $match['score_b'] !== null ? (int) $match['score_b'] : null;
    $winner = !empty($match['winner_team_id'])
        ? (int) $match['winner_team_id']
        : determineMatchWinner(
            $scoreA,
            $scoreB,
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

/** Strip SDS rubber or Sepak regu suffix so championship / consolation rounds compare as team ties. */
function bracketTieBaseLabel(array $match): string
{
    return sepakTakrawReguBaseLabel([
        'round_label' => sdsRubberBaseLabel($match),
    ]);
}

function isChampionshipFinalMatch(array $match): bool
{
    if (isConsolationMatch($match)) {
        return false;
    }

    $base = strtolower(bracketTieBaseLabel($match));
    if ($base === '') {
        $base = strtolower(trim((string) ($match['round_label'] ?? '')));
    }
    $parts = preg_split('/\s*—\s*/u', $base) ?: [$base];
    $tail = trim((string) end($parts));

    return $tail === 'final' || $tail === 'championship final';
}

function isThirdPlaceMatch(array $match): bool
{
    $base = strtolower(bracketTieBaseLabel($match));
    if ($base === '') {
        $base = strtolower(trim((string) ($match['round_label'] ?? '')));
    }

    return str_contains($base, '3rd place')
        || str_contains($base, 'third place')
        || str_contains($base, 'consolation final');
}

/**
 * Split generated matches into per-division buckets (0 = unassigned).
 *
 * @param list<array> $matches
 * @return array<string, list<array>>
 */
function partitionMatchesByDivision(array $matches): array
{
    $groups = [];
    foreach ($matches as $m) {
        $key = (string) ((int) ($m['division_id'] ?? 0));
        if (!isset($groups[$key])) {
            $groups[$key] = [];
        }
        $groups[$key][] = $m;
    }

    return $groups;
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
    $buckets = [];
    foreach ($matches as $m) {
        $label = (string) ($m['round_label'] ?? '');
        if (stripos($label, 'SDS') === false) {
            continue;
        }
        $teamA = (int) ($m['team_a_id'] ?? 0);
        $teamB = (int) ($m['team_b_id'] ?? 0);
        $base = sdsRubberBaseLabel($m);
        if ($teamA <= 0 || $teamB <= 0 || $base === '') {
            continue;
        }
        $pair = $teamA < $teamB ? $teamA . ':' . $teamB : $teamB . ':' . $teamA;
        $key = $base . '|' . $pair;
        $buckets[$key][] = $m;
    }

    $ties = [];
    foreach ($buckets as $group) {
        $result = sdsTieIsDecided($group);
        if ($result) {
            $ties[] = $result;
        }
    }

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
        foreach (partitionMatchesByDivision($matches) as $divMatches) {
            $updated += advanceSdsBracket($db, $divMatches, $details);
        }
        return ['updated' => $updated, 'details' => $details];
    }
    if ($format === 'team_play_sds_consolation') {
        foreach (partitionMatchesByDivision($matches) as $divMatches) {
            $updated += advanceSdsConsolationBracket($db, $divMatches, $details);
        }
        return ['updated' => $updated, 'details' => $details];
    }
    if (shouldExpandSepakTakrawRegus($sport)) {
        if ($format === 'single_elimination') {
            foreach (partitionMatchesByDivision($matches) as $divMatches) {
                $updated += advanceReguBracket($db, $divMatches, $details);
            }
            if ($updated === 0 && empty($details)) {
                $details[] = 'No regu TBD ties were ready to update. Complete previous regus first.';
            }
            return ['updated' => $updated, 'details' => $details];
        }
        if ($format === 'single_elimination_consolation') {
            foreach (partitionMatchesByDivision($matches) as $divMatches) {
                $updated += advanceReguConsolationBracket($db, $divMatches, $details);
            }
            return ['updated' => $updated, 'details' => $details];
        }
        if ($format === 'modified_single_elimination_consolation') {
            $updated += advanceModifiedSepakTakrawConsolationBracket($db, $matches, $details);
            if ($updated === 0 && empty($details)) {
                $details[] = 'No TBD slots were ready to update. Complete previous regus first.';
            }
            return ['updated' => $updated, 'details' => $details];
        }
    }
    if ($format === 'modified_single_elimination_consolation') {
        $updated += advanceModifiedSingleElimConsolationBracket($db, $matches, $details);
        if ($updated === 0 && empty($details)) {
            $details[] = 'No TBD slots were ready to update. Complete previous games first.';
        }
        return ['updated' => $updated, 'details' => $details];
    }

    foreach (partitionMatchesByDivision($matches) as $divMatches) {
        $updated += advanceClassicBracketGroup($db, $divMatches, $format, $details);
    }

    if ($updated === 0 && empty($details)) {
        $details[] = 'No TBD slots were ready to update. Complete previous round matches first.';
    }

    return ['updated' => $updated, 'details' => $details];
}

/**
 * Fill TBD slots for one division in a classic (non-SDS/regu) bracket.
 *
 * @param list<array> $matches
 * @param list<string> $details
 */
function advanceClassicBracketGroup(PDO $db, array $matches, string $format, array &$details): int
{
    $updated = 0;
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

    return $updated;
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

/**
 * Determine Sepak Takraw regu tie winner when tie is decided (2 regu wins, or 3 regus played).
 *
 * @param list<array> $reguMatches
 * @return array{winner:int,loser:int,matches:list<array>}|null
 */
function reguTieIsDecided(array $reguMatches): ?array
{
    if (empty($reguMatches)) {
        return null;
    }

    $wins = [];
    foreach ($reguMatches as $m) {
        $out = getMatchOutcome($m);
        if (!$out) {
            continue;
        }
        $w = (int) $out['winner'];
        $wins[$w] = ($wins[$w] ?? 0) + 1;
    }
    if (empty($wins)) {
        return null;
    }

    arsort($wins);
    $winner = (int) array_key_first($wins);
    $topWins = (int) ($wins[$winner] ?? 0);
    $completed = array_sum($wins);
    $decided = $topWins >= 2 || $completed >= 3;
    if (!$decided) {
        return null;
    }

    $teamA = (int) ($reguMatches[0]['team_a_id'] ?? 0);
    $teamB = (int) ($reguMatches[0]['team_b_id'] ?? 0);
    $loser = $winner === $teamA ? $teamB : $teamA;
    if ($winner > 0 && $loser > 0) {
        return ['winner' => $winner, 'loser' => $loser, 'matches' => $reguMatches];
    }

    return null;
}

/**
 * Collapse regu triples into virtual ties with a team winner/loser.
 *
 * @param list<array> $matches
 * @return list<array{winner:int,loser:int,matches:list<array>}>
 */
function collapseReguTies(array $matches): array
{
    $buckets = [];
    foreach ($matches as $m) {
        $label = (string) ($m['round_label'] ?? '');
        if (!preg_match('/\bRegu\b/i', $label)) {
            continue;
        }
        $teamA = (int) ($m['team_a_id'] ?? 0);
        $teamB = (int) ($m['team_b_id'] ?? 0);
        $base = sepakTakrawReguBaseLabel($m);
        if ($teamA <= 0 || $teamB <= 0 || $base === '') {
            continue;
        }
        $pair = $teamA < $teamB ? $teamA . ':' . $teamB : $teamB . ':' . $teamA;
        $key = $base . '|' . $pair;
        $buckets[$key][] = $m;
    }

    $ties = [];
    foreach ($buckets as $group) {
        usort($group, static fn(array $a, array $b): int => ((int) ($a['match_order'] ?? 0)) <=> ((int) ($b['match_order'] ?? 0)));
        $result = reguTieIsDecided($group);
        if ($result) {
            $result['_order'] = (int) ($group[0]['match_order'] ?? 0);
            $ties[] = $result;
        }
    }
    usort($ties, static fn(array $a, array $b): int => ((int) ($a['_order'] ?? 0)) <=> ((int) ($b['_order'] ?? 0)));
    foreach ($ties as &$tie) {
        unset($tie['_order']);
    }
    unset($tie);

    return $ties;
}

/**
 * Group regu matches by round label (strip regu suffix).
 *
 * @param list<array> $matches
 * @return array<string, list<array>>
 */
function groupReguRoundsByBaseLabel(array $matches): array
{
    $rounds = [];
    foreach ($matches as $m) {
        $base = trim(preg_replace('/\s*—\s*\d+(?:st|nd|rd)\s+Regu.*$/i', '', (string) ($m['round_label'] ?? '')));
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
 * Group destination regu rubbers into tie shells (3 legs each).
 *
 * @param list<array> $dstMatches
 * @return list<array{anchor: array, all: list<array>}>
 */
function groupReguTieShells(array $dstMatches): array
{
    $dstTies = [];
    foreach ($dstMatches as $m) {
        $label = (string) ($m['round_label'] ?? '');
        if (!preg_match('/1st Regu/i', $label)) {
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
 * Fill regu rubber shells from ordered team pairs.
 *
 * @param list<array> $dstMatches
 * @param list<array{0:int,1:int}> $teamPairs
 */
function fillReguTiesFromTeams(PDO $db, array $dstMatches, array $teamPairs): int
{
    $dstTies = groupReguTieShells($dstMatches);
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
 * Resolve a modified-consolation game (Games 1–6) from its regu rubbers.
 *
 * @param list<array> $matches
 * @return array{winner:int,loser:int}|null
 */
function getReguGameOutcome(int $game, array $matches): ?array
{
    $gameMatches = [];
    foreach ($matches as $m) {
        if (modifiedConsolationGameNumber($m) === $game) {
            $gameMatches[] = $m;
        }
    }
    if (empty($gameMatches)) {
        return null;
    }
    if (count($gameMatches) === 1 && !preg_match('/\bRegu\b/i', (string) ($gameMatches[0]['round_label'] ?? ''))) {
        return getMatchOutcome($gameMatches[0]);
    }

    usort($gameMatches, static fn($a, $b) => (int) ($a['match_order'] ?? 0) <=> (int) ($b['match_order'] ?? 0));
    $ties = collapseReguTies($gameMatches);

    return $ties[0] ?? null;
}

/**
 * Assign both teams to every regu slot for a modified-consolation game.
 *
 * @param list<array> $matches
 */
function fillReguGameTeams(PDO $db, array &$matches, int $game, int $teamA, int $teamB): int
{
    $updated = 0;
    foreach ($matches as &$m) {
        if (modifiedConsolationGameNumber($m) !== $game) {
            continue;
        }
        if (!preg_match('/\bRegu\b/i', (string) ($m['round_label'] ?? ''))) {
            continue;
        }
        if ($teamA && assignTeamToBracketSlot($db, $m, $teamA, 'a')) {
            $updated++;
        }
        if ($teamB && assignTeamToBracketSlot($db, $m, $teamB, 'b')) {
            $updated++;
        }
    }
    unset($m);

    return $updated;
}

/**
 * Fill Games 3–6 from completed regu results (Modified SE w Consolation + Sepak Takraw).
 *
 * @param list<array> $matches
 * @param list<int> $indexes
 * @param list<string> $details
 */
function advanceModifiedSepakTakrawConsolationGroup(PDO $db, array &$matches, array $indexes, array &$details): int
{
    $updated = 0;
    $fillGame = static function (int $game, int $teamA, int $teamB, string $note) use ($db, &$matches, &$updated, &$details): void {
        if ($teamA <= 0 || $teamB <= 0) {
            return;
        }
        $count = fillReguGameTeams($db, $matches, $game, $teamA, $teamB);
        if ($count > 0) {
            $updated += $count;
            $details[] = $note;
        }
    };

    $outcome = static function (int $game) use (&$matches): ?array {
        return getReguGameOutcome($game, $matches);
    };

    $g1 = $outcome(1);
    $g2 = $outcome(2);
    if ($g1 && $g2) {
        $fillGame(3, (int) $g1['loser'], (int) $g2['loser'], 'Filled Game 3 regus with losers of Games 1 and 2.');
        $fillGame(4, (int) $g1['winner'], (int) $g2['winner'], 'Filled Game 4 regus with winners of Games 1 and 2.');
    }

    $g3 = $outcome(3);
    $g4 = $outcome(4);
    if ($g3 && $g4) {
        $fillGame(5, (int) $g4['loser'], (int) $g3['winner'], 'Filled Game 5 regus with the Game 4 loser and Game 3 winner.');
    }

    $g5 = $outcome(5);
    if ($g4 && $g5) {
        $fillGame(6, (int) $g4['winner'], (int) $g5['winner'], 'Filled Game 6 regus with the undefeated Game 4 winner and Game 5 winner.');
    }

    return $updated;
}

/**
 * @param list<array> $matches
 * @param list<string> $details
 */
function advanceModifiedSepakTakrawConsolationBracket(PDO $db, array &$matches, array &$details): int
{
    $indexesByDivision = [];
    foreach ($matches as $i => $m) {
        $divKey = (string) ((int) ($m['division_id'] ?? 0));
        $indexesByDivision[$divKey][] = $i;
    }

    $updated = 0;
    foreach ($indexesByDivision as $indexes) {
        $updated += advanceModifiedSepakTakrawConsolationGroup($db, $matches, $indexes, $details);
    }

    $details = array_values(array_unique($details));
    return $updated;
}

/**
 * Advance regu single-elim ties (best of 3) into later TBD ties.
 *
 * @param list<array> $matches
 * @param list<string> $details
 */
function advanceReguBracket(PDO $db, array $matches, array &$details, bool $quiet = false): int
{
    $rounds = groupReguRoundsByBaseLabel($matches);
    $labels = array_keys($rounds);
    $updated = 0;

    for ($i = 0; $i < count($labels) - 1; $i++) {
        $srcTies = collapseReguTies($rounds[$labels[$i]]);
        if (empty($srcTies)) {
            continue;
        }

        $winners = array_map(static fn($t) => (int) $t['winner'], $srcTies);
        $dstTies = groupReguTieShells($rounds[$labels[$i + 1]]);
        $needed = count($dstTies) * 2;
        if ($needed < 1 || count($winners) < $needed) {
            continue;
        }

        $pairs = [];
        for ($p = 0; $p < count($dstTies); $p++) {
            $pairs[] = [$winners[$p * 2] ?? 0, $winners[$p * 2 + 1] ?? 0];
        }
        $count = fillReguTiesFromTeams($db, $rounds[$labels[$i + 1]], $pairs);
        if ($count > 0) {
            $updated += $count;
            $details[] = 'Advanced regu winners from ' . $labels[$i] . ' → ' . $labels[$i + 1] . '.';
        }
    }

    if ($updated === 0 && !$quiet) {
        $details[] = 'No regu TBD ties were ready to update.';
    }

    return $updated;
}

/**
 * Advance regu championship + consolation ties (3rd place and 5th–8th).
 *
 * @param list<array> $matches
 * @param list<string> $details
 */
function advanceReguConsolationBracket(PDO $db, array $matches, array &$details): int
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

    $updated = advanceReguBracket($db, $championship, $details, true);
    $champRounds = groupReguRoundsByBaseLabel($championship);
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
        $semiTies = collapseReguTies($champRounds[$semiLabel]);
        if (count($semiTies) >= 2) {
            $count = fillReguTiesFromTeams($db, $thirdMatches, [[
                (int) $semiTies[0]['loser'],
                (int) $semiTies[1]['loser'],
            ]]);
            if ($count > 0) {
                $updated += $count;
                $details[] = 'Filled Consolation Final (3rd Place) regus from semi-final losers.';
            }
        }
    }

    $consolationRounds = groupReguRoundsByBaseLabel($consolationPlain);
    $consolationLabels = array_keys($consolationRounds);

    if (!empty($champLabels) && !empty($consolationLabels)) {
        $firstChampTies = collapseReguTies($champRounds[$champLabels[0]]);
        $firstConsolation = $consolationRounds[$consolationLabels[0]];
        $neededLosers = count(groupReguTieShells($firstConsolation)) * 2;
        if ($neededLosers > 0 && count($firstChampTies) >= $neededLosers) {
            $pairs = [];
            for ($i = 0; $i < $neededLosers; $i += 2) {
                $pairs[] = [
                    (int) $firstChampTies[$i]['loser'],
                    (int) ($firstChampTies[$i + 1]['loser'] ?? 0),
                ];
            }
            $count = fillReguTiesFromTeams($db, $firstConsolation, $pairs);
            if ($count > 0) {
                $updated += $count;
                $details[] = "Filled {$count} consolation regu slot(s) from first-round losers.";
            }
        }
    }

    $updated += advanceReguBracket($db, $consolationPlain, $details, true);

    if ($updated === 0) {
        $details[] = 'No regu TBD ties were ready to update.';
    }

    return $updated;
}

/** Strip the regu suffix from a Sepak Takraw match round label. */
function sepakTakrawReguBaseLabel(array $match): string
{
    return trim(preg_replace('/\s*—\s*\d+(?:st|nd|rd)\s+Regu.*$/i', '', (string) ($match['round_label'] ?? '')));
}

function isSepakTakrawReguMatch(array $match, ?PDO $db = null): bool
{
    if (!preg_match('/\d+(?:st|nd|rd)\s+Regu/i', (string) ($match['round_label'] ?? ''))) {
        return false;
    }
    if ($db !== null) {
        $match = enrichMatchWithSport($db, $match);
    }
    $name = (string) ($match['name'] ?? $match['sport_name'] ?? '');

    return isSepakTakrawSport($name);
}

function isSepakTakrawThirdReguMatch(array $match, ?PDO $db = null): bool
{
    return isSepakTakrawReguMatch($match, $db)
        && preg_match('/3rd\s+Regu/i', (string) ($match['round_label'] ?? ''));
}

/**
 * All regu rubbers for the same team tie (1st, 2nd, 3rd Regu).
 *
 * @return list<array>
 */
function getSepakTakrawReguSiblings(PDO $db, array $match): array
{
    $match = enrichMatchWithSport($db, $match);
    if (!isSepakTakrawReguMatch($match, $db)) {
        return [];
    }

    $seasonId = (int) ($match['season_id'] ?? 0);
    $sportId = (int) ($match['sport_id'] ?? 0);
    $teamA = (int) ($match['team_a_id'] ?? 0);
    $teamB = (int) ($match['team_b_id'] ?? 0);
    $baseLabel = sepakTakrawReguBaseLabel($match);
    if (!$seasonId || !$sportId || !$teamA || !$teamB || $baseLabel === '') {
        return [];
    }

    $like = $baseLabel . ' — % Regu';
    $divId = isset($match['division_id']) ? (int) $match['division_id'] : 0;
    if ($divId > 0) {
        $stmt = $db->prepare('SELECT * FROM intramural_matches
            WHERE season_id = ? AND sport_id = ? AND division_id = ?
              AND team_a_id = ? AND team_b_id = ?
              AND round_label LIKE ?
            ORDER BY match_order ASC, id ASC');
        $stmt->execute([$seasonId, $sportId, $divId, $teamA, $teamB, $like]);
    } else {
        $stmt = $db->prepare('SELECT * FROM intramural_matches
            WHERE season_id = ? AND sport_id = ?
              AND (division_id IS NULL OR division_id = 0)
              AND team_a_id = ? AND team_b_id = ?
              AND round_label LIKE ?
            ORDER BY match_order ASC, id ASC');
        $stmt->execute([$seasonId, $sportId, $teamA, $teamB, $like]);
    }

    $siblings = $stmt->fetchAll() ?: [];
    usort($siblings, static function ($a, $b) {
        preg_match('/(\d+)(?:st|nd|rd)\s+Regu/i', (string) ($a['round_label'] ?? ''), $ma);
        preg_match('/(\d+)(?:st|nd|rd)\s+Regu/i', (string) ($b['round_label'] ?? ''), $mb);

        return ((int) ($ma[1] ?? 0)) <=> ((int) ($mb[1] ?? 0));
    });

    return $siblings;
}

/** True when the same team won both 1st and 2nd Regu (tie decided 2–0). */
function reguTieDecidedTwoNil(array $reguMatches): bool
{
    if (count($reguMatches) < 2) {
        return false;
    }

    $wins = [];
    foreach (array_slice($reguMatches, 0, 2) as $m) {
        $out = getMatchOutcome($m);
        if (!$out) {
            return false;
        }
        $w = (int) $out['winner'];
        $wins[$w] = ($wins[$w] ?? 0) + 1;
    }

    return max($wins) >= 2;
}

/**
 * True when this is the 3rd Regu and the tie was already decided 2–0 in regus 1 and 2.
 */
function isSepakTakrawThirdReguDisabled(PDO $db, array $match): bool
{
    $match = enrichMatchWithSport($db, $match);
    if (!isSepakTakrawThirdReguMatch($match, $db)) {
        return false;
    }

    $siblings = getSepakTakrawReguSiblings($db, $match);

    return reguTieDecidedTwoNil($siblings);
}

/**
 * Cancel the 3rd regu when a Sepak Takraw tie is already decided 2–0 after regus 1 and 2.
 */
function maybeCancelUnneededSepakTakrawRegu(PDO $db, int $matchId): int
{
    $stmt = $db->prepare('SELECT m.*, s.name AS sport_name, s.tournament_format
        FROM intramural_matches m
        JOIN intramural_sports s ON s.id = m.sport_id
        WHERE m.id = ?');
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();
    if (!$match) {
        return 0;
    }
    $match = enrichMatchWithSport($db, $match);
    if (!isSepakTakrawReguMatch($match, $db)) {
        return 0;
    }

    $siblings = getSepakTakrawReguSiblings($db, $match);
    if (count($siblings) < 3 || !reguTieDecidedTwoNil($siblings)) {
        return 0;
    }

    $third = $siblings[2];
    if ((string) ($third['status'] ?? '') === 'cancelled') {
        return 0;
    }
    if (in_array((string) ($third['status'] ?? ''), ['completed', 'forfeit'], true)) {
        return 0;
    }

    $cancel = $db->prepare("UPDATE intramural_matches SET status = 'cancelled', score_a = NULL, score_b = NULL, winner_team_id = NULL, forfeit_team_id = NULL, notes = CONCAT(COALESCE(notes, ''), CASE WHEN notes IS NULL OR notes = '' THEN '' ELSE '. ' END, 'Not required — tie decided 2–0 in 1st and 2nd Regu.') WHERE id = ?");
    $cancel->execute([(int) $third['id']]);

    return $cancel->rowCount();
}

/** Strip the SDS rubber suffix from a Team Play SDS match round label. */
function sdsRubberBaseLabel(array $match): string
{
    return trim(preg_replace('/\s*—\s*SDS\s+.*$/i', '', (string) ($match['round_label'] ?? '')));
}

function isSdsRubberMatch(array $match, ?PDO $db = null): bool
{
    if (!preg_match('/\s—\s*SDS\s+(Singles\s+1|Doubles|Singles\s+2)/i', (string) ($match['round_label'] ?? ''))) {
        return false;
    }
    if ($db !== null) {
        $match = enrichMatchWithSport($db, $match);
    }

    return isSdsTournamentFormat($match['tournament_format'] ?? null);
}

function isSdsSingles2RubberMatch(array $match, ?PDO $db = null): bool
{
    return isSdsRubberMatch($match, $db)
        && preg_match('/SDS\s+Singles\s+2/i', (string) ($match['round_label'] ?? ''));
}

/**
 * Determine SDS tie winner when decided (2 rubber wins, or all 3 rubbers played).
 *
 * @param list<array> $rubberMatches
 * @return array{winner:int,loser:int,matches:list<array>}|null
 */
function sdsTieIsDecided(array $rubberMatches): ?array
{
    if (empty($rubberMatches)) {
        return null;
    }

    $wins = [];
    foreach ($rubberMatches as $m) {
        $out = getMatchOutcome($m);
        if (!$out) {
            continue;
        }
        $w = (int) $out['winner'];
        $wins[$w] = ($wins[$w] ?? 0) + 1;
    }
    if (empty($wins)) {
        return null;
    }

    arsort($wins);
    $winner = (int) array_key_first($wins);
    $topWins = (int) ($wins[$winner] ?? 0);
    $completed = array_sum($wins);
    $decided = $topWins >= 2 || $completed >= 3;
    if (!$decided) {
        return null;
    }

    $teamA = (int) ($rubberMatches[0]['team_a_id'] ?? 0);
    $teamB = (int) ($rubberMatches[0]['team_b_id'] ?? 0);
    $loser = $winner === $teamA ? $teamB : $teamA;
    if ($winner > 0 && $loser > 0) {
        return ['winner' => $winner, 'loser' => $loser, 'matches' => $rubberMatches];
    }

    return null;
}

/** Sort SDS rubbers Singles 1 → Doubles → Singles 2. */
function sortSdsRubberSiblings(array $siblings): array
{
    $rank = static function (array $m): int {
        $label = (string) ($m['round_label'] ?? '');
        if (stripos($label, 'SDS Singles 1') !== false) {
            return 1;
        }
        if (stripos($label, 'SDS Doubles') !== false) {
            return 2;
        }
        if (stripos($label, 'SDS Singles 2') !== false) {
            return 3;
        }

        return 99;
    };

    usort($siblings, static fn($a, $b) => $rank($a) <=> $rank($b));

    return $siblings;
}

/**
 * All SDS rubbers for the same team tie (Singles 1, Doubles, Singles 2).
 *
 * @return list<array>
 */
function getSdsRubberSiblings(PDO $db, array $match): array
{
    $match = enrichMatchWithSport($db, $match);
    if (!isSdsRubberMatch($match, $db)) {
        return [];
    }

    $seasonId = (int) ($match['season_id'] ?? 0);
    $sportId = (int) ($match['sport_id'] ?? 0);
    $teamA = (int) ($match['team_a_id'] ?? 0);
    $teamB = (int) ($match['team_b_id'] ?? 0);
    $baseLabel = sdsRubberBaseLabel($match);
    if (!$seasonId || !$sportId || !$teamA || !$teamB || $baseLabel === '') {
        return [];
    }

    $like = $baseLabel . ' — SDS %';
    $divId = isset($match['division_id']) ? (int) $match['division_id'] : 0;
    if ($divId > 0) {
        $stmt = $db->prepare('SELECT * FROM intramural_matches
            WHERE season_id = ? AND sport_id = ? AND division_id = ?
              AND team_a_id = ? AND team_b_id = ?
              AND round_label LIKE ?
            ORDER BY match_order ASC, id ASC');
        $stmt->execute([$seasonId, $sportId, $divId, $teamA, $teamB, $like]);
    } else {
        $stmt = $db->prepare('SELECT * FROM intramural_matches
            WHERE season_id = ? AND sport_id = ?
              AND (division_id IS NULL OR division_id = 0)
              AND team_a_id = ? AND team_b_id = ?
              AND round_label LIKE ?
            ORDER BY match_order ASC, id ASC');
        $stmt->execute([$seasonId, $sportId, $teamA, $teamB, $like]);
    }

    return sortSdsRubberSiblings($stmt->fetchAll() ?: []);
}

/** True when the same team won both Singles 1 and Doubles (tie decided 2–0). */
function sdsTieDecidedTwoNil(array $rubberMatches): bool
{
    return reguTieDecidedTwoNil($rubberMatches);
}

/** True when this is SDS Singles 2 and the tie was already decided 2–0. */
function isSdsSingles2Disabled(PDO $db, array $match): bool
{
    $match = enrichMatchWithSport($db, $match);
    if (!isSdsSingles2RubberMatch($match, $db)) {
        return false;
    }

    $siblings = getSdsRubberSiblings($db, $match);

    return sdsTieDecidedTwoNil($siblings);
}

function stripAutoCancelledDecidingNote(?string $notes, string $needle): ?string
{
    $text = trim((string) $notes);
    if ($text === '') {
        return null;
    }
    $pattern = '/(?:^|\.\s*)' . preg_quote($needle, '/') . '\.?/iu';
    $text = trim(preg_replace($pattern, '', $text) ?? $text);
    $text = trim($text, " \t\n\r\0\x0B.");

    return $text !== '' ? $text : null;
}

/**
 * Re-open SDS Singles 2 when Singles 1 / Doubles no longer make the tie 2–0
 * (e.g. Singles 1 winner was corrected).
 */
function maybeRestoreNeededSdsSingles2(PDO $db, int $matchId): int
{
    $stmt = $db->prepare('SELECT m.*, s.name AS sport_name, s.tournament_format
        FROM intramural_matches m
        JOIN intramural_sports s ON s.id = m.sport_id
        WHERE m.id = ?');
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();
    if (!$match) {
        return 0;
    }
    $match = enrichMatchWithSport($db, $match);
    if (!isSdsRubberMatch($match, $db)) {
        return 0;
    }

    $siblings = getSdsRubberSiblings($db, $match);
    $deciding = null;
    foreach ($siblings as $rubber) {
        if (isSdsSingles2RubberMatch($rubber, $db)) {
            $deciding = $rubber;
            break;
        }
    }
    if (!$deciding || (string) ($deciding['status'] ?? '') !== 'cancelled') {
        return 0;
    }
    if (sdsTieDecidedTwoNil($siblings)) {
        return 0;
    }
    if (in_array((string) ($deciding['status'] ?? ''), ['completed', 'forfeit', 'ongoing'], true)) {
        return 0;
    }

    $notes = stripAutoCancelledDecidingNote(
        $deciding['notes'] ?? null,
        'Not required — tie decided 2–0 in SDS Singles 1 and Doubles'
    );
    $restore = $db->prepare("UPDATE intramural_matches
        SET status = 'scheduled', score_a = NULL, score_b = NULL, winner_team_id = NULL, forfeit_team_id = NULL, notes = ?
        WHERE id = ? AND status = 'cancelled'");
    $restore->execute([$notes, (int) $deciding['id']]);
    $updated = $restore->rowCount();
    if ($updated > 0 && !empty($deciding['season_id'])) {
        recalculateVenueGameNumbers((int) $deciding['season_id']);
    }

    return $updated;
}

/**
 * Re-open 3rd Regu when 1st/2nd Regu no longer make the tie 2–0.
 */
function maybeRestoreNeededSepakTakrawRegu(PDO $db, int $matchId): int
{
    $stmt = $db->prepare('SELECT m.*, s.name AS sport_name, s.tournament_format
        FROM intramural_matches m
        JOIN intramural_sports s ON s.id = m.sport_id
        WHERE m.id = ?');
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();
    if (!$match) {
        return 0;
    }
    $match = enrichMatchWithSport($db, $match);
    if (!isSepakTakrawReguMatch($match, $db)) {
        return 0;
    }

    $siblings = getSepakTakrawReguSiblings($db, $match);
    $third = $siblings[2] ?? null;
    if (!$third || (string) ($third['status'] ?? '') !== 'cancelled') {
        return 0;
    }
    if (reguTieDecidedTwoNil($siblings)) {
        return 0;
    }

    $notes = stripAutoCancelledDecidingNote(
        $third['notes'] ?? null,
        'Not required — tie decided 2–0 in 1st and 2nd Regu'
    );
    $restore = $db->prepare("UPDATE intramural_matches
        SET status = 'scheduled', score_a = NULL, score_b = NULL, winner_team_id = NULL, forfeit_team_id = NULL, notes = ?
        WHERE id = ? AND status = 'cancelled'");
    $restore->execute([$notes, (int) $third['id']]);
    $updated = $restore->rowCount();
    if ($updated > 0 && !empty($third['season_id'])) {
        recalculateVenueGameNumbers((int) $third['season_id']);
    }

    return $updated;
}

/** Cancel SDS Singles 2 when Singles 1 and Doubles were both won by the same team. */
function maybeCancelUnneededSdsSingles2(PDO $db, int $matchId): int
{
    $stmt = $db->prepare('SELECT m.*, s.name AS sport_name, s.tournament_format
        FROM intramural_matches m
        JOIN intramural_sports s ON s.id = m.sport_id
        WHERE m.id = ?');
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();
    if (!$match) {
        return 0;
    }
    $match = enrichMatchWithSport($db, $match);
    if (!isSdsRubberMatch($match, $db)) {
        return 0;
    }

    $siblings = getSdsRubberSiblings($db, $match);
    if (count($siblings) < 3 || !sdsTieDecidedTwoNil($siblings)) {
        return 0;
    }

    $deciding = $siblings[2];
    if ((string) ($deciding['status'] ?? '') === 'cancelled') {
        return 0;
    }
    if (in_array((string) ($deciding['status'] ?? ''), ['completed', 'forfeit'], true)) {
        return 0;
    }

    $cancel = $db->prepare("UPDATE intramural_matches SET status = 'cancelled', score_a = NULL, score_b = NULL, winner_team_id = NULL, forfeit_team_id = NULL, notes = CONCAT(COALESCE(notes, ''), CASE WHEN notes IS NULL OR notes = '' THEN '' ELSE '. ' END, 'Not required — tie decided 2–0 in SDS Singles 1 and Doubles.') WHERE id = ?");
    $cancel->execute([(int) $deciding['id']]);

    return $cancel->rowCount();
}

/** Cancel or restore deciding rubbers (SDS Singles 2 / Sepak 3rd Regu) from current 2–0 state. */
function maybeCancelUnneededDecidingRubber(PDO $db, int $matchId): int
{
    return maybeRestoreNeededSdsSingles2($db, $matchId)
        + maybeRestoreNeededSepakTakrawRegu($db, $matchId)
        + maybeCancelUnneededSepakTakrawRegu($db, $matchId)
        + maybeCancelUnneededSdsSingles2($db, $matchId);
}

/**
 * Re-activate auto-cancelled SDS Singles 2 (and Sepak 3rd Regu) when 2–0 no longer holds.
 */
function restoreNeededDecidingRubbersForSeason(int $seasonId, ?int $sportId = null): int
{
    if ($seasonId <= 0) {
        return 0;
    }
    $db = getDB();
    $sql = "SELECT m.id
        FROM intramural_matches m
        JOIN intramural_sports s ON s.id = m.sport_id
        WHERE m.season_id = ?
          AND m.status = 'cancelled'
          AND (
            m.round_label LIKE '%SDS Singles 2%'
            OR m.round_label LIKE '%3rd Regu%'
          )";
    $params = [$seasonId];
    if ($sportId && $sportId > 0) {
        $sql .= ' AND m.sport_id = ?';
        $params[] = $sportId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $updated = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
        $updated += maybeRestoreNeededSdsSingles2($db, (int) $id);
        $updated += maybeRestoreNeededSepakTakrawRegu($db, (int) $id);
    }

    return $updated;
}

function isDecidingRubberDisabled(PDO $db, array $match): bool
{
    return isSepakTakrawThirdReguDisabled($db, $match)
        || isSdsSingles2Disabled($db, $match);
}

function decidingRubberDisabledMessage(PDO $db, array $match): ?string
{
    if (isSepakTakrawThirdReguDisabled($db, $match)) {
        return '3rd Regu is not required — one team already won 1st and 2nd Regu (2–0). This match is disabled.';
    }
    if (isSdsSingles2Disabled($db, $match)) {
        return 'SDS Singles 2 is not required — one team already won Singles 1 and Doubles (2–0). This match is disabled.';
    }

    return null;
}

function decidingRubberDisabledAlert(PDO $db, array $match): ?string
{
    if (isSepakTakrawThirdReguDisabled($db, $match)) {
        return 'One team already won both the 1st and 2nd Regu (2–0), so this deciding regu is not required and cannot be scored.';
    }
    if (isSdsSingles2Disabled($db, $match)) {
        return 'One team already won both SDS Singles 1 and Doubles (2–0), so the deciding Singles 2 rubber is not required and cannot be scored.';
    }

    return null;
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
        'unfinished_games' => 0,
        'registered_athletes' => 0,
    ];

    $tmScoped = isTournamentManager() && !canManageIntramurals();
    $tmSportIds = $tmScoped ? getTmSportIds() : [];
    $umTeamId = getUnitManagerTeamScopeId();

    try {
        if ($tmScoped) {
            $stats['total_sports'] = count($tmSportIds);
            if ($tmSportIds && $seasonId) {
                $placeholders = implode(',', array_fill(0, count($tmSportIds), '?'));
                $params = array_merge([(int) $seasonId], $tmSportIds);

                $stmt = $db->prepare("SELECT COUNT(DISTINCT r.team_id)
                    FROM intramural_registrations r
                    WHERE r.season_id = ? AND r.sport_id IN ($placeholders)");
                $stmt->execute($params);
                $stats['total_teams'] = (int) $stmt->fetchColumn();

                $stmt = $db->prepare("SELECT COUNT(DISTINCT r.athlete_id)
                    FROM intramural_registrations r
                    WHERE r.season_id = ? AND r.sport_id IN ($placeholders)");
                $stmt->execute($params);
                $stats['registered_athletes'] = (int) $stmt->fetchColumn();
                $stats['total_athletes'] = $stats['registered_athletes'];

                $stmt = $db->prepare("SELECT COUNT(*) FROM intramural_matches
                    WHERE status = 'scheduled' AND season_id = ? AND sport_id IN ($placeholders)");
                $stmt->execute($params);
                $stats['scheduled_games'] = (int) $stmt->fetchColumn();

                $stmt = $db->prepare("SELECT COUNT(*) FROM intramural_matches
                    WHERE status IN ('completed', 'forfeit') AND season_id = ? AND sport_id IN ($placeholders)");
                $stmt->execute($params);
                $stats['completed_games'] = (int) $stmt->fetchColumn();

                $stmt = $db->prepare("SELECT COUNT(*) FROM intramural_matches
                    WHERE status = 'ongoing' AND season_id = ? AND sport_id IN ($placeholders)");
                $stmt->execute($params);
                $stats['ongoing_games'] = (int) $stmt->fetchColumn();
            }
        } elseif ($umTeamId !== null) {
            $stats['total_teams'] = $umTeamId > 0 ? 1 : 0;
            if ($umTeamId > 0 && $seasonId) {
                $stmt = $db->prepare('SELECT COUNT(DISTINCT r.athlete_id)
                    FROM intramural_registrations r
                    WHERE r.season_id = ? AND r.team_id = ?');
                $stmt->execute([$seasonId, $umTeamId]);
                $stats['registered_athletes'] = (int) $stmt->fetchColumn();
                $stats['total_athletes'] = $stats['registered_athletes'];

                $stmt = $db->prepare('SELECT COUNT(DISTINCT sport_id)
                    FROM intramural_registrations
                    WHERE season_id = ? AND team_id = ?');
                $stmt->execute([$seasonId, $umTeamId]);
                $stats['total_sports'] = (int) $stmt->fetchColumn();

                $matchCountSql = static function (string $statusSql) use ($db, $seasonId, $umTeamId): int {
                    $sql = "SELECT COUNT(*) FROM intramural_matches m
                        WHERE m.season_id = ? AND ($statusSql)
                          AND (m.team_a_id = ? OR m.team_b_id = ?)";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([$seasonId, $umTeamId, $umTeamId]);

                    return (int) $stmt->fetchColumn();
                };

                $stats['scheduled_games'] = $matchCountSql("m.status = 'scheduled'");
                $stats['completed_games'] = $matchCountSql("m.status IN ('completed', 'forfeit')");
                $stats['ongoing_games'] = $matchCountSql("m.status = 'ongoing'");
            }
        } else {
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
        }

        $stats['unfinished_games'] = (int) $stats['scheduled_games'] + (int) $stats['ongoing_games'];
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
    appendUnitManagerMatchFilter($whereClause, $params);
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

/**
 * Unfinished matches for dashboards (scheduled / ongoing), scoped for tournament managers.
 *
 * @return list<array<string, mixed>>
 */
function getDashboardUnfinishedMatches(int $limit = 12, ?int $seasonId = null): array
{
    $db = getDB();
    $seasonId = $seasonId ?? getCurrentSeasonId();
    if (!$seasonId) {
        return [];
    }

    $where = ['m.season_id = ?', "m.status IN ('scheduled', 'ongoing')"];
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
    appendUnitManagerMatchFilter($whereClause, $params);
    $stmt = $db->prepare("
        SELECT m.*, s.name AS sport_name, s.category AS sport_category,
               ta.name AS team_a_name, ta.color AS team_a_color,
               tb.name AS team_b_name, tb.color AS team_b_color
        FROM intramural_matches m
        JOIN intramural_sports s ON m.sport_id = s.id
        LEFT JOIN intramural_teams ta ON m.team_a_id = ta.id
        LEFT JOIN intramural_teams tb ON m.team_b_id = tb.id
        WHERE $whereClause
        ORDER BY
            CASE WHEN m.status = 'ongoing' THEN 0 ELSE 1 END,
            (m.scheduled_at IS NULL) DESC,
            m.scheduled_at ASC,
            m.match_order ASC,
            m.id ASC
        LIMIT " . (int) $limit . "
    ");
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
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

function sepakConsolationPlacementLabel(int $rank): string
{
    return match ($rank) {
        1 => 'Champion',
        2 => '1st Runner Up',
        3 => '3rd Place',
        4 => '4th Place',
        default => getPlacementLabel($rank),
    };
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

/** Manual per-event team ranks that feed medals and overall standing (scoped by division). */
function ensureEventRanksTable(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $tableStmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $colStmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $tableStmt->execute(['intramural_event_ranks']);
    if ((int) $tableStmt->fetchColumn() === 0) {
        $db->exec("CREATE TABLE intramural_event_ranks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            season_id INT NOT NULL,
            sport_id INT NOT NULL,
            division_id INT NOT NULL DEFAULT 0,
            team_id INT NOT NULL,
            place_rank INT NOT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_event_rank_team (season_id, sport_id, division_id, team_id),
            UNIQUE KEY uq_event_rank_place (season_id, sport_id, division_id, place_rank),
            FOREIGN KEY (season_id) REFERENCES intramural_seasons(id) ON DELETE CASCADE,
            FOREIGN KEY (sport_id) REFERENCES intramural_sports(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES intramural_teams(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
        return;
    }

    $colStmt->execute(['intramural_event_ranks', 'division_id']);
    if ((int) $colStmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE intramural_event_ranks ADD COLUMN division_id INT NOT NULL DEFAULT 0 AFTER sport_id');
        try {
            $db->exec('ALTER TABLE intramural_event_ranks DROP INDEX uq_event_rank_team');
        } catch (Throwable $e) {
        }
        try {
            $db->exec('ALTER TABLE intramural_event_ranks DROP INDEX uq_event_rank_place');
        } catch (Throwable $e) {
        }
        try {
            $db->exec('ALTER TABLE intramural_event_ranks ADD UNIQUE KEY uq_event_rank_team (season_id, sport_id, division_id, team_id)');
            $db->exec('ALTER TABLE intramural_event_ranks ADD UNIQUE KEY uq_event_rank_place (season_id, sport_id, division_id, place_rank)');
        } catch (Throwable $e) {
        }
        // Backfill division from each team's current division assignment.
        try {
            $db->exec('UPDATE intramural_event_ranks r
                JOIN intramural_teams t ON t.id = r.team_id
                SET r.division_id = COALESCE(t.division_id, 0)
                WHERE r.division_id = 0');
        } catch (Throwable $e) {
        }
    }
}

/**
 * Normalize division key for ranks/matches (0 = unassigned / no division).
 */
function eventRankDivisionKey(?int $divisionId): int
{
    return ($divisionId !== null && $divisionId > 0) ? $divisionId : 0;
}

/**
 * @return array<int, array{team_id:int,place_rank:int,notes:?string,division_id:int}>
 */
function getEventRanks(int $sportId, int $seasonId, ?int $divisionId = null): array
{
    ensureEventRanksTable();
    if ($sportId <= 0 || $seasonId <= 0) {
        return [];
    }

    $db = getDB();
    if ($divisionId === null) {
        $stmt = $db->prepare('SELECT team_id, place_rank, notes, division_id FROM intramural_event_ranks
            WHERE sport_id = ? AND season_id = ? ORDER BY division_id ASC, place_rank ASC');
        $stmt->execute([$sportId, $seasonId]);
    } else {
        $divKey = eventRankDivisionKey($divisionId);
        $stmt = $db->prepare('SELECT team_id, place_rank, notes, division_id FROM intramural_event_ranks
            WHERE sport_id = ? AND season_id = ? AND division_id = ? ORDER BY place_rank ASC');
        $stmt->execute([$sportId, $seasonId, $divKey]);
    }

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['team_id']] = [
            'team_id' => (int) $row['team_id'],
            'place_rank' => (int) $row['place_rank'],
            'notes' => $row['notes'] ?? null,
            'division_id' => (int) ($row['division_id'] ?? 0),
        ];
    }

    return $out;
}

function eventHasManualRanks(int $sportId, int $seasonId, ?int $divisionId = null): bool
{
    return getEventRanks($sportId, $seasonId, $divisionId) !== [];
}

/**
 * Whether the event (or one division of it) already has a match schedule.
 * Those brackets use match results for placement instead of manual ranks.
 */
function eventHasScheduledMatches(int $sportId, int $seasonId, ?int $divisionId = null): bool
{
    if ($sportId <= 0 || $seasonId <= 0) {
        return false;
    }

    ensureMatchDivisionColumn();
    $db = getDB();
    if ($divisionId === null) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM intramural_matches
            WHERE sport_id = ? AND season_id = ? AND status <> 'cancelled'");
        $stmt->execute([$sportId, $seasonId]);
    } else {
        $divKey = eventRankDivisionKey($divisionId);
        if ($divKey === 0) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM intramural_matches
                WHERE sport_id = ? AND season_id = ? AND status <> 'cancelled'
                  AND (division_id IS NULL OR division_id = 0)");
            $stmt->execute([$sportId, $seasonId]);
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM intramural_matches
                WHERE sport_id = ? AND season_id = ? AND status <> 'cancelled' AND division_id = ?");
            $stmt->execute([$sportId, $seasonId, $divKey]);
        }
    }

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Finished vs unfinished match counts for one event division.
 *
 * @return array{unfinished: int, finished: int}
 */
function getEventDivisionMatchCounts(int $sportId, int $seasonId, ?int $divisionId = null): array
{
    if ($sportId <= 0 || $seasonId <= 0) {
        return ['unfinished' => 0, 'finished' => 0];
    }

    ensureMatchDivisionColumn();
    $db = getDB();
    $sql = "SELECT
            SUM(CASE WHEN status IN ('scheduled', 'ongoing') THEN 1 ELSE 0 END) AS unfinished,
            SUM(CASE WHEN status IN ('completed', 'forfeit') THEN 1 ELSE 0 END) AS finished
        FROM intramural_matches
        WHERE sport_id = ? AND season_id = ? AND status <> 'cancelled'";

    if ($divisionId === null) {
        $stmt = $db->prepare($sql);
        $stmt->execute([$sportId, $seasonId]);
    } else {
        $divKey = eventRankDivisionKey($divisionId);
        if ($divKey === 0) {
            $stmt = $db->prepare($sql . ' AND (division_id IS NULL OR division_id = 0)');
            $stmt->execute([$sportId, $seasonId]);
        } else {
            $stmt = $db->prepare($sql . ' AND division_id = ?');
            $stmt->execute([$sportId, $seasonId, $divKey]);
        }
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'unfinished' => (int) ($row['unfinished'] ?? 0),
        'finished' => (int) ($row['finished'] ?? 0),
    ];
}

/**
 * Whether an event division is finished (all its matches done, or manual ranks with no open fixtures).
 */
function isEventDivisionFinished(int $sportId, int $seasonId, ?int $divisionId = null): bool
{
    if ($sportId <= 0 || $seasonId <= 0) {
        return false;
    }

    $counts = getEventDivisionMatchCounts($sportId, $seasonId, $divisionId);
    if ($counts['finished'] > 0 && $counts['unfinished'] === 0) {
        return true;
    }
    if ($counts['unfinished'] > 0) {
        return false;
    }

    $ranks = getEventRanks($sportId, $seasonId, $divisionId);
    foreach ($ranks as $rank) {
        if ((int) ($rank['place_rank'] ?? 0) > 0) {
            return true;
        }
    }

    return false;
}

/**
 * Team IDs that belong in the event ranking UI (roster first, else eligible houses).
 *
 * @return list<int>
 */
function getEventParticipatingTeamIds(int $sportId, int $seasonId, ?int $divisionId = null): array
{
    $db = getDB();
    $ids = [];
    if ($seasonId > 0 && $sportId > 0) {
        $stmt = $db->prepare('SELECT DISTINCT team_id FROM intramural_registrations WHERE sport_id = ? AND season_id = ? ORDER BY team_id');
        $stmt->execute([$sportId, $seasonId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
    foreach (getEventRanks($sportId, $seasonId, $divisionId) as $tid => $_row) {
        if (!in_array((int) $tid, $ids, true)) {
            $ids[] = (int) $tid;
        }
    }

    $ids = array_values(array_filter($ids, static fn($id) => $id > 0));
    if ($sportId > 0 && function_exists('teamCanPlaySport')) {
        $ids = array_values(array_filter($ids, static fn($id) => teamCanPlaySport((int) $id, $sportId)));
    }

    if ($divisionId !== null) {
        $divKey = eventRankDivisionKey($divisionId);
        $filtered = [];
        foreach ($ids as $tid) {
            $teamDiv = getTeamDivisionId((int) $tid);
            $teamKey = eventRankDivisionKey($teamDiv);
            if ($teamKey === $divKey) {
                $filtered[] = (int) $tid;
            }
        }
        $ids = $filtered;
    }

    return $ids;
}

/**
 * Save official event ranks for one division bracket.
 * Empty/zero rank removes that team. Duplicate places within the division are rejected.
 *
 * @param array<int, int> $ranksByTeam team_id => place_rank (0 = clear)
 * @return list<string> validation errors
 */
function saveEventRanks(int $sportId, int $seasonId, array $ranksByTeam, ?int $userId = null, ?int $divisionId = 0): array
{
    ensureEventRanksTable();
    $divKey = eventRankDivisionKey($divisionId);
    $errors = [];
    if ($sportId <= 0 || $seasonId <= 0) {
        return ['Select a season and event before saving ranks.'];
    }
    if (eventHasScheduledMatches($sportId, $seasonId, $divKey)) {
        return ['Manual ranking is not allowed for this division while it has scheduled matches. Placement follows match results.'];
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
        $teamDiv = eventRankDivisionKey(getTeamDivisionId($teamId));
        if ($teamDiv !== $divKey) {
            $errors[] = 'Team does not belong to the selected division.';
            continue;
        }
        if (isset($usedPlaces[$place])) {
            $errors[] = 'Each place can only be assigned to one team in this division (duplicate rank ' . $place . ').';
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
        $db->prepare('DELETE FROM intramural_event_ranks WHERE sport_id = ? AND season_id = ? AND division_id = ?')
            ->execute([$sportId, $seasonId, $divKey]);
        $insert = $db->prepare('INSERT INTO intramural_event_ranks (season_id, sport_id, division_id, team_id, place_rank, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($clean as $teamId => $place) {
            $insert->execute([$seasonId, $sportId, $divKey, $teamId, $place, $userId]);
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
 * SDS / single-elim consolation places from decided team ties (bracket finish).
 *
 * Final winner = Champion, Final loser = 1st runner-up.
 * 3rd-place winner = 3rd, 3rd-place loser = last of that pair (4th in a 4-team draw).
 *
 * @param list<array> $matches Completed/forfeit matches for one division
 * @return array<int, int> team_id => place (1 = champion)
 */
function computeSdsConsolationPlaces(array $matches): array
{
    $places = [];
    $assign = static function (int $teamId, int $place) use (&$places): void {
        if ($teamId > 0 && !isset($places[$teamId])) {
            $places[$teamId] = $place;
        }
    };

    $assignFromOutcome = static function (?array $out, int $winPlace, int $losePlace) use ($assign): void {
        if (!$out) {
            return;
        }
        $assign((int) $out['winner'], $winPlace);
        $assign((int) $out['loser'], $losePlace);
    };

    $hasSds = false;
    $hasRegu = false;
    foreach ($matches as $m) {
        $label = (string) ($m['round_label'] ?? '');
        if (stripos($label, 'SDS') !== false) {
            $hasSds = true;
        }
        if (preg_match('/\bRegu\b/i', $label)) {
            $hasRegu = true;
        }
    }

    $collapseTies = static function (array $group) use ($hasSds, $hasRegu): array {
        if ($hasSds) {
            return collapseSdsTies($group);
        }
        if ($hasRegu) {
            return collapseReguTies($group);
        }
        $ties = [];
        foreach ($group as $m) {
            $out = getMatchOutcome($m);
            if ($out) {
                $ties[] = $out;
            }
        }

        return $ties;
    };

    $championship = [];
    $consolation = [];
    foreach ($matches as $m) {
        if (isConsolationMatch($m) || isThirdPlaceMatch($m)) {
            $consolation[] = $m;
        } else {
            $championship[] = $m;
        }
    }

    $finalMatches = [];
    foreach ($championship as $m) {
        if (isChampionshipFinalMatch($m)) {
            $finalMatches[] = $m;
        }
    }
    foreach ($collapseTies($finalMatches) as $tie) {
        $assignFromOutcome($tie, 1, 2);
    }

    $thirdMatches = [];
    $consolationPlain = [];
    foreach ($consolation as $cm) {
        if (isThirdPlaceMatch($cm)) {
            $thirdMatches[] = $cm;
        } else {
            $consolationPlain[] = $cm;
        }
    }
    foreach ($collapseTies($thirdMatches) as $tie) {
        $assignFromOutcome($tie, 3, 4);
    }

    $consolRounds = [];
    foreach ($consolationPlain as $cm) {
        $base = bracketTieBaseLabel($cm);
        if ($base === '') {
            $base = trim((string) ($cm['round_label'] ?? 'Consolation'));
        }
        $consolRounds[$base][] = $cm;
    }
    $roundMeta = [];
    foreach ($consolRounds as $roundMatches) {
        $rn = 0;
        foreach ($roundMatches as $m) {
            $rn = max($rn, (int) ($m['round_number'] ?? 0));
        }
        $roundMeta[] = ['matches' => $roundMatches, 'round_number' => $rn];
    }
    usort($roundMeta, static fn(array $a, array $b): int => $b['round_number'] <=> $a['round_number']);

    $nextPlace = 5;
    foreach ($roundMeta as $round) {
        foreach ($collapseTies($round['matches']) as $tie) {
            $winner = (int) ($tie['winner'] ?? 0);
            $loser = (int) ($tie['loser'] ?? 0);
            if ($winner > 0 && !isset($places[$winner])) {
                $assign($winner, $nextPlace++);
            }
            if ($loser > 0 && !isset($places[$loser])) {
                $assign($loser, $nextPlace++);
            }
        }
    }

    return $places;
}

function matchesHaveConsolationBracketPlaces(array $matches): bool
{
    $hasFinal = false;
    $hasThird = false;
    foreach ($matches as $m) {
        if (isChampionshipFinalMatch($m)) {
            $hasFinal = true;
        }
        if (isThirdPlaceMatch($m)) {
            $hasThird = true;
        }
    }

    return $hasFinal && $hasThird;
}

function usesConsolationBracketRanking(string $format, array $matches): bool
{
    if (in_array($format, [
        'team_play_sds_consolation',
        'single_elimination_consolation',
    ], true)) {
        return true;
    }

    return matchesHaveConsolationBracketPlaces($matches);
}

/**
 * Completed matches that belong to a standings division bucket.
 *
 * @param list<array> $matches
 * @return list<array>
 */
function filterMatchesForStandingsDivision(array $matches, int $divKey): array
{
    $out = [];
    foreach ($matches as $m) {
        $matchDiv = eventRankDivisionKey(
            isset($m['division_id']) && $m['division_id'] !== '' && $m['division_id'] !== null
                ? (int) $m['division_id']
                : null
        );
        if ($matchDiv === $divKey) {
            $out[] = $m;
            continue;
        }
        if ($matchDiv !== 0 || $divKey <= 0) {
            continue;
        }
        $teamA = (int) ($m['team_a_id'] ?? 0);
        $teamB = (int) ($m['team_b_id'] ?? 0);
        if (
            ($teamA > 0 && eventRankDivisionKey(getTeamDivisionId($teamA)) === $divKey)
            || ($teamB > 0 && eventRankDivisionKey(getTeamDivisionId($teamB)) === $divKey)
        ) {
            $out[] = $m;
        }
    }

    return $out;
}

/**
 * Compute standings for a sport (or all sports if sportId is null).
 * Match W/D/L points determine event ranking; placement points come from the sport's point scheme.
 * Tie-breakers: match points → wins → point differential → points for.
 * Team Play SDS / Single Elimination with Consolation uses bracket finish
 * (Final → Champion & 1st Runner Up; 3rd-place game → 3rd & last), not score differential.
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
            $row['division_id'] = eventRankDivisionKey(getTeamDivisionId((int) $row['team_id']));
            $div = $row['division_id'] > 0 ? getDivisionById($row['division_id']) : null;
            $row['division_name'] = $div['name'] ?? ($row['division_id'] > 0 ? ('Division #' . $row['division_id']) : 'Unassigned');
        }
        unset($row);

        $manualRanks = $seasonId ? getEventRanks($sid, (int) $seasonId) : [];
        if ($manualRanks && $seasonId) {
            $usableManual = [];
            foreach ($manualRanks as $tid => $rankRow) {
                $divKey = (int) ($rankRow['division_id'] ?? eventRankDivisionKey(getTeamDivisionId((int) $tid)));
                if (!eventHasScheduledMatches($sid, (int) $seasonId, $divKey)) {
                    $usableManual[(int) $tid] = $rankRow;
                }
            }
            $manualRanks = $usableManual;
        }

        // Rank / place medals within each division separately.
        $byDivision = [];
        foreach ($standings as $row) {
            $divKey = (int) ($row['division_id'] ?? 0);
            $byDivision[$divKey][] = $row;
        }
        ksort($byDivision);

        $flatRows = [];
        $divisionBlocks = [];
        $anyManual = false;
        $eventFinished = $seasonId ? isEventFinished($sid, (int) $seasonId) : false;

        foreach ($byDivision as $divKey => $divRows) {
            $divId = $divKey > 0 ? $divKey : null;
            // Medals / placement points per division once that division's matches are finished.
            $divFinished = $seasonId ? isEventDivisionFinished($sid, (int) $seasonId, $divId) : false;
            $format = (string) ($sport['tournament_format'] ?? '');
            $divMatches = filterMatchesForStandingsDivision($matches, (int) $divKey);
            $placeByTeam = [];
            if (usesConsolationBracketRanking($format, $divMatches)) {
                $placeByTeam = computeSdsConsolationPlaces($divMatches);
            }
            if ($placeByTeam !== []) {
                usort($divRows, static function ($x, $y) use ($placeByTeam) {
                    $px = $placeByTeam[(int) $x['team_id']] ?? 1000;
                    $py = $placeByTeam[(int) $y['team_id']] ?? 1000;
                    if ($px !== $py) {
                        return $px <=> $py;
                    }
                    return strcasecmp((string) $x['team_name'], (string) $y['team_name']);
                });
            } else {
                usort($divRows, static function ($x, $y) {
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
            }

            $divManual = [];
            foreach ($divRows as $row) {
                $tid = (int) $row['team_id'];
                if (isset($manualRanks[$tid])) {
                    $divManual[$tid] = $manualRanks[$tid];
                }
            }
            if ($divManual) {
                $anyManual = true;
            }

            $rank = 1;
            foreach ($divRows as &$row) {
                $tid = (int) $row['team_id'];
                $bracketPlace = $placeByTeam[$tid] ?? null;
                $row['rank'] = $bracketPlace ?? $rank;
                $rank++;
                $row['medal'] = null;
                $row['placement_points'] = 0;
                $row['placement_label'] = null;
                $row['manual_rank'] = false;
                if ($row['played'] > 0 && $divFinished) {
                    applyEventPlacement($row, (int) $row['rank'], $scheme, false);
                    if (isSepakTakrawSport((string) ($sport['name'] ?? '')) && $placeByTeam !== []) {
                        $row['placement_label'] = sepakConsolationPlacementLabel((int) $row['rank']);
                    }
                }
            }
            unset($row);

            if ($divManual) {
                foreach ($divRows as &$row) {
                    $tid = (int) $row['team_id'];
                    if (isset($divManual[$tid])) {
                        applyEventPlacement($row, (int) $divManual[$tid]['place_rank'], $scheme, true);
                    } elseif ((int) $row['played'] === 0) {
                        $row['rank'] = 1000;
                        $row['medal'] = null;
                        $row['placement_points'] = 0;
                        $row['placement_label'] = null;
                        $row['manual_rank'] = false;
                    }
                }
                unset($row);
                usort($divRows, static function ($x, $y) {
                    if ($x['rank'] !== $y['rank']) {
                        return $x['rank'] <=> $y['rank'];
                    }
                    return strcasecmp((string) $x['team_name'], (string) $y['team_name']);
                });
            }

            $divisionName = $divRows[0]['division_name'] ?? ($divKey > 0 ? ('Division #' . $divKey) : 'Unassigned');
            $divisionBlocks[] = [
                'division_id' => $divKey > 0 ? $divKey : null,
                'division_key' => (int) $divKey,
                'division_name' => $divisionName,
                'standings' => $divRows,
                'manual_ranks' => $divManual !== [],
                'event_finished' => $divFinished,
            ];
            foreach ($divRows as $row) {
                $flatRows[] = $row;
            }
        }

        $result[$sid] = [
            'sport' => $sport,
            'scheme' => $scheme,
            'standings' => $flatRows,
            'divisions' => $divisionBlocks,
            'manual_ranks' => $anyManual,
            'event_finished' => $eventFinished,
        ];
    }

    return $result;
}

/**
 * Overall intramurals standing uses placement points from each event's point scheme.
 * Also returns medal tally / breakdown grouped by division.
 */
function computeOverallStandings(): array
{
    $all = computeSportStandings(null);
    $db = getDB();
    ensureIntramuralDivisionsSchema();
    $teams = $db->query('SELECT * FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
    $overall = [];

    foreach ($teams as $team) {
        $divKey = eventRankDivisionKey(
            isset($team['division_id']) && $team['division_id'] !== '' && $team['division_id'] !== null
                ? (int) $team['division_id']
                : null
        );
        $div = $divKey > 0 ? getDivisionById($divKey) : null;
        $overall[(int) $team['id']] = [
            'team_id' => (int) $team['id'],
            'team_name' => $team['name'],
            'short_name' => $team['short_name'] ?: $team['name'],
            'color' => $team['color'],
            'division_id' => $divKey > 0 ? $divKey : null,
            'division_key' => $divKey,
            'division_name' => $div['name'] ?? ($divKey > 0 ? ('Division #' . $divKey) : 'Unassigned'),
            'sports' => [],
            'sport_medals' => [],
            'total' => 0,
            'gold' => 0,
            'silver' => 0,
            'bronze' => 0,
        ];
    }

    $sportLabels = [];
    $sportIdByLabel = [];
    foreach ($all as $sid => $block) {
        $sid = (int) $sid;
        $sportKey = sportLabel($block['sport']);
        $sportLabels[] = $sportKey;
        $sportIdByLabel[$sportKey] = $sid;
        foreach ($block['standings'] as $row) {
            $tid = $row['team_id'];
            if (!isset($overall[$tid])) {
                continue;
            }
            // Only finished events (or manual ranks) contribute to overall / medal tally.
            $hasPlacement = !empty($row['manual_rank']) || (int) $row['placement_points'] > 0 || !empty($row['medal']);
            if (!$hasPlacement) {
                continue;
            }
            $pts = (int) $row['placement_points'];
            $overall[$tid]['sports'][$sportKey] = $pts;
            $overall[$tid]['sport_medals'][$sportKey] = $row['medal'] ?? null;
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

    usort($sportLabels, static function (string $labelA, string $labelB) use ($sportIdByLabel, $all): int {
        $sidA = (int) ($sportIdByLabel[$labelA] ?? 0);
        $sidB = (int) ($sportIdByLabel[$labelB] ?? 0);
        $nameA = strtolower((string) ($all[$sidA]['sport']['name'] ?? $labelA));
        $nameB = strtolower((string) ($all[$sidB]['sport']['name'] ?? $labelB));
        $cmp = strcmp($nameA, $nameB);
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcasecmp($labelA, $labelB);
    });

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

    $byDivisionMap = [];
    foreach ($rows as $row) {
        $key = (int) ($row['division_key'] ?? 0);
        if (!isset($byDivisionMap[$key])) {
            $byDivisionMap[$key] = [
                'division_id' => $row['division_id'] ?? null,
                'division_key' => $key,
                'division_name' => $row['division_name'] ?? 'Unassigned',
                'standings' => [],
            ];
        }
        $byDivisionMap[$key]['standings'][] = $row;
    }
    ksort($byDivisionMap);

    /**
     * Labels for events activated on a division (Admin → Divisions event list).
     * Unassigned teams see every event.
     *
     * @return list<string>
     */
    $labelsForDivision = static function (int $divKey) use ($sportLabels, $sportIdByLabel): array {
        if ($divKey <= 0) {
            return $sportLabels;
        }
        $activatedIds = getDivisionSportIds($divKey);
        if ($activatedIds === []) {
            return [];
        }
        $activatedSet = array_fill_keys($activatedIds, true);
        $labels = [];
        foreach ($sportLabels as $label) {
            $sid = (int) ($sportIdByLabel[$label] ?? 0);
            if ($sid > 0 && isset($activatedSet[$sid])) {
                $labels[] = $label;
            }
        }
        return $labels;
    };

    $byDivision = [];
    foreach ($byDivisionMap as $group) {
        $divKey = (int) $group['division_key'];
        $divSportLabels = $labelsForDivision($divKey);

        $divStandings = [];
        foreach ($group['standings'] as $r) {
            $sportsFiltered = [];
            $total = 0;
            $gold = 0;
            $silver = 0;
            $bronze = 0;
            foreach ($divSportLabels as $label) {
                $pts = (int) ($r['sports'][$label] ?? 0);
                $sportsFiltered[$label] = $pts;
                $total += $pts;
                $medal = $r['sport_medals'][$label] ?? null;
                if ($medal === 'gold') {
                    $gold++;
                } elseif ($medal === 'silver') {
                    $silver++;
                } elseif ($medal === 'bronze') {
                    $bronze++;
                }
            }
            // Drop points/medals from events not activated for this division.
            $r['sports'] = $sportsFiltered;
            $r['total'] = $total;
            $r['gold'] = $gold;
            $r['silver'] = $silver;
            $r['bronze'] = $bronze;
            $r['medal_total'] = $gold + $silver + $bronze;
            $divStandings[] = $r;
        }

        usort($divStandings, static function ($a, $b) {
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
        $divRank = 1;
        foreach ($divStandings as &$r) {
            $r['division_rank'] = $divRank++;
            $r['medal_total'] = (int) $r['gold'] + (int) $r['silver'] + (int) $r['bronze'];
        }
        unset($r);

        $medalTally = $divStandings;
        usort($medalTally, static function ($a, $b) {
            if ($a['gold'] !== $b['gold']) {
                return $b['gold'] <=> $a['gold'];
            }
            if ($a['silver'] !== $b['silver']) {
                return $b['silver'] <=> $a['silver'];
            }
            if ($a['bronze'] !== $b['bronze']) {
                return $b['bronze'] <=> $a['bronze'];
            }
            return $b['total'] <=> $a['total'];
        });
        $medalRank = 1;
        foreach ($medalTally as &$mr) {
            $mr['medal_rank'] = $medalRank++;
            $mr['medal_total'] = (int) $mr['gold'] + (int) $mr['silver'] + (int) $mr['bronze'];
        }
        unset($mr);

        $medalTotals = ['gold' => 0, 'silver' => 0, 'bronze' => 0, 'all' => 0];
        foreach ($divStandings as $r) {
            $medalTotals['gold'] += (int) $r['gold'];
            $medalTotals['silver'] += (int) $r['silver'];
            $medalTotals['bronze'] += (int) $r['bronze'];
        }
        $medalTotals['all'] = $medalTotals['gold'] + $medalTotals['silver'] + $medalTotals['bronze'];

        $champion = null;
        foreach ($divStandings as $r) {
            if ($r['total'] > 0) {
                $champion = $r;
                break;
            }
        }
        $medalLeader = null;
        foreach ($medalTally as $r) {
            if ($r['medal_total'] > 0) {
                $medalLeader = $r;
                break;
            }
        }

        $eventHeaders = [];
        foreach ($divSportLabels as $label) {
            if (preg_match('/^(.+?)\s*\(([^)]+)\)$/', $label, $m)) {
                $eventHeaders[] = ['name' => $m[1], 'category' => $m[2], 'label' => $label];
            } else {
                $eventHeaders[] = ['name' => $label, 'category' => '', 'label' => $label];
            }
        }

        $byDivision[] = [
            'division_id' => $group['division_id'],
            'division_key' => $group['division_key'],
            'division_name' => $group['division_name'],
            'sport_labels' => $divSportLabels,
            'event_headers' => $eventHeaders,
            'activated_event_count' => count($divSportLabels),
            'standings' => $divStandings,
            'medal_tally' => $medalTally,
            'medal_totals' => $medalTotals,
            'champion' => $champion,
            'medal_leader' => $medalLeader,
        ];
    }

    return [
        'sport_labels' => $sportLabels,
        'standings' => $rows,
        'by_division' => $byDivision,
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

/** Normalize athlete name parts to uppercase for storage and display. */
function formatAthleteName(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }

    return function_exists('mb_strtoupper') ? mb_strtoupper($name, 'UTF-8') : strtoupper($name);
}

function athleteFullName(array $athlete, bool $uppercase = true): string
{
    $name = trim(($athlete['first_name'] ?? '') . ' ' . ($athlete['last_name'] ?? ''));
    if ($name === '') {
        return '';
    }

    return $uppercase ? formatAthleteName($name) : $name;
}

/** Athlete display name for printable reports and official forms. */
function athleteFullNameReport(array $athlete): string
{
    return athleteFullName($athlete, true);
}

/** Normalized key for matching athlete names (first|last, uppercase). */
function athleteNameMatchKey(string $firstName, string $lastName): string
{
    return formatAthleteName($firstName) . '|' . formatAthleteName($lastName);
}

/**
 * Find an athlete by student ID, or by normalized name when ID is new.
 * Prefers same team when multiple name matches exist.
 */
function findAthleteByStudentIdOrName(PDO $db, string $studentId, string $firstName, string $lastName, ?int $teamId = null): ?array
{
    $studentId = trim($studentId);
    if ($studentId !== '') {
        $stmt = $db->prepare('SELECT * FROM intramural_athletes WHERE student_id = ? LIMIT 1');
        $stmt->execute([$studentId]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
    }

    $first = formatAthleteName($firstName);
    $last = formatAthleteName($lastName);
    if ($first === '' || $last === '') {
        return null;
    }

    if ($teamId !== null && $teamId > 0) {
        $stmt = $db->prepare('SELECT * FROM intramural_athletes WHERE is_active = 1 AND first_name = ? AND last_name = ? ORDER BY (team_id = ?) DESC, id ASC LIMIT 1');
        $stmt->execute([$first, $last, $teamId]);
    } else {
        $stmt = $db->prepare('SELECT * FROM intramural_athletes WHERE is_active = 1 AND first_name = ? AND last_name = ? ORDER BY id ASC LIMIT 1');
        $stmt->execute([$first, $last]);
    }

    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Active athletes grouped by normalized name when more than one account shares the name.
 *
 * @return list<array{name_key: string, display_name: string, athletes: list<array>}>
 */
function getDuplicateAthleteNameGroups(?int $seasonId = null, ?int $teamId = null): array
{
    $db = getDB();
    $where = ['a.is_active = 1'];
    $params = [];

    if ($teamId !== null && $teamId > 0) {
        $where[] = 'a.team_id = ?';
        $params[] = $teamId;
    }
    if ($seasonId) {
        $where[] = 'EXISTS (SELECT 1 FROM intramural_registrations r WHERE r.athlete_id = a.id AND r.season_id = ?)';
        $params[] = $seasonId;
    }

    $seasonRegClause = $seasonId ? ' AND r.season_id = ' . (int) $seasonId : '';
    $sql = "SELECT a.*, t.name AS team_name,
            (SELECT COUNT(DISTINCT r.sport_id) FROM intramural_registrations r WHERE r.athlete_id = a.id{$seasonRegClause}) AS event_count
        FROM intramural_athletes a
        LEFT JOIN intramural_teams t ON t.id = a.team_id
        WHERE " . implode(' AND ', $where) . '
        ORDER BY a.last_name, a.first_name, a.id';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $eventSql = $seasonId
        ? 'SELECT s.name, s.category FROM intramural_registrations r JOIN intramural_sports s ON s.id = r.sport_id WHERE r.athlete_id = ? AND r.season_id = ? ORDER BY s.name, s.category'
        : 'SELECT s.name, s.category FROM intramural_registrations r JOIN intramural_sports s ON s.id = r.sport_id WHERE r.athlete_id = ? ORDER BY s.name, s.category';
    $eventStmt = $db->prepare($eventSql);

    $byName = [];
    foreach ($rows as $row) {
        $key = athleteNameMatchKey((string) $row['first_name'], (string) $row['last_name']);
        if ($seasonId) {
            $eventStmt->execute([(int) $row['id'], $seasonId]);
        } else {
            $eventStmt->execute([(int) $row['id']]);
        }
        $events = [];
        foreach ($eventStmt->fetchAll() ?: [] as $ev) {
            $events[] = sportLabel($ev);
        }
        $row['events'] = $events;
        $byName[$key][] = $row;
    }

    $groups = [];
    foreach ($byName as $key => $athletes) {
        if (count($athletes) < 2) {
            continue;
        }
        $groups[] = [
            'name_key' => $key,
            'display_name' => athleteFullName($athletes[0]),
            'athletes' => $athletes,
        ];
    }

    usort($groups, static function (array $a, array $b): int {
        return strcasecmp($a['display_name'], $b['display_name']);
    });

    return $groups;
}

/**
 * Filter duplicate name groups by search (name or student ID).
 *
 * @param list<array> $groups
 * @return list<array>
 */
function filterDuplicateAthleteGroupsBySearch(array $groups, ?string $search): array
{
    $search = trim((string) $search);
    if ($search === '') {
        return $groups;
    }

    $needle = strtolower($search);
    return array_values(array_filter($groups, static function (array $group) use ($needle): bool {
        if (str_contains(strtolower($group['display_name']), $needle)) {
            return true;
        }
        foreach ($group['athletes'] as $athlete) {
            if (str_contains(strtolower((string) ($athlete['student_id'] ?? '')), $needle)) {
                return true;
            }
            if (str_contains(strtolower((string) ($athlete['team_name'] ?? '')), $needle)) {
                return true;
            }
        }

        return false;
    }));
}

/** Pick the account with the most event registrations to keep when merging. */
function pickDefaultAthleteAccountToKeep(array $athletes): array
{
    $defaultKeep = $athletes[0];
    foreach ($athletes as $athlete) {
        if ((int) ($athlete['event_count'] ?? 0) > (int) ($defaultKeep['event_count'] ?? 0)) {
            $defaultKeep = $athlete;
        }
    }

    return $defaultKeep;
}

/**
 * Merge all duplicate accounts in one name group into the keeper profile.
 *
 * @return array{merged: int, errors: list<string>}
 */
function mergeDuplicateAthleteGroup(array $group, int $keepId, ?int $scopedTeamId = null): array
{
    $merged = 0;
    $errors = [];

    foreach ($group['athletes'] as $athlete) {
        $mergeId = (int) $athlete['id'];
        if ($mergeId === $keepId) {
            continue;
        }
        if ($scopedTeamId && (int) ($athlete['team_id'] ?? 0) !== $scopedTeamId) {
            continue;
        }

        $result = mergeAthleteAccounts($keepId, $mergeId);
        if ($result['ok']) {
            $merged++;
        } else {
            $errors[] = athleteFullName($athlete) . ' (' . $athlete['student_id'] . '): ' . $result['message'];
        }
    }

    return ['merged' => $merged, 'errors' => $errors];
}

/**
 * Merge duplicate athlete accounts: move registrations to keeper, then remove duplicate.
 *
 * @return array{ok: bool, message: string, moved_registrations?: int, dropped_registrations?: int}
 */
function mergeAthleteAccounts(int $keepId, int $mergeId): array
{
    if ($keepId <= 0 || $mergeId <= 0 || $keepId === $mergeId) {
        return ['ok' => false, 'message' => 'Invalid athlete selection.'];
    }

    $db = getDB();
    $keepStmt = $db->prepare('SELECT * FROM intramural_athletes WHERE id = ? LIMIT 1');
    $keepStmt->execute([$keepId]);
    $keep = $keepStmt->fetch();
    $mergeStmt = $db->prepare('SELECT * FROM intramural_athletes WHERE id = ? LIMIT 1');
    $mergeStmt->execute([$mergeId]);
    $merge = $mergeStmt->fetch();

    if (!$keep || !$merge) {
        return ['ok' => false, 'message' => 'One or both athletes were not found.'];
    }
    if (empty($keep['is_active'])) {
        return ['ok' => false, 'message' => 'The keeper athlete is not active.'];
    }

    try {
        $db->beginTransaction();

        $movedRegs = 0;
        $droppedRegs = 0;
        $regsStmt = $db->prepare('SELECT * FROM intramural_registrations WHERE athlete_id = ?');
        $regsStmt->execute([$mergeId]);
        $regs = $regsStmt->fetchAll() ?: [];

        $findDupReg = $db->prepare('SELECT id FROM intramural_registrations WHERE athlete_id = ? AND sport_id = ? AND season_id = ? LIMIT 1');
        $moveReg = $db->prepare('UPDATE intramural_registrations SET athlete_id = ? WHERE id = ?');
        $deleteReg = $db->prepare('DELETE FROM intramural_registrations WHERE id = ?');

        foreach ($regs as $reg) {
            $findDupReg->execute([$keepId, (int) $reg['sport_id'], (int) $reg['season_id']]);
            if ($findDupReg->fetch()) {
                $deleteReg->execute([(int) $reg['id']]);
                $droppedRegs++;
            } else {
                $moveReg->execute([$keepId, (int) $reg['id']]);
                $movedRegs++;
            }
        }

        $mergedPhoto = !empty($keep['photo']) ? $keep['photo'] : ($merge['photo'] ?? null);
        $db->prepare('UPDATE intramural_athletes SET
                photo = COALESCE(NULLIF(?, ""), photo),
                email = COALESCE(NULLIF(?, ""), email),
                phone = COALESCE(NULLIF(?, ""), phone),
                birthdate = COALESCE(?, birthdate),
                department = COALESCE(NULLIF(?, ""), department),
                year_level = COALESCE(NULLIF(?, ""), year_level)
            WHERE id = ?')->execute([
            $mergedPhoto,
            trim((string) ($keep['email'] ?? '')) ?: trim((string) ($merge['email'] ?? '')),
            trim((string) ($keep['phone'] ?? '')) ?: trim((string) ($merge['phone'] ?? '')),
            $keep['birthdate'] ?: ($merge['birthdate'] ?? null),
            trim((string) ($keep['department'] ?? '')) ?: trim((string) ($merge['department'] ?? '')),
            trim((string) ($keep['year_level'] ?? '')) ?: trim((string) ($merge['year_level'] ?? '')),
            $keepId,
        ]);

        $mergePhoto = $merge['photo'] ?? null;
        $db->prepare('DELETE FROM intramural_athletes WHERE id = ?')->execute([$mergeId]);

        $db->commit();

        if ($mergePhoto && $mergePhoto !== ($keep['photo'] ?? null) && $mergePhoto !== $mergedPhoto && function_exists('deleteUploadedFile')) {
            deleteUploadedFile((string) $mergePhoto, UPLOAD_PATH_ATHLETES);
        }

        if (!empty($_SESSION['user_id'])) {
            auditLog((int) $_SESSION['user_id'], 'merge', 'intramural_athlete', $keepId, [
                'merged_id' => $mergeId,
                'merged_student_id' => $merge['student_id'],
                'keep_student_id' => $keep['student_id'],
                'moved_registrations' => $movedRegs,
                'dropped_duplicate_registrations' => $droppedRegs,
            ]);
        }

        return [
            'ok' => true,
            'message' => 'Athlete accounts merged. All events are now on one profile.',
            'moved_registrations' => $movedRegs,
            'dropped_registrations' => $droppedRegs,
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        return ['ok' => false, 'message' => 'Could not merge athletes. Try again.'];
    }
}

/** Default JHCSC course / program names (seeded into athlete_courses when empty). */
function athleteCourseDefaultNames(): array
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

function ensureAthleteCoursesSchema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $tableStmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $tableStmt->execute(['athlete_courses']);
    if ((int) $tableStmt->fetchColumn() === 0) {
        $db->exec("CREATE TABLE athlete_courses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            code VARCHAR(40) DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_athlete_course_name (name)
        ) ENGINE=InnoDB");
    }

    $colStmt = $db->prepare('SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $colStmt->execute(['intramural_athletes', 'department']);
    $deptLen = (int) ($colStmt->fetchColumn() ?: 0);
    if ($deptLen > 0 && $deptLen < 150) {
        try {
            $db->exec('ALTER TABLE intramural_athletes MODIFY COLUMN department VARCHAR(150) DEFAULT NULL');
        } catch (Throwable $e) {
            // Column may already match or lack privileges
        }
    }

    $count = (int) $db->query('SELECT COUNT(*) FROM athlete_courses')->fetchColumn();
    if ($count === 0) {
        $insert = $db->prepare('INSERT INTO athlete_courses (name, sort_order, is_active) VALUES (?, ?, 1)');
        foreach (athleteCourseDefaultNames() as $i => $name) {
            $insert->execute([$name, $i]);
        }
    }
}

/**
 * @return list<array<string, mixed>>
 */
function getAthleteCourses(bool $activeOnly = true): array
{
    ensureAthleteCoursesSchema();
    $db = getDB();
    $sql = 'SELECT c.*,
        (SELECT COUNT(*) FROM intramural_athletes a WHERE a.department = c.name) AS athlete_count
        FROM athlete_courses c';
    if ($activeOnly) {
        $sql .= ' WHERE c.is_active = 1';
    }
    $sql .= ' ORDER BY c.sort_order ASC, c.name ASC';
    return $db->query($sql)->fetchAll() ?: [];
}

function getAthleteCourseById(int $id): ?array
{
    ensureAthleteCoursesSchema();
    if ($id <= 0) {
        return null;
    }
    $stmt = getDB()->prepare('SELECT * FROM athlete_courses WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Active course names for athlete Course dropdowns. */
function athleteCourseOptions(): array
{
    ensureAthleteCoursesSchema();
    $rows = getAthleteCourses(true);
    if ($rows === []) {
        return athleteCourseDefaultNames();
    }
    return array_values(array_map(static fn(array $r): string => (string) $r['name'], $rows));
}

function athleteYearLevelOptions(): array
{
    return ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year', 'Graduate'];
}

function renderAthleteCourseSelect(string $selected = '', string $name = 'department', string $id = 'department', bool $required = false): string
{
    $options = athleteCourseOptions();
    $html = '<select name="' . sanitize($name) . '" id="' . sanitize($id) . '" class="form-select"'
        . ($required ? ' required' : '') . '>';
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
 * Whether an event is finished for the season (all matches done, or manual ranks set with no open fixtures).
 */
function isEventFinished(int $sportId, ?int $seasonId = null): bool
{
    $seasonId = $seasonId ?? getCurrentSeasonId();
    if ($sportId <= 0 || !$seasonId) {
        return false;
    }

    ensureMatchDivisionColumn();
    $db = getDB();
    $stmt = $db->prepare("SELECT DISTINCT COALESCE(NULLIF(division_id, 0), 0) AS div_key
        FROM intramural_matches
        WHERE sport_id = ? AND season_id = ? AND status <> 'cancelled'");
    $stmt->execute([$sportId, $seasonId]);
    $divKeys = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if ($divKeys !== []) {
        foreach ($divKeys as $divKey) {
            $divId = $divKey > 0 ? $divKey : null;
            if (!isEventDivisionFinished($sportId, (int) $seasonId, $divId)) {
                return false;
            }
        }

        return true;
    }

    return isEventDivisionFinished($sportId, (int) $seasonId, null);
}

/**
 * Finished events for certificate generation (admin / secretariat / publication).
 *
 * @return list<array<string, mixed>>
 */
function getFinishedEventsForCertificates(?int $seasonId = null): array
{
    $seasonId = $seasonId ?? getCurrentSeasonId();
    if (!$seasonId) {
        return [];
    }

    $db = getDB();
    $sports = filterSportsForUser($db->query('SELECT * FROM intramural_sports ORDER BY name, category')->fetchAll() ?: []);
    $out = [];
    foreach ($sports as $sport) {
        $sid = (int) $sport['id'];
        if (!isEventFinished($sid, (int) $seasonId)) {
            continue;
        }
        $stmt = $db->prepare('SELECT COUNT(*) FROM intramural_registrations r
            JOIN intramural_athletes a ON r.athlete_id = a.id
            WHERE r.sport_id = ? AND r.season_id = ? AND a.is_active = 1');
        $stmt->execute([$sid, $seasonId]);
        $athleteCount = (int) $stmt->fetchColumn();
        $sport['athlete_count'] = $athleteCount;
        $sport['is_finished'] = true;
        $out[] = $sport;
    }

    return $out;
}

/**
 * Official roster athletes for certificate of recognition (one finished event).
 *
 * @return list<array<string, mixed>>
 */
function getCertificateRecognitionAthletes(int $sportId, ?int $teamId = null, ?int $seasonId = null): array
{
    $seasonId = $seasonId ?? getCurrentSeasonId();
    if ($sportId <= 0 || !$seasonId || !isEventFinished($sportId, (int) $seasonId)) {
        return [];
    }

    $db = getDB();
    $sql = "SELECT r.id AS registration_id, r.jersey_number, r.position, r.event_category, r.athlete_id, r.team_id, r.sport_id,
                   a.first_name, a.last_name, a.student_id, a.athlete_code, a.gender, a.year_level, a.department, a.photo, a.birthdate,
                   t.name AS team_name, t.color AS team_color, t.short_name AS team_short_name, t.department AS team_department,
                   s.name AS sport_name, s.category AS sport_category
            FROM intramural_registrations r
            JOIN intramural_athletes a ON r.athlete_id = a.id
            JOIN intramural_teams t ON r.team_id = t.id
            JOIN intramural_sports s ON r.sport_id = s.id
            WHERE r.sport_id = ? AND r.season_id = ? AND a.is_active = 1";
    $params = [$sportId, $seasonId];
    if ($teamId !== null && $teamId > 0) {
        $sql .= ' AND r.team_id = ?';
        $params[] = $teamId;
    }
    $sql .= ' ORDER BY t.name, CAST(r.jersey_number AS UNSIGNED), a.last_name, a.first_name';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

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

/** Plain team label for schedules / print (no roster dialog). */
function matchTeamLabelPlain(array $match, string $side = 'a'): string
{
    $isB = $side === 'b';
    $teamId = (int) ($isB ? ($match['team_b_id'] ?? 0) : ($match['team_a_id'] ?? 0));
    $name = trim((string) ($isB ? ($match['team_b_name'] ?? '') : ($match['team_a_name'] ?? '')));
    if ($teamId <= 0 || $name === '') {
        return 'TBD';
    }

    return $name;
}

/**
 * Group match rows by sport for scheduling views (sorted by date/time within each sport).
 *
 * @param list<array> $matches
 * @return array<int, array{sport_id:int,sport_name:string,sport_category:string,tournament_format:?string,matches:list<array>}>
 */
function groupMatchesBySportSchedule(array $matches): array
{
    $groups = [];
    foreach ($matches as $m) {
        $sid = (int) ($m['sport_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        if (!isset($groups[$sid])) {
            $groups[$sid] = [
                'sport_id' => $sid,
                'sport_name' => (string) ($m['sport_name'] ?? ''),
                'sport_category' => (string) ($m['sport_category'] ?? ''),
                'tournament_format' => $m['tournament_format'] ?? null,
                'matches' => [],
            ];
        }
        $groups[$sid]['matches'][] = $m;
    }

    foreach ($groups as &$sportGroup) {
        usort($sportGroup['matches'], static function (array $a, array $b): int {
            $aTime = $a['scheduled_at'] ?? null;
            $bTime = $b['scheduled_at'] ?? null;
            if ($aTime === null || $aTime === '') {
                return ($bTime === null || $bTime === '') ? ((int) ($a['match_order'] ?? 0) <=> (int) ($b['match_order'] ?? 0)) : 1;
            }
            if ($bTime === null || $bTime === '') {
                return -1;
            }
            $cmp = strcmp((string) $aTime, (string) $bTime);
            if ($cmp !== 0) {
                return $cmp;
            }

            return ((int) ($a['match_order'] ?? 0) <=> (int) ($b['match_order'] ?? 0));
        });
    }
    unset($sportGroup);

    return $groups;
}

/** SQL ORDER BY for match schedule listings. */
function matchScheduleSqlOrderClause(string $sort): string
{
    if ($sort === 'game_number') {
        return '(m.scheduled_at IS NULL) ASC,
            COALESCE(NULLIF(TRIM(m.venue), \'\'), NULLIF(TRIM(s.venue), \'\'), \'zzz\') ASC,
            DATE(m.scheduled_at) ASC,
            (m.game_number IS NULL) ASC,
            m.game_number ASC,
            m.scheduled_at ASC,
            m.id ASC';
    }

    return 's.name ASC, s.category ASC, (m.scheduled_at IS NULL) ASC, m.scheduled_at ASC, m.match_order ASC, m.id ASC';
}

/**
 * Group matches by play date + venue (for game-number sort views).
 *
 * @param list<array> $matches
 * @return list<array{group_key:string,day:?string,venue_label:string,matches:list<array>}>
 */
function groupMatchesByVenueDaySchedule(array $matches): array
{
    $groups = [];
    $order = [];
    foreach ($matches as $m) {
        if (empty($m['scheduled_at'])) {
            $key = 'unscheduled';
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'group_key' => $key,
                    'day' => null,
                    'venue_label' => 'Unscheduled',
                    'matches' => [],
                ];
                $order[] = $key;
            }
            $groups[$key]['matches'][] = $m;
            continue;
        }

        $day = date('Y-m-d', strtotime((string) $m['scheduled_at']));
        $venue = trim((string) ($m['effective_venue'] ?? $m['venue'] ?? ''));
        $venueKey = normalizeVenueKey($venue);
        $key = $venueKey . '|' . $day;
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'group_key' => $key,
                'day' => $day,
                'venue_label' => $venue !== '' ? $venue : 'Venue TBD',
                'matches' => [],
            ];
            $order[] = $key;
        }
        $groups[$key]['matches'][] = $m;
    }

    $result = [];
    $unscheduled = null;
    foreach ($order as $key) {
        if ($key === 'unscheduled') {
            $unscheduled = $groups[$key];
            continue;
        }
        $g = $groups[$key];
        usort($g['matches'], static function (array $a, array $b): int {
            $ga = (int) ($a['game_number'] ?? 0);
            $gb = (int) ($b['game_number'] ?? 0);
            if ($ga !== $gb) {
                if ($ga === 0) {
                    return 1;
                }
                if ($gb === 0) {
                    return -1;
                }
                return $ga <=> $gb;
            }
            $ta = (string) ($a['scheduled_at'] ?? '');
            $tb = (string) ($b['scheduled_at'] ?? '');
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }
            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });
        $result[] = $g;
    }

    usort($result, static function (array $a, array $b): int {
        $va = strtolower((string) ($a['venue_label'] ?? ''));
        $vb = strtolower((string) ($b['venue_label'] ?? ''));
        if ($va === 'venue tbd') {
            $va = 'zzz';
        }
        if ($vb === 'venue tbd') {
            $vb = 'zzz';
        }
        if ($va !== $vb) {
            return $va <=> $vb;
        }
        return strcmp((string) ($a['day'] ?? ''), (string) ($b['day'] ?? ''));
    });

    if ($unscheduled !== null) {
        $result[] = $unscheduled;
    }

    return $result;
}

/**
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

/** Allowed roster size for a sport/event (null/0 = no limit configured). */
function getSportPlayersPerEventLimit(int $sportId): ?int
{
    if ($sportId <= 0) {
        return null;
    }
    $db = getDB();
    $stmt = $db->prepare('SELECT players_per_event FROM intramural_sports WHERE id = ?');
    $stmt->execute([$sportId]);
    $val = $stmt->fetchColumn();
    if ($val === false || $val === null || (int) $val < 1) {
        return null;
    }
    return (int) $val;
}

/** Current official roster size for a team in one event/season. */
function countTeamEventRoster(int $teamId, int $sportId, int $seasonId): int
{
    if ($teamId <= 0 || $sportId <= 0 || $seasonId <= 0) {
        return 0;
    }
    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM intramural_registrations
        WHERE team_id = ? AND sport_id = ? AND season_id = ?');
    $stmt->execute([$teamId, $sportId, $seasonId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Whether a team may add more athletes to an event roster.
 *
 * @return array{ok:bool,limit:?int,current:int,remaining:?int,message:string}
 */
function checkTeamEventRosterCapacity(int $teamId, int $sportId, int $seasonId, int $adding = 1, ?int $excludeAthleteId = null): array
{
    $adding = max(0, $adding);
    $limit = getSportPlayersPerEventLimit($sportId);
    $current = countTeamEventRoster($teamId, $sportId, $seasonId);

    if ($excludeAthleteId !== null && $excludeAthleteId > 0) {
        $db = getDB();
        $stmt = $db->prepare('SELECT COUNT(*) FROM intramural_registrations
            WHERE team_id = ? AND sport_id = ? AND season_id = ? AND athlete_id = ?');
        $stmt->execute([$teamId, $sportId, $seasonId, $excludeAthleteId]);
        if ((int) $stmt->fetchColumn() > 0) {
            // Already on this roster — updates do not consume another slot.
            $adding = 0;
        }
    }

    if ($limit === null) {
        return [
            'ok' => true,
            'limit' => null,
            'current' => $current,
            'remaining' => null,
            'message' => '',
        ];
    }

    $remaining = max(0, $limit - $current);
    if ($current + $adding > $limit) {
        $db = getDB();
        $sport = $db->prepare('SELECT name, category FROM intramural_sports WHERE id = ?');
        $sport->execute([$sportId]);
        $sportRow = $sport->fetch() ?: ['name' => 'this event', 'category' => ''];
        $label = sportLabel($sportRow);
        return [
            'ok' => false,
            'limit' => $limit,
            'current' => $current,
            'remaining' => $remaining,
            'message' => $label . ' allows only ' . $limit . ' athlete'
                . ($limit === 1 ? '' : 's') . ' per team (currently ' . $current . ').',
        ];
    }

    return [
        'ok' => true,
        'limit' => $limit,
        'current' => $current,
        'remaining' => $remaining - $adding,
        'message' => '',
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
