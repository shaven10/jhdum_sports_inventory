<?php
/**
 * Authentication and Authorization
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/intramurals.php';
require_once __DIR__ . '/committees.php';

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
 * Roles that use competition/team tools only (no Inventory or full Intramurals module nav).
 */
function isIntramuralsOnlyRole(): bool
{
    return isLoggedIn() && in_array($_SESSION['user_role'] ?? '', ['unit_manager', 'coach', 'tabulator', 'secretariat', 'publication'], true);
}

/** Secretariat, publication, tournament managers, and unit managers — scoped tools, not the full Intramurals module. */
function isIntramuralsScopedStaffRole(): bool
{
    return isLoggedIn() && in_array($_SESSION['user_role'] ?? '', ['unit_manager', 'tabulator', 'secretariat', 'publication'], true);
}

function getHomeUrl(): string
{
    return BASE_URL . '/dashboard.php';
}

/** Show inventory stats and borrowing widgets on the main dashboard. */
function canViewInventoryDashboard(): bool
{
    return isLoggedIn() && !isIntramuralsOnlyRole();
}

/** Show match results and overall standings on the main dashboard. */
function canViewCompetitionDashboard(): bool
{
    return isLoggedIn() && (
        canManageIntramurals()
        || isSecretariat()
        || isPublication()
        || isTournamentManager()
        || hasRole('unit_manager')
    );
}

