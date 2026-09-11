<?php
/**
 * Authentication and Authorization
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/intramurals.php';

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        flash('error', 'Please login to access this page.');
        redirect(BASE_URL . '/login.php');
    }
}

function requireRole(array $roles): void
{
    requireLogin();
    if (!in_array($_SESSION['user_role'], $roles, true)) {
        flash('error', 'You do not have permission to access this page.');
        redirect(getHomeUrl());
    }
}

/**
 * Unit managers and coaches only use the Intramurals module (no inventory).
 */
function isIntramuralsOnlyRole(): bool
{
    return isLoggedIn() && in_array($_SESSION['user_role'] ?? '', ['unit_manager', 'coach', 'tabulator', 'tournament_manager'], true);
}

function getHomeUrl(): string
{
    if (isIntramuralsOnlyRole()) {
        return BASE_URL . '/intramurals/index.php';
    }
    return BASE_URL . '/dashboard.php';
}

/** Block coaches / unit managers from inventory and other non-intramurals modules. */
function requireInventoryModule(): void
{
    requireLogin();
    if (isIntramuralsOnlyRole()) {
        flash('error', 'Your account only has access to the Intramurals module.');
        redirect(getHomeUrl());
    }
}

function login(string $username, string $password): array
{
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1');
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        if ($user) {
            logLoginAttempt($user['id'], 'failed');
        }
        return ['success' => false, 'message' => 'Invalid username or password.'];
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['team_id'] = !empty($user['team_id']) ? (int) $user['team_id'] : null;

    logLoginAttempt($user['id'], 'success');
    auditLog($user['id'], 'login', 'user', $user['id']);

    return ['success' => true, 'message' => 'Login successful.', 'role' => $user['role']];
}

function logout(): void
{
    if (isLoggedIn()) {
        auditLog($_SESSION['user_id'], 'logout', 'user', $_SESSION['user_id']);
    }
    session_destroy();
    session_start();
}

function getCurrentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT u.*, t.name as team_name, t.color as team_color, t.short_name as team_short_name
        FROM users u
        LEFT JOIN intramural_teams t ON u.team_id = t.id
        WHERE u.id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;

    if ($user) {
        $_SESSION['team_id'] = !empty($user['team_id']) ? (int) $user['team_id'] : null;
    }

    return $user;
}

function logLoginAttempt(int $userId, string $status): void
{
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO login_history (user_id, ip_address, user_agent, status) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        $userId,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null,
        $status
    ]);
}

function hasRole(string $role): bool
{
    return isLoggedIn() && $_SESSION['user_role'] === $role;
}

function getAllRoles(): array
{
    return [
        'admin' => 'Administrator',
        'coordinator' => 'Sports Coordinator',
        'staff' => 'Sports Staff',
        'unit_manager' => 'Unit Manager',
        'coach' => 'Coach',
        'tabulator' => 'Tabulator',
        'tournament_manager' => 'Tournament Manager',
        'student' => 'Student',
    ];
}

function getUserTeamId(): ?int
{
    if (!isLoggedIn()) {
        return null;
    }
    if (array_key_exists('team_id', $_SESSION) && $_SESSION['team_id']) {
        return (int) $_SESSION['team_id'];
    }
    $user = getCurrentUser();
    if ($user && !empty($user['team_id'])) {
        return (int) $user['team_id'];
    }

    // Coaches may only have event assignments (no home team_id)
    $teams = getCoachTeamIds();
    return $teams[0] ?? null;
}

/**
 * Event coach assignments for the current coach user: [ ['team_id'=>, 'sport_id'=>], ... ]
 */
function getCoachAssignments(?int $userId = null): array
{
    if ($userId === null) {
        if (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== 'coach') {
            return [];
        }
        $userId = (int) $_SESSION['user_id'];
    }

    static $cache = [];
    $seasonId = function_exists('getCurrentSeasonId') ? getCurrentSeasonId() : null;
    $cacheKey = $userId . ':' . ($seasonId ?? 0);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    try {
        $db = getDB();
        if ($seasonId) {
            $stmt = $db->prepare('SELECT team_id, sport_id FROM intramural_event_coaches WHERE coach_user_id = ? AND season_id = ?');
            $stmt->execute([$userId, $seasonId]);
        } else {
            $stmt = $db->prepare('SELECT team_id, sport_id FROM intramural_event_coaches WHERE coach_user_id = ?');
            $stmt->execute([$userId]);
        }
        $cache[$cacheKey] = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        $cache[$cacheKey] = [];
    }

    return $cache[$cacheKey];
}

function getCoachTeamIds(?int $userId = null): array
{
    $ids = [];
    foreach (getCoachAssignments($userId) as $a) {
        $ids[(int) $a['team_id']] = (int) $a['team_id'];
    }
    return array_values($ids);
}

