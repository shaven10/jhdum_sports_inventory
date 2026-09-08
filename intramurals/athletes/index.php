<?php
require_once __DIR__ . '/../../includes/auth.php';
requireAthletesDirectoryAccess();

$db = getDB();
$seasonId = getCurrentSeasonId();
$search = get('search');
$teamId = get('team');
$sportId = get('sport');
$multiEvent = get('multi_event');
$page = max(1, (int) get('page', '1'));
$perPage = 15;
$userTeamId = getUserTeamId();
$isCoach = hasRole('coach') && !canManageIntramurals();
$coachTeamIds = $isCoach ? getCoachTeamIds() : [];

// Unit managers are locked to their assigned team; coaches default when they have one team
if (hasRole('unit_manager') && !canManageIntramurals()) {
    $teamId = $userTeamId ? (string) $userTeamId : '0';
} elseif ($isCoach && count($coachTeamIds) === 1 && $teamId === '') {
    $teamId = (string) $coachTeamIds[0];
}
$lockTeamFilter = hasRole('unit_manager') && !canManageIntramurals() && (bool) $userTeamId;

$where = ['a.is_active = 1'];
$params = [];

if ($search) {
    $where[] = '(a.first_name LIKE ? OR a.last_name LIKE ? OR a.student_id LIKE ? OR a.athlete_code LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}
if ($teamId !== '') {
    $where[] = 'a.team_id = ?';
    $params[] = (int) $teamId;
} elseif ($isCoach && $coachTeamIds) {
    $placeholders = implode(',', array_fill(0, count($coachTeamIds), '?'));
    $where[] = "a.team_id IN ($placeholders)";
    $params = array_merge($params, $coachTeamIds);
}
if ($sportId !== '') {
    if ($seasonId) {
        $where[] = 'EXISTS (SELECT 1 FROM intramural_registrations r WHERE r.athlete_id = a.id AND r.sport_id = ? AND r.season_id = ?)';
        $params[] = (int) $sportId;
        $params[] = $seasonId;
    } else {
        $where[] = 'EXISTS (SELECT 1 FROM intramural_registrations r WHERE r.athlete_id = a.id AND r.sport_id = ?)';
        $params[] = (int) $sportId;
    }
} elseif ($isCoach) {
    // Coaches only list athletes registered in their assigned team + event pairs
    $assignments = getCoachAssignments();
    if ($assignments) {
        $parts = [];
        foreach ($assignments as $a) {
            $parts[] = '(r.team_id = ? AND r.sport_id = ?)';
            $params[] = (int) $a['team_id'];
            $params[] = (int) $a['sport_id'];
        }
        $seasonClause = $seasonId ? ' AND r.season_id = ' . (int) $seasonId : '';
        $where[] = 'EXISTS (SELECT 1 FROM intramural_registrations r WHERE r.athlete_id = a.id AND (' . implode(' OR ', $parts) . ')' . $seasonClause . ')';
    } else {
        $where[] = '0=1';
    }
}

$eventCountSql = $seasonId
    ? '(SELECT COUNT(DISTINCT r.sport_id) FROM intramural_registrations r WHERE r.athlete_id = a.id AND r.season_id = ' . (int) $seasonId . ')'
    : '(SELECT COUNT(DISTINCT r.sport_id) FROM intramural_registrations r WHERE r.athlete_id = a.id)';
if ($multiEvent === 'multi') {
    $where[] = "$eventCountSql >= 2";
} elseif ($multiEvent === 'single') {
    $where[] = "$eventCountSql = 1";
}

$whereClause = implode(' AND ', $where);
$countStmt = $db->prepare("SELECT COUNT(*) FROM intramural_athletes a WHERE $whereClause");
$countStmt->execute($params);
$pagination = paginate((int) $countStmt->fetchColumn(), $perPage, $page);

$sportNamesSql = $seasonId
    ? '(SELECT GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR \'||\')
        FROM intramural_registrations r
        JOIN intramural_sports s ON s.id = r.sport_id
        WHERE r.athlete_id = a.id AND r.season_id = ' . (int) $seasonId . ')'
    : '(SELECT GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR \'||\')
        FROM intramural_registrations r
        JOIN intramural_sports s ON s.id = r.sport_id
        WHERE r.athlete_id = a.id)';

