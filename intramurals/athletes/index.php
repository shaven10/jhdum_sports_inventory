<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$db = getDB();
$seasonId = getCurrentSeasonId();
$search = get('search');
$teamId = get('team');
$sportId = get('sport');
$page = max(1, (int) get('page', '1'));
$perPage = 15;
$userTeamId = getUserTeamId();
$isCoach = hasRole('coach') && !canManageIntramurals();
$coachTeamIds = $isCoach ? getCoachTeamIds() : [];

// Unit managers default to their team; coaches see athletes on teams they coach
if (hasRole('unit_manager') && $userTeamId) {
    $teamId = (string) $userTeamId;
} elseif ($isCoach && count($coachTeamIds) === 1 && $teamId === '') {
    $teamId = (string) $coachTeamIds[0];
}

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
    // Coaches only list athletes registered in (or belonging to teams of) their events
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
    }
}

$whereClause = implode(' AND ', $where);
$countStmt = $db->prepare("SELECT COUNT(*) FROM intramural_athletes a WHERE $whereClause");
$countStmt->execute($params);
$pagination = paginate((int) $countStmt->fetchColumn(), $perPage, $page);

$sportCountSql = $seasonId
    ? '(SELECT COUNT(*) FROM intramural_registrations r WHERE r.athlete_id = a.id AND r.season_id = ' . (int) $seasonId . ')'
    : '(SELECT COUNT(*) FROM intramural_registrations r WHERE r.athlete_id = a.id)';

$sql = "SELECT a.*, t.name as team_name, t.color as team_color,
        $sportCountSql as sport_count
        FROM intramural_athletes a
        LEFT JOIN intramural_teams t ON a.team_id = t.id
        WHERE $whereClause
        ORDER BY a.last_name, a.first_name
        LIMIT {$pagination['offset']}, $perPage";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$athletes = $stmt->fetchAll();

$teams = $db->query('SELECT id, name FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
$sports = $db->query('SELECT id, name, category FROM intramural_sports WHERE is_active = 1 ORDER BY name, category')->fetchAll();

$pageTitle = 'Athlete Management';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-person-badge"></i> Athletes</h1>
        <p class="text-muted mb-0">Register and manage intramural athletes</p>
    </div>
    <div class="d-flex gap-2">
        <?php if (canManageTeamAthletes()): ?>
        <a href="<?= BASE_URL ?>/intramurals/athletes/add.php" class="btn btn-primary"><i class="bi bi-person-plus"></i> Register Athlete</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/intramurals/index.php" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

<div class="filter-bar">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Search</label>
            <input type="text" name="search" class="form-control" value="<?= sanitize($search) ?>" placeholder="Name, student ID...">
        </div>
        <div class="col-md-3">
            <label class="form-label">Team / House</label>
            <select name="team" class="form-select" <?= hasRole('unit_manager') && !canManageIntramurals() ? 'disabled' : '' ?>>
                <option value="">All Teams</option>
                <?php foreach ($teams as $t): ?>
                <?php if ($isCoach && !in_array((int) $t['id'], $coachTeamIds, true)) continue; ?>
                <option value="<?= $t['id'] ?>" <?= $teamId === (string) $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (hasRole('unit_manager') && !canManageIntramurals() && $userTeamId): ?>
            <input type="hidden" name="team" value="<?= (int) $userTeamId ?>">
            <?php endif; ?>
        </div>
        <div class="col-md-3">
            <label class="form-label">Sport</label>
            <select name="sport" class="form-select">
                <option value="">All Sports</option>
                <?php foreach ($sports as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $sportId === (string) $s['id'] ? 'selected' : '' ?>><?= sanitize(sportLabel($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Filter</button></div>
    </form>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th></th>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Student ID</th>
                        <th>Team</th>
                        <th>Department</th>
                        <th>Sports</th>
                        <th></th>
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
                        <td><?= sanitize($a['athlete_code']) ?></td>
                        <td><strong><?= sanitize(athleteFullName($a)) ?></strong></td>
                        <td><?= sanitize($a['student_id']) ?></td>
                        <td>
                            <?php if ($a['team_name']): ?>
                            <span style="color:<?= sanitize($a['team_color']) ?>"><?= sanitize($a['team_name']) ?></span>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td><?= sanitize($a['department'] ?: '-') ?></td>
                        <td><?= (int) $a['sport_count'] ?></td>
                        <td class="text-nowrap">
                            <a href="<?= BASE_URL ?>/intramurals/athletes/view.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                            <?php
                            $athTeam = !empty($a['team_id']) ? (int) $a['team_id'] : null;
                            if (canManageIntramurals() || ($athTeam && (canManageTeamRoster($athTeam) || canManageTeamAthletes($athTeam)))):
                            ?>
                            <a href="<?= BASE_URL ?>/intramurals/athletes/edit.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-secondary"><?= $isCoach ? 'Roster' : 'Edit' ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($athletes)): ?>
                    <tr><td colspan="8" class="text-muted p-3">No athletes found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-3"><?= paginationLinks($pagination, BASE_URL . '/intramurals/athletes/index.php?search=' . urlencode($search) . '&team=' . urlencode($teamId) . '&sport=' . urlencode($sportId)) ?></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