function getCoachSportIdsForTeam(int $teamId, ?int $userId = null): array
{
    $ids = [];
    foreach (getCoachAssignments($userId) as $a) {
        if ((int) $a['team_id'] === $teamId) {
            $ids[(int) $a['sport_id']] = (int) $a['sport_id'];
        }
    }
    return array_values($ids);
}

function canCoachEvent(int $teamId, int $sportId): bool
{
    if (canManageIntramurals()) {
        return true;
    }
    if (hasRole('unit_manager') && getUserTeamId() === $teamId) {
        return true;
    }
    if (!hasRole('coach')) {
        return false;
    }
    foreach (getCoachAssignments() as $a) {
        if ((int) $a['team_id'] === $teamId && (int) $a['sport_id'] === $sportId) {
            return true;
        }
    }
    return false;
}

function isTeamScopedRole(): bool
{
    return isLoggedIn() && in_array($_SESSION['user_role'], ['unit_manager', 'coach'], true);
}

function canManageInventory(): bool
{
    return isLoggedIn() && in_array($_SESSION['user_role'], ['admin', 'coordinator', 'staff'], true);
}

function canApproveRequests(): bool
{
    return isLoggedIn() && in_array($_SESSION['user_role'], ['admin', 'coordinator'], true);
}

function canManageUsers(): bool
{
    return isLoggedIn() && $_SESSION['user_role'] === 'admin';
}

function canViewReports(): bool
{
    // Inventory reports — intramurals-only roles use intramurals reports instead
    return isLoggedIn() && in_array($_SESSION['user_role'], ['admin', 'coordinator', 'staff'], true);
}

function canManageSettings(): bool
{
    return isLoggedIn() && $_SESSION['user_role'] === 'admin';
}

/** Full intramurals administration (all teams/sports/matches). */
function canManageIntramurals(): bool
{
    return isLoggedIn() && in_array($_SESSION['user_role'], ['admin', 'coordinator', 'staff'], true);
}

function canViewIntramurals(): bool
{
    return isLoggedIn();
}

/** Unit managers can register/edit athletes for their own team. */
function canManageTeamAthletes(?int $teamId = null): bool
{
    if (canManageIntramurals()) {
        return true;
    }
    if (!isLoggedIn() || $_SESSION['user_role'] !== 'unit_manager') {
        return false;
    }
    $userTeam = getUserTeamId();
    if (!$userTeam) {
        return false;
    }
    return $teamId === null || $teamId === $userTeam;
}

/**
 * Unit managers: full roster for their team.
 * Coaches: only their assigned team+event combinations.
 */
function canManageTeamRoster(?int $teamId = null, ?int $sportId = null): bool
{
    if (canManageIntramurals()) {
        return true;
    }
    if (!isLoggedIn()) {
        return false;
    }

    if ($_SESSION['user_role'] === 'unit_manager') {
        $userTeam = getUserTeamId();
        if (!$userTeam) {
            return false;
        }
        return $teamId === null || $teamId === $userTeam;
    }

    if ($_SESSION['user_role'] === 'coach') {
        $assignments = getCoachAssignments();
        if (empty($assignments)) {
            return false;
        }
        if ($teamId === null && $sportId === null) {
            return true;
        }
        foreach ($assignments as $a) {
            $matchTeam = $teamId === null || (int) $a['team_id'] === $teamId;
            $matchSport = $sportId === null || (int) $a['sport_id'] === $sportId;
            if ($matchTeam && $matchSport) {
                return true;
            }
        }
        return false;
    }

    return false;
}

function canEditOwnTeam(?int $teamId = null): bool
{
    if (canManageIntramurals()) {
        return true;
    }
    if (!isLoggedIn() || $_SESSION['user_role'] !== 'unit_manager') {
        return false;
    }
    $userTeam = getUserTeamId();
    return $userTeam && ($teamId === null || $teamId === $userTeam);
}

function canManageMatches(): bool
{
    return canManageIntramurals() || hasRole('tabulator');
}

function isTournamentManager(): bool
{
    return isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'tournament_manager';
}

/**
 * Sport IDs assigned to the current (or given) tournament manager for the viewed season.
 *
 * @return list<int>
 */
function getTournamentManagerSportIds(?int $userId = null): array
{
    if ($userId === null) {
        if (!isTournamentManager()) {
            return [];
        }
        $userId = (int) $_SESSION['user_id'];
    }

    static $cache = [];
    $seasonId = function_exists('getCurrentSeasonId') ? getCurrentSeasonId() : null;
    $cacheKey = $userId . ':' . ($seasonId ?? 0);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    try {
        $db = getDB();
        if ($seasonId) {
            $stmt = $db->prepare('SELECT sport_id FROM intramural_event_managers WHERE user_id = ? AND season_id = ?');
            $stmt->execute([$userId, $seasonId]);
        } else {
            $stmt = $db->prepare('SELECT DISTINCT sport_id FROM intramural_event_managers WHERE user_id = ?');
            $stmt->execute([$userId]);
        }
        $cache[$cacheKey] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        $cache[$cacheKey] = [];
    }

    return $cache[$cacheKey];
}