$sportCategoriesSql = $seasonId
    ? '(SELECT GROUP_CONCAT(DISTINCT s.category ORDER BY s.category SEPARATOR \'||\')
        FROM intramural_registrations r
        JOIN intramural_sports s ON s.id = r.sport_id
        WHERE r.athlete_id = a.id AND r.season_id = ' . (int) $seasonId . ')'
    : '(SELECT GROUP_CONCAT(DISTINCT s.category ORDER BY s.category SEPARATOR \'||\')
        FROM intramural_registrations r
        JOIN intramural_sports s ON s.id = r.sport_id
        WHERE r.athlete_id = a.id)';

$sql = "SELECT a.*, t.name as team_name, t.color as team_color,
        $sportNamesSql as sport_names,
        $sportCategoriesSql as sport_categories,
        $eventCountSql as event_count
        FROM intramural_athletes a
        LEFT JOIN intramural_teams t ON a.team_id = t.id
        WHERE $whereClause
        ORDER BY a.last_name, a.first_name
        LIMIT {$pagination['offset']}, $perPage";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$athletes = $stmt->fetchAll();

foreach ($athletes as &$athleteRow) {
    $athleteRow['sport_name_list'] = array_values(array_filter(array_map('trim', explode('||', (string) ($athleteRow['sport_names'] ?? '')))));
    $athleteRow['sport_category_list'] = array_values(array_filter(array_map('trim', explode('||', (string) ($athleteRow['sport_categories'] ?? '')))));
}
unset($athleteRow);

