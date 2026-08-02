<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$db = getDB();
$seasonId = getCurrentSeasonId();
$search = get('search');
$sportId = get('sport');
$export = get('export');

$sports = $db->query('SELECT * FROM intramural_sports WHERE is_active = 1 ORDER BY name, category')->fetchAll();

$where = ['a.is_active = 1'];
$params = [];
if ($seasonId) {
    $where[] = 'r.season_id = ?';
    $params[] = $seasonId;
}
if ($search) {
    $where[] = '(a.first_name LIKE ? OR a.last_name LIKE ? OR a.student_id LIKE ? OR r.jersey_number LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}
if ($sportId !== '') {
    $where[] = 'r.sport_id = ?';
    $params[] = (int) $sportId;
}
$whereClause = implode(' AND ', $where);

$sql = "SELECT r.*, a.first_name, a.last_name, a.student_id, a.athlete_code,
               s.name as sport_name, s.category as sport_category,
               t.name as team_name, t.color as team_color
        FROM intramural_registrations r
        JOIN intramural_athletes a ON r.athlete_id = a.id
        JOIN intramural_sports s ON r.sport_id = s.id
        JOIN intramural_teams t ON r.team_id = t.id
        WHERE $whereClause
        ORDER BY s.name, s.category, t.name, CAST(r.jersey_number AS UNSIGNED), a.last_name";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($export === 'excel' || $export === 'csv') {
    $headers = ['Sport', 'Category', 'Jersey No.', 'Athlete Name', 'Student ID', 'Team', 'Position', 'Event/Category'];
    $csvRows = [];
    foreach ($rows as $r) {
        $csvRows[] = [
            $r['sport_name'],
            ucfirst($r['sport_category']),
            $r['jersey_number'] ?: '',
            athleteFullName($r),
            $r['student_id'],
            $r['team_name'],
            $r['position'] ?: '',
            $r['event_category'] ?: '',
        ];
    }
    exportCsv('athlete-roster-' . date('Ymd') . '.csv', $headers, $csvRows);
}

$grouped = [];
foreach ($rows as $r) {
    $key = $r['sport_name'] . '|' . $r['sport_category'];
    $grouped[$key][] = $r;
}

$pageTitle = 'Athlete Roster';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
    <div>
        <h1><i class="bi bi-list-ul"></i> Athlete Roster (Per Sport)</h1>
        <p class="text-muted mb-0">Official list of athletes by sport</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (canManageTeamAthletes() || canManageTeamRoster()): ?>
        <a href="<?= BASE_URL ?>/intramurals/roster/import.php" class="btn btn-primary"><i class="bi bi-file-earmark-arrow-up"></i> Import Excel</a>
        <a href="<?= BASE_URL ?>/intramurals/roster/import.php?download=template" class="btn btn-outline-success"><i class="bi bi-download"></i> Download Template</a>
        <?php endif; ?>
        <a href="?search=<?= urlencode($search) ?>&sport=<?= urlencode($sportId) ?>&export=excel" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
        <button type="button" class="btn btn-outline-secondary" onclick="printReport()"><i class="bi bi-printer"></i> Print / PDF</button>
        <a href="<?= BASE_URL ?>/intramurals/index.php" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

<div class="filter-bar no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Search athlete</label>
            <input type="text" name="search" class="form-control" value="<?= sanitize($search) ?>" placeholder="Name, jersey, student ID...">
        </div>
        <div class="col-md-4">
            <label class="form-label">Filter by sport</label>
            <select name="sport" class="form-select">
                <option value="">All Sports</option>
                <?php foreach ($sports as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $sportId === (string) $s['id'] ? 'selected' : '' ?>><?= sanitize(sportLabel($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Apply</button></div>
    </form>
</div>

<?php if (empty($grouped)): ?>
<div class="alert alert-info">No roster entries found.</div>
<?php endif; ?>

<?php foreach ($grouped as $key => $list): ?>
<?php
    [$sportName, $category] = explode('|', $key);
    $hasJersey = false;
    foreach ($list as $r) {
        if ($r['jersey_number']) { $hasJersey = true; break; }
    }
?>
<div class="card mb-4">
    <div class="card-header">
        <strong><?= sanitize($sportName) ?></strong>
        <span class="text-muted">— <?= ucfirst($category) ?></span>
        <span class="badge bg-secondary ms-2"><?= count($list) ?> athletes</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <?php if ($hasJersey): ?><th>Jersey No.</th><?php endif; ?>
                        <th>Athlete Name</th>
                        <th>Team</th>
                        <th>Position</th>
                        <th>Event/Category</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($list as $r): ?>
                    <tr>
                        <?php if ($hasJersey): ?><td><?= sanitize($r['jersey_number'] ?: '-') ?></td><?php endif; ?>
                        <td><a href="<?= BASE_URL ?>/intramurals/athletes/view.php?id=<?= $r['athlete_id'] ?>"><?= sanitize(athleteFullName($r)) ?></a></td>
                        <td style="color:<?= sanitize($r['team_color']) ?>"><?= sanitize($r['team_name']) ?></td>
                        <td><?= sanitize($r['position'] ?: '-') ?></td>
                        <td><?= sanitize($r['event_category'] ?: '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>

<p class="text-muted small no-print">Tip: Use Print / PDF and choose “Save as PDF” in your browser print dialog.</p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
