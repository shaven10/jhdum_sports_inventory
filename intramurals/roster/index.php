<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
ensureSportCategoryEnum();

if (isTournamentManager() && !canManageIntramurals()) {
    flash('error', 'Official rosters are not available for tournament manager accounts.');
    redirect(BASE_URL . '/dashboard.php');
}

if (isCoach() && !canManageIntramurals() && !hasCoachAssignments()) {
    flash('error', 'You have no event coach assignments. Ask your unit manager to assign you under Teams → Event Coaches.');
    redirect(BASE_URL . '/intramurals/index.php');
}

$db = getDB();
$seasonId = getCurrentSeasonId();
$season = getCurrentSeason();
$search = get('search');
$sportId = get('sport');
$teamId = get('team');
$category = get('category');
$export = get('export');
applyUnitManagerTeamScope($teamId);

$sports = filterSportsForUser(filterSportsForCoach($db->query('SELECT * FROM intramural_sports ORDER BY ' . intramuralSportsOrderBy())->fetchAll()));
$teams = filterTeamsForCoach($db->query('SELECT id, name FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll());
$categoryOptions = sportCategoryOptions();
$lockTeamFilter = hasRole('unit_manager') && !canManageIntramurals() && getUserTeamId();

$where = ['a.is_active = 1'];
$params = [];
if ($seasonId) {
    $where[] = 'r.season_id = ?';
    $params[] = $seasonId;
}
if ($search) {
    $where[] = '(a.first_name LIKE ? OR a.last_name LIKE ? OR a.student_id LIKE ? OR r.jersey_number LIKE ? OR s.name LIKE ? OR r.event_category LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%", "%$search%", "%$search%"]);
}
if ($sportId !== '') {
    $where[] = 'r.sport_id = ?';
    $params[] = (int) $sportId;
}
if ($teamId !== '') {
    $where[] = 'r.team_id = ?';
    $params[] = (int) $teamId;
}
if ($category !== '' && array_key_exists($category, $categoryOptions)) {
    $where[] = 's.category = ?';
    $params[] = $category;
}
$whereClause = implode(' AND ', $where);
appendCoachAssignmentFilter($whereClause, $params);
appendTmSportFilter($whereClause, $params, 'r.sport_id');
if ($sportId !== '' && !canViewEvent((int) $sportId)) {
    flash('error', 'You do not have permission to view this event roster.');
    redirect(BASE_URL . '/intramurals/roster/index.php');
}

$sql = "SELECT r.*, a.first_name, a.last_name, a.student_id, a.athlete_code, a.gender, a.department, a.year_level,
               s.id as sport_id, s.name as sport_name, s.category as sport_category,
               t.name as team_name, t.color as team_color
        FROM intramural_registrations r
        JOIN intramural_athletes a ON r.athlete_id = a.id
        JOIN intramural_sports s ON r.sport_id = s.id
        JOIN intramural_teams t ON r.team_id = t.id
        WHERE $whereClause
        ORDER BY s.name, s.category, t.name, CAST(r.jersey_number AS UNSIGNED), a.last_name, a.first_name";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$scopedTeamForDupes = ($teamId !== '') ? (int) $teamId : null;
if ($lockTeamFilter && $teamId !== '') {
    $scopedTeamForDupes = (int) $teamId;
} elseif (hasRole('unit_manager') && !canManageIntramurals() && getUserTeamId()) {
    $scopedTeamForDupes = (int) getUserTeamId();
}
$duplicateGroups = getDuplicateAthleteNameGroups($seasonId, $scopedTeamForDupes);
$duplicateAthleteIds = [];
foreach ($duplicateGroups as $group) {
    foreach ($group['athletes'] as $athlete) {
        $duplicateAthleteIds[(int) $athlete['id']] = $group['display_name'];
    }
}

$categoryBadgeClass = static function (string $cat): string {
    $cat = strtolower($cat);
    if ($cat === 'men') {
        return 'bg-primary';
    }
    if ($cat === 'women') {
        return 'bg-danger';
    }
    if ($cat === 'mixed') {
        return 'bg-info text-dark';
    }
    return 'bg-secondary';
};

$grouped = [];
foreach ($rows as $r) {
    $key = $r['sport_id'] . '|' . $r['sport_name'] . '|' . $r['sport_category'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'sport_id' => (int) $r['sport_id'],
            'sport_name' => $r['sport_name'],
            'sport_category' => $r['sport_category'],
            'athletes' => [],
        ];
    }
    $grouped[$key]['athletes'][] = $r;
}

if ($export === 'excel' || $export === 'csv') {
    $headers = ['Event', 'Category', 'Jersey No.', 'Student ID', 'Athlete Name', 'Gender', 'Team', 'Course', 'Year Level', 'Position', 'Division'];
    $csvRows = [];
    foreach ($grouped as $group) {
        $catLabel = $categoryOptions[$group['sport_category']] ?? ucfirst($group['sport_category']);
        foreach ($group['athletes'] as $r) {
            $csvRows[] = [
                $group['sport_name'],
                $catLabel,
                $r['jersey_number'] ?: '',
                $r['student_id'],
                athleteFullNameReport($r),
                ucfirst($r['gender'] ?: ''),
                $r['team_name'],
                $r['department'] ?: '',
                $r['year_level'] ?: '',
                $r['position'] ?: '',
                $r['event_category'] ?: '',
            ];
        }
    }
    exportCsv('athlete-roster-' . date('Ymd') . '.csv', $headers, $csvRows);
}

$seasonLabel = $season ? seasonLabel($season) : 'No active season';
$querySuffix = 'search=' . urlencode($search) . '&sport=' . urlencode($sportId) . '&team=' . urlencode($teamId) . '&category=' . urlencode($category);

$pageTitle = 'Athlete Roster';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
    <div>
        <h1><i class="bi bi-list-ul"></i> Athlete Roster</h1>
        <p class="text-muted mb-0"><?= count($rows) ?> athlete<?= count($rows) === 1 ? '' : 's' ?> across <?= count($grouped) ?> event<?= count($grouped) === 1 ? '' : 's' ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (canImportRoster()): ?>
        <a href="<?= BASE_URL ?>/intramurals/roster/import.php" class="btn btn-primary"><i class="bi bi-file-earmark-arrow-up"></i> Import Excel</a>
        <a href="<?= BASE_URL ?>/intramurals/roster/import.php?download=template" class="btn btn-outline-success"><i class="bi bi-download"></i> Download Template</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/intramurals/roster/gallery.php" class="btn btn-outline-primary"><i class="bi bi-images"></i> Entry Form Gallery</a>
        <a href="<?= BASE_URL ?>/intramurals/roster/team_list.php" class="btn btn-outline-primary"><i class="bi bi-people"></i> Team Athlete List</a>
        <?php if (!empty($duplicateGroups) && (canManageTeamAthletes() || canManageIntramurals())): ?>
        <a href="<?= BASE_URL ?>/intramurals/roster/duplicates.php" class="btn btn-warning">
            <i class="bi bi-exclamation-triangle"></i> Duplicates (<?= count($duplicateGroups) ?>)
        </a>
        <?php endif; ?>
        <a href="?<?= $querySuffix ?>&export=excel" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
        <button type="button" class="btn btn-outline-secondary" onclick="printReport()"><i class="bi bi-printer"></i> Print / PDF</button>
        <a href="<?= BASE_URL ?>/intramurals/index.php" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

<div class="filter-bar no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Search</label>
            <input type="text" name="search" class="form-control" value="<?= sanitize($search) ?>" placeholder="Name, jersey, student ID, event...">
        </div>
        <div class="col-md-2">
            <label class="form-label">Event</label>
            <select name="sport" class="form-select">
                <option value="">All Events</option>
                <?= renderSportSelectOptions($sports, $sportId) ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Category</label>
            <select name="category" class="form-select">
                <option value="">All Categories</option>
                <?php foreach ($categoryOptions as $val => $label): ?>
                <option value="<?= $val ?>" <?= $category === $val ? 'selected' : '' ?>><?= sanitize($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Team</label>
            <select name="team" class="form-select" <?= !empty($lockTeamFilter) ? 'disabled' : '' ?>>
                <?php if (empty($lockTeamFilter)): ?>
                <option value="">All Teams</option>
                <?php endif; ?>
                <?php foreach ($teams as $t): ?>
                <option value="<?= $t['id'] ?>" <?= $teamId === (string) $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($lockTeamFilter)): ?>
            <input type="hidden" name="team" value="<?= (int) $teamId ?>">
            <?php endif; ?>
        </div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Apply</button></div>
    </form>
</div>

<?php if (!empty($duplicateGroups)): ?>
<div class="alert alert-warning no-print">
    <i class="bi bi-exclamation-triangle"></i>
    <strong><?= count($duplicateGroups) ?></strong> athlete name<?= count($duplicateGroups) === 1 ? '' : 's' ?>
    appear<?= count($duplicateGroups) === 1 ? 's' : '' ?> on multiple accounts (different student IDs).
    Multiple events should use one account only.
    <?php if (canManageTeamAthletes() || canManageIntramurals()): ?>
    <a href="<?= BASE_URL ?>/intramurals/roster/duplicates.php" class="alert-link">Review and merge duplicates</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="roster-print-doc" data-report-capture>
    <?php
    $rosterMetaParts = [];
    $rosterMetaParts[] = 'Season: ' . $seasonLabel;
    $rosterMetaParts[] = count($rows) . ' athlete' . (count($rows) === 1 ? '' : 's');
    $rosterMetaParts[] = count($grouped) . ' event' . (count($grouped) === 1 ? '' : 's');
    if ($search) {
        $rosterMetaParts[] = 'Search: ' . $search;
    }
    if ($sportId !== '') {
        $filterSport = null;
        foreach ($sports as $s) {
            if ((string) $s['id'] === $sportId) {
                $filterSport = $s;
                break;
            }
        }
        $rosterMetaParts[] = 'Event: ' . ($filterSport ? sportLabel($filterSport) : $sportId);
    }
    if ($category !== '') {
        $rosterMetaParts[] = 'Category: ' . ($categoryOptions[$category] ?? $category);
    }
    if ($teamId !== '') {
        $filterTeam = null;
        foreach ($teams as $t) {
            if ((string) $t['id'] === $teamId) {
                $filterTeam = $t;
                break;
            }
        }
        $rosterMetaParts[] = 'Team: ' . ($filterTeam['name'] ?? $teamId);
    }
    echo renderReportHeader('Official Athlete Roster', ['meta' => implode(' · ', $rosterMetaParts)]);
    ?>

    <?php if (empty($grouped)): ?>
    <div class="alert alert-info">No roster entries found.</div>
    <?php else: ?>
    <?php foreach ($grouped as $group): ?>
    <?php
        $catKey = strtolower((string) $group['sport_category']);
        $catLabel = $categoryOptions[$catKey] ?? ucfirst($group['sport_category']);
        $athletes = $group['athletes'];
    ?>
    <section class="card mb-4 roster-event-group">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong class="roster-event-title"><?= sanitize($group['sport_name']) ?></strong>
                <span class="badge <?= $categoryBadgeClass($catKey) ?> ms-2"><?= sanitize($catLabel) ?></span>
            </div>
            <span class="badge bg-secondary"><?= count($athletes) ?> athlete<?= count($athletes) === 1 ? '' : 's' ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0 align-middle roster-event-table">
                    <thead class="table-light">
                        <tr>
                            <th style="width:3rem;">#</th>
                            <th style="width:4.5rem;">Jersey</th>
                            <th>Student ID</th>
                            <th>Athlete Name</th>
                            <th>Gender</th>
                            <th>Team</th>
                            <th>Year Level</th>
                            <th>Position</th>
                            <th>Division</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($athletes as $i => $r): ?>
                        <?php $isDuplicateAccount = isset($duplicateAthleteIds[(int) $r['athlete_id']]); ?>
                        <tr class="<?= $isDuplicateAccount ? 'table-warning' : '' ?>">
                            <td><?= $i + 1 ?></td>
                            <td><?= sanitize($r['jersey_number'] ?: '—') ?></td>
                            <td><?= sanitize($r['student_id']) ?></td>
                            <td>
                                <span class="d-print-none">
                                    <a class="roster-athlete-link" href="<?= BASE_URL ?>/intramurals/athletes/view.php?id=<?= $r['athlete_id'] ?>"><?= sanitize(athleteFullNameReport($r)) ?></a>
                                    <?php if ($isDuplicateAccount): ?>
                                    <span class="badge bg-warning text-dark ms-1 no-print" title="Same name exists on another account">Duplicate name</span>
                                    <?php endif; ?>
                                </span>
                                <span class="d-none d-print-inline"><?= sanitize(athleteFullNameReport($r)) ?></span>
                            </td>
                            <td><?= sanitize(ucfirst($r['gender'] ?: '—')) ?></td>
                            <td><span class="roster-team-name"><?= sanitize($r['team_name']) ?></span></td>
                            <td><?= sanitize($r['year_level'] ?: '—') ?></td>
                            <td><?= sanitize($r['position'] ?: '—') ?></td>
                            <td><?= sanitize($r['event_category'] ?: '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <?php endforeach; ?>
    <?php endif; ?>

    <?= renderReportFooter('Athlete Roster · ' . $seasonLabel) ?>
</div>

<p class="text-muted small no-print mt-3">Tip: Use Print / PDF and choose “Save as PDF” in your browser print dialog. Athletes are grouped by event and category.</p>

<style>
@media print {
    .sidebar, .season-bar, .alert, .page-header, .filter-bar, .no-print {
        display: none !important;
    }
    .roster-print-doc {
        color: #000 !important;
        width: 100% !important;
    }
    .roster-event-group {
        border: 1px solid #333 !important;
        box-shadow: none !important;
        break-inside: auto;
        page-break-inside: auto;
        margin-bottom: 0.85rem !important;
        overflow: visible !important;
    }
    .roster-event-group .card-header,
    .roster-event-group .card-body {
        overflow: visible !important;
    }
    .roster-event-group .card-header {
        background: #f0f0f0 !important;
        border-bottom: 1px solid #333 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .roster-event-title {
        font-size: 12pt;
        font-weight: 700;
    }
    .roster-event-group .table-responsive {
        overflow: visible !important;
        border-radius: 0 !important;
    }
    .roster-event-table {
        width: 100% !important;
        font-size: 10pt !important;
        border-collapse: collapse !important;
    }
    .roster-event-table thead {
        display: table-header-group !important;
    }
    .roster-event-table tbody {
        display: table-row-group !important;
    }
    .roster-event-table tr {
        display: table-row !important;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .roster-event-table th,
    .roster-event-table td {
        display: table-cell !important;
        border: 1px solid #666 !important;
        padding: 4px 6px !important;
        color: #000 !important;
        background: transparent !important;
        vertical-align: middle !important;
    }
    .roster-event-table thead th {
        background: #e8e8e8 !important;
        font-weight: 700 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .roster-team-name {
        color: #000 !important;
    }
    .badge {
        border: 1px solid #666;
        color: #000 !important;
        background: #fff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