$teams = filterTeamsForCoach($db->query('SELECT id, name FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll());
$sports = filterSportsForCoach($db->query('SELECT id, name, category, event_group FROM intramural_sports ORDER BY ' . intramuralSportsOrderBy())->fetchAll());

$filterQuery = http_build_query(array_filter([
    'search' => $search,
    'team' => $teamId !== '' ? $teamId : null,
    'sport' => $sportId !== '' ? $sportId : null,
    'multi_event' => in_array($multiEvent, ['multi', 'single'], true) ? $multiEvent : null,
], static fn($v) => $v !== null && $v !== ''));

$pageTitle = 'Athlete Management';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-person-badge"></i> Athletes</h1>
        <p class="text-muted mb-0">Register athletes by school/team and event using Student ID from the Registrar</p>
    </div>
    <div class="d-flex gap-2">
        <?php if (canImportRoster()): ?>
        <a href="<?= BASE_URL ?>/intramurals/roster/import.php" class="btn btn-success"><i class="bi bi-file-earmark-arrow-up"></i> Import Roster</a>
        <?php endif; ?>
        <?php if (canModifyRosterAny() && canManageTeamAthletes()): ?>
        <a href="<?= BASE_URL ?>/intramurals/athletes/add.php" class="btn btn-primary"><i class="bi bi-person-plus"></i> Register Athlete</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/intramurals/index.php" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

<?php if ($isCoach && !hasCoachAssignments()): ?>
<div class="alert alert-warning">You have no event coach assignments for this season. Ask your unit manager to assign you under <strong>Teams → Event Coaches</strong>.</div>
<?php endif; ?>

<div class="filter-bar">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Search</label>
            <input type="text" name="search" class="form-control" value="<?= sanitize($search) ?>" placeholder="Name, student ID...">
        </div>
        <div class="col-md-2">
            <label class="form-label">Team / House</label>
            <select name="team" class="form-select" <?= !empty($lockTeamFilter) ? 'disabled' : '' ?>>
                <?php if (empty($lockTeamFilter)): ?>
                <option value="">All Teams</option>
                <?php endif; ?>
                <?php foreach ($teams as $t): ?>
                <?php if ($isCoach && !in_array((int) $t['id'], $coachTeamIds, true)) continue; ?>
                <option value="<?= $t['id'] ?>" <?= $teamId === (string) $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($lockTeamFilter)): ?>
            <input type="hidden" name="team" value="<?= (int) $userTeamId ?>">
            <?php endif; ?>
        </div>
        <div class="col-md-2">
            <label class="form-label">Event</label>
            <select name="sport" class="form-select">
                <option value="">All Events</option>
                <?= renderSportSelectOptions($sports, $sportId) ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Events</label>
            <select name="multi_event" class="form-select">
                <option value="">All athletes</option>
                <option value="multi" <?= $multiEvent === 'multi' ? 'selected' : '' ?>>Multiple events</option>
                <option value="single" <?= $multiEvent === 'single' ? 'selected' : '' ?>>Single event</option>
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Filter</button></div>
    </form>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:3rem;"></th>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Gender</th>
                        <th>Team</th>
                        <th>Course</th>
                        <th>Year Level</th>
                        <th>Sport / Event</th>
                        <th>Category</th>
                        <th class="text-end" style="width:9rem;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($athletes as $a): ?>
                    <tr>
                        <td>
                            <?php if ($a['photo']): ?>
                            <img src="<?= UPLOAD_URL_ATHLETES . sanitize($a['photo']) ?>" alt="" class="rounded-circle" style="width:36px;height:36px;object-fit:cover">
                            <?php else: ?>
                            <div class="rounded-circle bg-secondary bg-opacity-25 d-inline-flex align-items-center justify-content-center" style="width:36px;height:36px"><i class="bi bi-person"></i></div>
                            <?php endif; ?>
                        </td>
                        <td><?= sanitize($a['student_id']) ?></td>
                        <td>
                            <strong><?= sanitize(athleteFullName($a)) ?></strong>
                            <br><small class="text-muted"><?= sanitize($a['athlete_code']) ?></small>
                        </td>
                        <td><?= sanitize(ucfirst($a['gender'] ?: '—')) ?></td>
                        <td>
                            <?php if ($a['team_name']): ?>
                            <span style="color:<?= sanitize($a['team_color']) ?>"><?= sanitize($a['team_name']) ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= sanitize($a['department'] ?: '—') ?></td>
                        <td><?= sanitize($a['year_level'] ?: '—') ?></td>
                        <td>
                            <?php if (!empty($a['sport_name_list'])): ?>
                            <div class="d-flex flex-wrap gap-1 align-items-center">
                                <?php if ((int) ($a['event_count'] ?? 0) >= 2): ?>
                                <span class="badge bg-info text-dark" title="Registered in multiple events"><?= (int) $a['event_count'] ?> events</span>
                                <?php endif; ?>
                                <?php foreach ($a['sport_name_list'] as $sportName): ?>
                                <span class="badge bg-primary-subtle text-primary-emphasis border"><?= sanitize($sportName) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($a['sport_category_list'])): ?>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($a['sport_category_list'] as $sportCategory): ?>
                                <span class="badge bg-secondary"><?= sanitize(ucfirst($sportCategory)) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="<?= BASE_URL ?>/intramurals/athletes/view.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                            <?php
                            $athTeam = !empty($a['team_id']) ? (int) $a['team_id'] : null;
                            $canEditAthlete = canManageIntramurals()
                                || ($athTeam && canManageTeamAthletes($athTeam))
                                || ($isCoach && $athTeam && canManageTeamRoster($athTeam));
                            if ($canEditAthlete):
                            ?>
                            <a href="<?= BASE_URL ?>/intramurals/athletes/edit.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-secondary"><?= $isCoach ? 'Roster' : 'Edit' ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($athletes)): ?>
                    <tr><td colspan="10" class="text-muted p-3">No athletes found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-3"><?= paginationLinks($pagination, BASE_URL . '/intramurals/athletes/index.php' . ($filterQuery !== '' ? '?' . $filterQuery : '')) ?></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