function canManageAssignedEvent(?int $sportId = null): bool
{
    if (!isTournamentManager()) {
        return false;
    }
    $ids = getTournamentManagerSportIds();
    if (empty($ids)) {
        return false;
    }
    return $sportId === null || in_array($sportId, $ids, true);
}

function canRecordScores(?int $sportId = null): bool
{
    if (canManageMatches()) {
        return true;
    }
    return canManageAssignedEvent($sportId);
}

/**
 * Assign or clear the tournament manager for an event in a season.
 */
function assignEventTournamentManager(int $sportId, ?int $userId, ?int $seasonId = null): void
{
    $db = getDB();
    $seasonId = $seasonId ?? getCurrentSeasonId();
    if (!$seasonId) {
        return;
    }

    if (!$userId) {
        $db->prepare('DELETE FROM intramural_event_managers WHERE sport_id = ? AND season_id = ?')
            ->execute([$sportId, $seasonId]);
        return;
    }

    $db->prepare('INSERT INTO intramural_event_managers (season_id, sport_id, user_id) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), updated_at = CURRENT_TIMESTAMP')
        ->execute([$seasonId, $sportId, $userId]);
}

function canBorrowEquipment(): bool
{
    return isLoggedIn() && $_SESSION['user_role'] === 'student';
}

function requireTeamAccess(?int $teamId, bool $allowCoach = true): void
{
    if (canManageIntramurals()) {
        return;
    }

    $role = $_SESSION['user_role'] ?? '';

    if ($role === 'unit_manager') {
        $userTeam = getUserTeamId();
        if ($userTeam && $teamId && $userTeam === $teamId) {
            return;
        }
    }

    if ($allowCoach && $role === 'coach' && $teamId && in_array($teamId, getCoachTeamIds(), true)) {
        return;
    }

    flash('error', 'You can only access your assigned team.');
    redirect(BASE_URL . '/intramurals/index.php');
}

/**
 * Sync home team / unit-manager links.
 * Coaches are assigned per event via intramural_event_coaches (not a single team coach slot).
 */
function syncUserTeamAssignment(int $userId, string $role, ?int $teamId): void
{
    $db = getDB();

    $db->prepare('UPDATE intramural_teams SET unit_manager_id = NULL WHERE unit_manager_id = ?')->execute([$userId]);

    if ($role === 'unit_manager') {
        if (!$teamId) {
            $db->prepare('UPDATE users SET team_id = NULL WHERE id = ?')->execute([$userId]);
            return;
        }
        $db->prepare('UPDATE users SET team_id = ? WHERE id = ?')->execute([$teamId, $userId]);
        $db->prepare('UPDATE users SET team_id = NULL WHERE role = ? AND team_id = ? AND id != ?')
            ->execute(['unit_manager', $teamId, $userId]);
        $db->prepare('UPDATE intramural_teams SET unit_manager_id = ? WHERE id = ?')->execute([$userId, $teamId]);
        return;
    }

    if ($role === 'coach') {
        // Home team is optional context; event assignments are managed separately.
        $db->prepare('UPDATE users SET team_id = ? WHERE id = ?')->execute([$teamId, $userId]);
        return;
    }

    $db->prepare('UPDATE users SET team_id = NULL WHERE id = ?')->execute([$userId]);
}

/**
 * Set or clear the coach for a specific team + event.
 */
function assignEventCoach(int $teamId, int $sportId, ?int $coachUserId, ?int $seasonId = null): void
{
    $db = getDB();
    $seasonId = $seasonId ?? getCurrentSeasonId();
    if (!$seasonId) {
        return;
    }

    if (!$coachUserId) {
        $db->prepare('DELETE FROM intramural_event_coaches WHERE team_id = ? AND sport_id = ? AND season_id = ?')
            ->execute([$teamId, $sportId, $seasonId]);
        return;
    }

    $db->prepare('INSERT INTO intramural_event_coaches (team_id, sport_id, season_id, coach_user_id) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE coach_user_id = VALUES(coach_user_id), updated_at = CURRENT_TIMESTAMP')
        ->execute([$teamId, $sportId, $seasonId, $coachUserId]);

    // Keep home team in sync if empty
    $stmt = $db->prepare('SELECT team_id FROM users WHERE id = ?');
    $stmt->execute([$coachUserId]);
    $current = $stmt->fetchColumn();
    if (!$current) {
        $db->prepare('UPDATE users SET team_id = ?, role = ? WHERE id = ?')
            ->execute([$teamId, 'coach', $coachUserId]);
    }
}
