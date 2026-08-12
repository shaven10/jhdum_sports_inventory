<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
// Intramurals reports are available to all intramurals viewers (including tabulator / team roles)

$db = getDB();
$seasonId = getCurrentSeasonId();
$type = get('type', 'athletes');
$sportId = get('sport');
$teamId = get('team');
$export = get('export');

$sports = $db->query('SELECT * FROM intramural_sports ORDER BY name, category')->fetchAll();
$teams = $db->query('SELECT * FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();

$titleMap = [
    'athletes' => 'Athlete List',
    'rosters' => 'Team Rosters',
    'schedules' => 'Game Schedules',
    'results' => 'Match Results',
    'medals' => 'Medal Tally',
    'standings' => 'Team Standings',
];
$reportTitle = $titleMap[$type] ?? 'Intramurals Report';

$headers = [];
$rows = [];
$htmlRows = [];

if ($type === 'athletes') {
    $sql = 'SELECT a.*, t.name as team_name FROM intramural_athletes a LEFT JOIN intramural_teams t ON a.team_id = t.id WHERE a.is_active = 1';
    $params = [];
    if ($teamId !== '') { $sql .= ' AND a.team_id = ?'; $params[] = (int) $teamId; }
    $sql .= ' ORDER BY a.last_name, a.first_name';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();
    $headers = ['Code', 'Student ID', 'Name', 'Gender', 'Team', 'Department', 'Year Level'];
    foreach ($data as $r) {
        $rows[] = [$r['athlete_code'], $r['student_id'], athleteFullName($r), ucfirst($r['gender']), $r['team_name'] ?: '', $r['department'] ?: '', $r['year_level'] ?: ''];
        $htmlRows[] = $rows[count($rows) - 1];
    }
} elseif ($type === 'rosters') {
    $sql = "SELECT r.*, a.first_name, a.last_name, a.student_id, s.name as sport_name, s.category, t.name as team_name
            FROM intramural_registrations r
            JOIN intramural_athletes a ON r.athlete_id = a.id
            JOIN intramural_sports s ON r.sport_id = s.id
            JOIN intramural_teams t ON r.team_id = t.id
            WHERE a.is_active = 1";
    $params = [];
    if ($seasonId) { $sql .= ' AND r.season_id = ?'; $params[] = $seasonId; }
    if ($sportId !== '') { $sql .= ' AND r.sport_id = ?'; $params[] = (int) $sportId; }
    if ($teamId !== '') { $sql .= ' AND r.team_id = ?'; $params[] = (int) $teamId; }
    $sql .= ' ORDER BY s.name, t.name, a.last_name';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();
    $headers = ['Sport', 'Category', 'Team', 'Athlete', 'Student ID', 'Jersey', 'Position', 'Event'];
    foreach ($data as $r) {
        $rows[] = [$r['sport_name'], ucfirst($r['category']), $r['team_name'], athleteFullName($r), $r['student_id'], $r['jersey_number'] ?: '', $r['position'] ?: '', $r['event_category'] ?: ''];
        $htmlRows[] = $rows[count($rows) - 1];
    }
} elseif ($type === 'schedules') {
    $sql = "SELECT m.*, s.name as sport_name, s.category, ta.name as team_a_name, tb.name as team_b_name
            FROM intramural_matches m
            JOIN intramural_sports s ON m.sport_id = s.id
            JOIN intramural_teams ta ON m.team_a_id = ta.id
            JOIN intramural_teams tb ON m.team_b_id = tb.id WHERE 1=1";
    $params = [];
    if ($seasonId) { $sql .= ' AND m.season_id = ?'; $params[] = $seasonId; }
    if ($sportId !== '') { $sql .= ' AND m.sport_id = ?'; $params[] = (int) $sportId; }
    $sql .= ' ORDER BY m.scheduled_at';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();
    $headers = ['Date/Time', 'Sport', 'Category', 'Team A', 'Team B', 'Venue', 'Referee', 'Status'];
    foreach ($data as $r) {
        $rows[] = [formatDateTime($r['scheduled_at']), $r['sport_name'], ucfirst($r['category']), $r['team_a_name'], $r['team_b_name'], $r['venue'] ?: '', $r['referee_name'] ?: '', ucfirst($r['status'])];
        $htmlRows[] = $rows[count($rows) - 1];
    }
} elseif ($type === 'results') {
    $sql = "SELECT m.*, s.name as sport_name, s.category, ta.name as team_a_name, tb.name as team_b_name, tw.name as winner_name
            FROM intramural_matches m
            JOIN intramural_sports s ON m.sport_id = s.id
            JOIN intramural_teams ta ON m.team_a_id = ta.id
            JOIN intramural_teams tb ON m.team_b_id = tb.id
            LEFT JOIN intramural_teams tw ON m.winner_team_id = tw.id
            WHERE m.status IN ('completed', 'forfeit')";
    $params = [];
    if ($seasonId) { $sql .= ' AND m.season_id = ?'; $params[] = $seasonId; }
    if ($sportId !== '') { $sql .= ' AND m.sport_id = ?'; $params[] = (int) $sportId; }
    $sql .= ' ORDER BY m.scheduled_at DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();
    $headers = ['Date', 'Sport', 'Team A', 'Score A', 'Score B', 'Team B', 'Winner', 'Status'];
    foreach ($data as $r) {
        $rows[] = [formatDateTime($r['scheduled_at']), $r['sport_name'], $r['team_a_name'], $r['score_a'], $r['score_b'], $r['team_b_name'], $r['winner_name'] ?: 'Draw', ucfirst($r['status'])];
        $htmlRows[] = $rows[count($rows) - 1];
    }
} elseif ($type === 'medals') {
    $overall = computeOverallStandings();
    $headers = ['Rank', 'Team', 'Gold', 'Silver', 'Bronze', 'Total Points'];
    foreach ($overall['standings'] as $r) {
        $rows[] = [$r['rank'], $r['team_name'], $r['gold'], $r['silver'], $r['bronze'], $r['total']];
        $htmlRows[] = $rows[count($rows) - 1];
    }
} elseif ($type === 'standings') {
    $sid = $sportId !== '' ? (int) $sportId : ((int) ($sports[0]['id'] ?? 0));
    $blocks = $sid ? computeSportStandings($sid) : [];
    $block = $blocks[$sid] ?? null;
    $headers = ['Rank', 'Placement', 'Team', 'Wins', 'Losses', 'Draws', 'Match Pts', 'Event Pts', 'Diff'];
    if ($block) {
        foreach ($block['standings'] as $r) {
            if ($r['played'] === 0) continue;
            $rows[] = [$r['rank'], $r['placement_label'] ?: '', $r['team_name'], $r['wins'], $r['losses'], $r['draws'], $r['points'], $r['placement_points'], $r['diff']];
            $htmlRows[] = $rows[count($rows) - 1];
        }
    }
}

if ($export === 'excel') {
    exportCsv('intramurals-' . $type . '-' . date('Ymd') . '.csv', $headers, $rows);
}

$pageTitle = 'Intramurals Reports';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
    <div>
        <h1><i class="bi bi-printer"></i> Intramurals Reports</h1>
        <p class="text-muted mb-0">Printable reports with Excel/PDF export</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-success" href="?type=<?= urlencode($type) ?>&sport=<?= urlencode($sportId) ?>&team=<?= urlencode($teamId) ?>&export=excel"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
        <button class="btn btn-outline-secondary" onclick="printReport()"><i class="bi bi-printer"></i> Print / PDF</button>
        <a href="<?= BASE_URL ?>/intramurals/index.php" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

<div class="filter-bar no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Report Type</label>
            <select name="type" class="form-select">
                <?php foreach ($titleMap as $key => $label): ?>
                <option value="<?= $key ?>" <?= $type === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Sport</label>
            <select name="sport" class="form-select">
                <option value="">All / Default</option>
                <?php foreach ($sports as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $sportId === (string) $s['id'] ? 'selected' : '' ?>><?= sanitize(sportLabel($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Team</label>
            <select name="team" class="form-select">
                <option value="">All Teams</option>
                <?php foreach ($teams as $t): ?>
                <option value="<?= $t['id'] ?>" <?= $teamId === (string) $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Generate</button></div>
    </form>
</div>

<?= renderReportHeader($reportTitle, [
    'meta' => (function_exists('getCurrentSeason') && getCurrentSeason())
        ? ('Season: ' . seasonLabel(getCurrentSeason()))
        : '',
]) ?>

<div class="card">
    <div class="card-header d-flex justify-content-between">
        <span><?= sanitize($reportTitle) ?></span>
        <small class="text-muted"><?= sanitize(APP_CAMPUS) ?> · <?= date('M d, Y h:i A') ?></small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead class="table-light">
                    <tr><?php foreach ($headers as $h): ?><th><?= sanitize($h) ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <?php foreach ($htmlRows as $row): ?>
                    <tr><?php foreach ($row as $cell): ?><td><?= sanitize((string) $cell) ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                    <?php if (empty($htmlRows)): ?>
                    <tr><td colspan="<?= max(1, count($headers)) ?>" class="text-muted p-3">No data for this report.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<p class="text-muted small mt-2 no-print">For PDF: click Print / PDF and choose “Save as PDF”.</p>
<?= renderReportFooter($reportTitle) ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