/** Block intramurals-only roles from inventory and other non-intramurals modules. */
function requireInventoryModule(): void
{
    requireLogin();
    if (isIntramuralsOnlyRole()) {
        flash('error', 'Your account does not have access to the Inventory module.');
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
        'tabulator' => 'Tournament Manager',
        'secretariat' => 'Secretariat',
        'publication' => 'Publication',
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
    if (!isCoach()) {
        return false;
    }
    foreach (getCoachAssignments() as $a) {
        if ((int) $a['team_id'] === $teamId && (int) $a['sport_id'] === $sportId) {
            return true;
        }
    }
    return false;
}

function isCoach(): bool
{
    return hasRole('coach');
}

function hasCoachAssignments(?int $userId = null): bool
{
    return !empty(getCoachAssignments($userId));
}

function requireCoachEventAccess(int $teamId, int $sportId): void
{
    if (!canCoachEvent($teamId, $sportId)) {
        flash('error', 'You are not assigned as coach for this team and event.');
        redirect(BASE_URL . '/intramurals/index.php');
    }
}

/** Restrict roster/match queries to coach-assigned team + event pairs. */
function appendCoachAssignmentFilter(string &$sql, array &$params, string $teamColumn = 'r.team_id', string $sportColumn = 'r.sport_id'): void
{
    if (!isCoach() || canManageIntramurals()) {
        return;
    }
    $assignments = getCoachAssignments();
    if (empty($assignments)) {
        $sql .= ' AND 0=1';
        return;
    }
    $parts = [];
    foreach ($assignments as $a) {
        $parts[] = "({$teamColumn} = ? AND {$sportColumn} = ?)";
        $params[] = (int) $a['team_id'];
        $params[] = (int) $a['sport_id'];
    }
    $sql .= ' AND (' . implode(' OR ', $parts) . ')';
}

function filterTeamsForCoach(array $teams): array
{
    if (canManageIntramurals()) {
        return $teams;
    }
    if (hasRole('unit_manager')) {
        $tid = getUserTeamId();
        if (!$tid) {
            return [];
        }
        return array_values(array_filter($teams, static fn($t) => (int) $t['id'] === (int) $tid));
    }
    if (!isCoach()) {
        return $teams;
    }
    $allowed = array_flip(getCoachTeamIds());
    return array_values(array_filter($teams, static fn($t) => isset($allowed[(int) $t['id']])));
}

/**
 * Force unit managers onto their assigned team for roster/form queries.
 */
function applyUnitManagerTeamScope(string &$teamId): void
{
    if (canManageIntramurals() || !hasRole('unit_manager')) {
        return;
    }
    $tid = getUserTeamId();
    // Assigned team only; "0" yields no rows if the account has no team.
    $teamId = $tid ? (string) $tid : '0';
}

function filterSportsForCoach(array $sports, ?int $teamId = null): array
{
    if (canManageIntramurals() || !isCoach()) {
        return $sports;
    }
    if ($teamId) {
        $allowed = array_flip(getCoachSportIdsForTeam($teamId));
        return array_values(array_filter($sports, static fn($s) => isset($allowed[(int) $s['id']])));
    }
    $allowed = [];
    foreach (getCoachAssignments() as $a) {
        $allowed[(int) $a['sport_id']] = (int) $a['sport_id'];
    }
    return array_values(array_filter($sports, static fn($s) => isset($allowed[(int) $s['id']])));
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
    return isAdmin();
}

/** True when the logged-in user is a system administrator. */
function isAdmin(): bool
{
    return isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'admin';
}

/** Unit managers can create coach accounts for their team; intramurals staff and admins can create for any team. */
function canCreateCoachAccounts(?int $teamId = null): bool
{
    if (canManageIntramurals() || canManageUsers()) {
        return true;
    }
    if (!hasRole('unit_manager')) {
        return false;
    }
    $userTeam = getUserTeamId();
    if (!$userTeam) {
        return false;
    }
    return $teamId === null || $teamId === $userTeam;
}

/**
 * Create a coach user account scoped to a team.
 *
 * @return array{success:bool, message?:string, id?:int}
 */
function createCoachAccount(array $input, int $teamId): array
{
    if (!canCreateCoachAccounts($teamId)) {
        return ['success' => false, 'message' => 'You do not have permission to create coach accounts.'];
    }

    ensurePasswordPlainColumn();

    $username = trim($input['username'] ?? '');
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $firstName = trim($input['first_name'] ?? '');
    $lastName = trim($input['last_name'] ?? '');

    if ($username === '' || $email === '' || $password === '' || $firstName === '' || $lastName === '') {
        return ['success' => false, 'message' => 'Username, email, password, first name, and last name are required.'];
    }
    if (strlen($password) < 6) {
        return ['success' => false, 'message' => 'Password must be at least 6 characters.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Enter a valid email address.'];
    }

    $db = getDB();
    $check = $db->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $check->execute([$username, $email]);
    if ($check->fetch()) {
        return ['success' => false, 'message' => 'Username or email already exists.'];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (username, email, password, password_plain, first_name, last_name, role, team_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$username, $email, $hash, $password, $firstName, $lastName, 'coach', $teamId]);
    $id = (int) $db->lastInsertId();
    syncUserTeamAssignment($id, 'coach', $teamId);
    auditLog($_SESSION['user_id'], 'create_coach', 'user', $id, null, [
        'team_id' => $teamId,
        'username' => $username,
    ]);

    return [
        'success' => true,
        'id' => $id,
        'message' => 'Coach account created successfully.',
    ];
}

function canViewReports(): bool
{
    // Inventory reports — intramurals-only roles use intramurals reports instead
    return isLoggedIn() && in_array($_SESSION['user_role'], ['admin', 'coordinator', 'staff'], true);
}

function canManageSettings(): bool
{
    return isAdmin();
}

/** Delete auto-generated fixtures (admin only). */
function canDeleteGeneratedMatches(): bool
{
    return canManageSettings();
}

/** Delete any matches including manual entries (admin only). */
function canDeleteAllMatches(): bool
{
    return canManageSettings();
}

/** Full intramurals administration (all teams/sports/matches). */
function canManageIntramurals(): bool
{
    return isLoggedIn() && in_array($_SESSION['user_role'], ['admin', 'coordinator', 'staff'], true);
}

/** Access any intramurals page (all roles except students). */
function canAccessIntramurals(): bool
{
    if (!isLoggedIn()) {
        return false;
    }

    return in_array($_SESSION['user_role'] ?? '', [
        'admin',
        'coordinator',
        'staff',
        'unit_manager',
        'coach',
        'tabulator',
        'secretariat',
        'publication',
    ], true);
}

/** Show the full Intramurals module in navigation (admin, staff, coaches only). */
function canViewIntramurals(): bool
{
    if (!isLoggedIn()) {
        return false;
    }

    return in_array($_SESSION['user_role'] ?? '', [
        'admin',
        'coordinator',
        'staff',
        'coach',
    ], true);
}

/** Block students and other roles without intramurals access from module pages. */
function requireIntramuralsAccess(): void
{
    requireLogin();
    if (!canAccessIntramurals()) {
        flash('error', 'You do not have permission to access the Intramurals module.');
        redirect(getHomeUrl());
    }
}

/** Block secretariat, publication, tournament managers, and unit managers from the full Intramurals module UI. */
function requireIntramuralsModule(): void
{
    requireIntramuralsAccess();
    if (isIntramuralsScopedStaffRole()) {
        flash('error', 'You do not have access to the Intramurals module.');
        redirect(getHomeUrl());
    }
}

/** Sports Management list is readable by every intramurals role; only staff can edit it. */
function canViewSportsCatalog(): bool
{
    return canAccessIntramurals();
}

/** True for roles that get the Sports Management list without editing or match point details. */
function isSportsCatalogViewOnly(): bool
{
    return !canManageIntramurals() && isIntramuralsScopedStaffRole();
}

function requireSportsCatalogAccess(): void
{
    requireIntramuralsAccess();
    if (!canViewSportsCatalog()) {
        flash('error', 'You do not have permission to view Sports Management.');
        redirect(getHomeUrl());
    }
}

/** Placement Point System — staff can edit; unit managers and secretariat may view only. */
function canViewPointSystem(): bool
{
    return canManageIntramurals() || hasRole('unit_manager') || isSecretariat();
}

function isPointSystemViewOnly(): bool
{
    return !canManageIntramurals() && (hasRole('unit_manager') || isSecretariat());
}

function requirePointSystemAccess(): void
{
    requireIntramuralsAccess();
    if (!canViewPointSystem()) {
        flash('error', 'You do not have permission to view the Point System.');
        redirect(getHomeUrl());
    }
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
 * Full athletes directory / roster list pages.
 * Tournament managers verify players via the match roster modal only.
 */
function canViewAthletesDirectory(): bool
{
    if (!canAccessIntramurals()) {
        return false;
    }
    if (isTournamentManager() && !canManageIntramurals()) {
        return false;
    }
    return true;
}

function requireAthletesDirectoryAccess(): void
{
    requireIntramuralsAccess();
    if (!canViewAthletesDirectory()) {
        flash('error', 'Athletes roster list is not available for tournament manager accounts.');
        redirect(BASE_URL . '/dashboard.php');
    }
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
        if ($teamId !== null && $sportId !== null) {
            return canCoachEvent($teamId, $sportId);
        }
        if ($teamId !== null) {
            return !empty(getCoachSportIdsForTeam($teamId));
        }
        return hasCoachAssignments();
    }

    return false;
}

/** Only administrators can lock/unlock athlete rosters for a season. */
function canLockRoster(): bool
{
    return canManageSettings();
}

/** Only administrators can lock/unlock match results for a season. */
function canLockResults(): bool
{
    return canManageSettings();
}

/** Tournament managers and unit managers can send incident reports / queries to admin. */
function canSubmitIncidentReports(): bool
{
    return isLoggedIn() && (
        (isTournamentManager() && !canManageIntramurals())
        || hasRole('unit_manager')
    );
}

/** Administrators receive and respond to incident reports. */
function canManageIncidentReports(): bool
{
    return isAdmin();
}

function requireIncidentSubmitAccess(): void
{
    requireLogin();
    if (!canSubmitIncidentReports()) {
        flash('error', 'Only tournament managers and unit managers can submit incident reports.');
        redirect(getHomeUrl());
    }
}

function requireIncidentManageAccess(): void
{
    requireLogin();
    if (!canManageIncidentReports()) {
        flash('error', 'Only administrators can manage incident reports.');
        redirect(getHomeUrl());
    }
}

/** Show roster lock status to all staff roles (not students). */
function shouldShowRosterLockStatus(): bool
{
    return isLoggedIn() && ($_SESSION['user_role'] ?? '') !== 'student';
}

/** Show results lock status to competition staff (not students). */
function shouldShowResultsLockStatus(): bool
{
    return isLoggedIn() && ($_SESSION['user_role'] ?? '') !== 'student' && (
        canViewMatchResults() || canManageIntramurals() || isAdmin()
    );
}

/** Roster changes allowed when season roster is not locked and user has roster permission. */
function canModifyRoster(?int $teamId = null, ?int $sportId = null): bool
{
    if (isRosterLocked()) {
        return false;
    }
    return canManageTeamRoster($teamId, $sportId);
}

/** Any roster workflow (import, register, assign) when not locked. */
function canModifyRosterAny(): bool
{
    if (isRosterLocked()) {
        return false;
    }
    return canManageTeamAthletes() || canManageTeamRoster();
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

function isSecretariat(): bool
{
    return hasRole('secretariat');
}

function isPublication(): bool
{
    return hasRole('publication');
}

/** Secretariat and publication — print / publish oriented intramurals staff. */
function isPublishStaff(): bool
{
    return isSecretariat() || isPublication();
}

/** Manual event rankings / medal placement entry. */
function canManageEventRankings(?int $sportId = null): bool
{
    if (isAdmin() || isSecretariat()) {
        return true;
    }
    if (!isTournamentManager()) {
        return false;
    }
    if ($sportId !== null && $sportId > 0) {
        return canManageEventMatches($sportId) && isTmRankingEnabled($sportId);
    }
    $enabled = getTmRankingEnabledSportIds();
    if ($enabled === []) {
        return false;
    }
    return (bool) array_intersect(getTmSportIds(), $enabled);
}

function canManageMatches(): bool
{
    if (canManageIntramurals() || isSecretariat()) {
        return true;
    }
    return isTournamentManager() && !empty(getTmSportIds());
}

function canManageEventMatches(int $sportId): bool
{
    if (canManageIntramurals() || isSecretariat()) {
        return true;
    }
    if (!isTournamentManager()) {
        return false;
    }
    return in_array($sportId, getTmSportIds(), true);
}

function requireEventMatchAccess(int $sportId): void
{
    if (!canManageEventMatches($sportId)) {
        flash('error', 'You do not have permission to manage matches for this event.');
        redirect(BASE_URL . '/intramurals/matches/index.php');
    }
}

function isTournamentManager(): bool
{
    return hasRole('tabulator');
}

/**
 * Tournament manager assignments for the current user: [ ['sport_id'=>], ... ]
 */
function getTmAssignments(?int $userId = null): array
{
    if ($userId === null) {
        if (!isLoggedIn() || !isTournamentManager()) {
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
            $stmt = $db->prepare('SELECT sport_id FROM intramural_event_managers WHERE manager_user_id = ? AND season_id = ?');
            $stmt->execute([$userId, $seasonId]);
        } else {
            $stmt = $db->prepare('SELECT sport_id FROM intramural_event_managers WHERE manager_user_id = ?');
            $stmt->execute([$userId]);
        }
        $cache[$cacheKey] = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        $cache[$cacheKey] = [];
    }

    return $cache[$cacheKey];
}

function getTmSportIds(?int $userId = null): array
{
    $ids = [];
    foreach (getTmAssignments($userId) as $a) {
        $ids[(int) $a['sport_id']] = (int) $a['sport_id'];
    }
    return array_values($ids);
}

function getEventManagerForSport(int $sportId, ?int $seasonId = null): ?int
{
    $seasonId = $seasonId ?? getCurrentSeasonId();
    if (!$seasonId) {
        return null;
    }

    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT manager_user_id FROM intramural_event_managers WHERE sport_id = ? AND season_id = ?');
        $stmt->execute([$sportId, $seasonId]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    } catch (Throwable $e) {
        return null;
    }
}

function eventHasManager(int $sportId, ?int $seasonId = null): bool
{
    return getEventManagerForSport($sportId, $seasonId) !== null;
}

/**
 * Set or clear the tournament manager for a specific event.
 */
function assignEventManager(int $sportId, ?int $managerUserId, ?int $seasonId = null): void
{
    $db = getDB();
    $seasonId = $seasonId ?? getCurrentSeasonId();
    if (!$seasonId) {
        return;
    }

    if (!$managerUserId) {
        $db->prepare('DELETE FROM intramural_event_managers WHERE sport_id = ? AND season_id = ?')
            ->execute([$sportId, $seasonId]);
        return;
    }

    $db->prepare('INSERT INTO intramural_event_managers (sport_id, season_id, manager_user_id) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE manager_user_id = VALUES(manager_user_id), updated_at = CURRENT_TIMESTAMP')
        ->execute([$sportId, $seasonId, $managerUserId]);
}

function canRecordScores(?int $sportId = null): bool
{
    if (function_exists('isResultsLocked') && isResultsLocked(null, $sportId)) {
        return false;
    }
    return canManageMatches();
}

/** Generate match fixtures (tabulators: assigned events only). */
function canGenerateMatches(): bool
{
    return canManageMatches();
}

/** View per-sport standings and overall rankings. */
function canViewStandings(): bool
{
    if (canManageIntramurals() || isPublishStaff() || hasRole('unit_manager')) {
        return true;
    }
    if (isTournamentManager()) {
        return !empty(getTmSportIds());
    }
    return false;
}

/** View match schedules and completed results. */
function canViewMatchResults(): bool
{
    if (canManageIntramurals() || isPublishStaff() || hasRole('unit_manager')) {
        return true;
    }
    if (isTournamentManager()) {
        return !empty(getTmSportIds());
    }
    return false;
}

/** View all intramurals reports (schedules, results, standings, rosters, etc.). */
function canViewIntramuralsReports(): bool
{
    return canManageIntramurals() || isPublishStaff();
}

/** View standings or results for a specific event (tabulators: assigned events only). */
function canViewEvent(int $sportId): bool
{
    if (canManageIntramurals() || isPublishStaff()) {
        return true;
    }
    if (isTournamentManager()) {
        return in_array($sportId, getTmSportIds(), true);
    }
    return isLoggedIn();
}

function requireStandingsAccess(): void
{
    if (!canViewStandings()) {
        flash('error', 'You do not have permission to view standings.');
        redirect(getHomeUrl());
    }
}

function requireMatchResultsAccess(): void
{
    if (!canViewMatchResults()) {
        flash('error', 'You do not have permission to view match results.');
        redirect(getHomeUrl());
    }
}

function requireEventViewAccess(int $sportId): void
{
    if (!canViewEvent($sportId)) {
        flash('error', 'You do not have permission to view this event.');
        redirect(BASE_URL . '/intramurals/matches/index.php');
    }
}

/** Filter sport rows to those accessible by the current tabulator. */
function filterSportsForUser(array $sports): array
{
    if (canManageIntramurals() || isPublishStaff()) {
        return $sports;
    }
    if (isTournamentManager()) {
        $allowed = array_flip(getTmSportIds());
        return array_values(array_filter($sports, static fn($s) => isset($allowed[(int) $s['id']])));
    }
    return $sports;
}

/** Report types available to tournament managers. */
function getTabulatorReportTypes(): array
{
    return ['schedules', 'results', 'standings', 'medals', 'overall'];
}

/** Restrict match queries to tabulator-assigned events. */
function appendTmSportFilter(string &$sql, array &$params, string $column = 'm.sport_id'): void
{
    if (!isTournamentManager() || canManageIntramurals()) {
        return;
    }
    $tmSportIds = getTmSportIds();
    if (empty($tmSportIds)) {
        $sql .= ' AND 0=1';
        return;
    }
    $placeholders = implode(',', array_fill(0, count($tmSportIds), '?'));
    $sql .= " AND $column IN ($placeholders)";
    $params = array_merge($params, $tmSportIds);
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

    if ($allowCoach && isCoach() && $teamId && in_array($teamId, getCoachTeamIds(), true)) {
        return;
    }

    if ($allowCoach && isCoach() && !$teamId && hasCoachAssignments()) {
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
 * Permanently delete a user account (admin only).
 *
 * @return array{success:bool, message?:string}
 */
function deleteUserAccount(int $userId): array
{
    if (!canManageUsers()) {
        return ['success' => false, 'message' => 'You do not have permission to delete users.'];
    }

    if ($userId <= 0) {
        return ['success' => false, 'message' => 'Invalid user.'];
    }

    if (isLoggedIn() && (int) $_SESSION['user_id'] === $userId) {
        return ['success' => false, 'message' => 'You cannot delete your own account.'];
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'User not found.'];
    }

    if ($user['role'] === 'admin') {
        $adminCount = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($adminCount <= 1) {
            return ['success' => false, 'message' => 'Cannot delete the only administrator account.'];
        }
    }

    $blocking = [
        'borrowing_transactions' => 'processed inventory transactions',
        'damage_reports' => 'damage reports',
        'maintenance_schedule' => 'maintenance records',
    ];
    foreach ($blocking as $table => $label) {
        $column = $table === 'borrowing_transactions' ? 'processed_by' : ($table === 'damage_reports' ? 'reported_by' : 'created_by');
        $check = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?");
        $check->execute([$userId]);
        if ((int) $check->fetchColumn() > 0) {
            return [
                'success' => false,
                'message' => 'This user has linked ' . $label . ' and cannot be deleted. Deactivate the account instead.',
            ];
        }
    }

    try {
        $db->beginTransaction();

        $db->prepare('UPDATE intramural_teams SET unit_manager_id = NULL WHERE unit_manager_id = ?')->execute([$userId]);
        $db->prepare('UPDATE intramural_teams SET coach_user_id = NULL WHERE coach_user_id = ?')->execute([$userId]);
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

        auditLog((int) $_SESSION['user_id'], 'delete_user', 'user', $userId, [
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
        ]);

        $db->commit();
        return ['success' => true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'message' => 'Could not delete user. Deactivate the account instead.'];
    }
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
