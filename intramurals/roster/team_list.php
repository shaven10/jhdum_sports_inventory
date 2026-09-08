<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
ensureSportCategoryEnum();

if (isTournamentManager() && !canManageIntramurals()) {
    flash('error', 'Team athlete list is not available for tournament manager accounts.');
    redirect(BASE_URL . '/dashboard.php');
}

if (isCoach() && !canManageIntramurals() && !hasCoachAssignments()) {
    flash('error', 'You have no event coach assignments. Ask your unit manager to assign you under Teams → Event Coaches.');
    redirect(BASE_URL . '/intramurals/index.php');
}

$db = getDB();
$seasonId = getCurrentSeasonId();
$season = getCurrentSeason();
$sportId = get('sport');
$teamId = get('team');
$category = get('category');
$export = get('export');
applyUnitManagerTeamScope($teamId);

$sports = filterSportsForUser(filterSportsForCoach($db->query('SELECT * FROM intramural_sports ORDER BY ' . intramuralSportsOrderBy())->fetchAll()));
$teams = filterTeamsForCoach($db->query('SELECT id, name, color, department FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll());
$categoryOptions = sportCategoryOptions();
$lockTeamFilter = hasRole('unit_manager') && !canManageIntramurals() && getUserTeamId();

$where = ['a.is_active = 1'];
$params = [];
if ($seasonId) {
    $where[] = 'r.season_id = ?';
    $params[] = $seasonId;
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
    flash('error', 'You do not have permission to view this event athlete list.');
    redirect(BASE_URL . '/intramurals/roster/team_list.php');
}

$sql = "SELECT r.*, a.id AS athlete_id, a.first_name, a.last_name, a.student_id, a.athlete_code,
               a.gender, a.department, a.year_level, a.birthdate,
               s.id AS sport_id, s.name AS sport_name, s.category AS sport_category,
               t.id AS team_id, t.name AS team_name, t.color AS team_color, t.department AS team_department
        FROM intramural_registrations r
        JOIN intramural_athletes a ON r.athlete_id = a.id
        JOIN intramural_sports s ON r.sport_id = s.id
        JOIN intramural_teams t ON r.team_id = t.id
        WHERE $whereClause
        ORDER BY s.name, s.category, t.name, CAST(r.jersey_number AS UNSIGNED), a.last_name, a.first_name";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

/** Group by sport/event + team (one printable sheet each). */
$forms = [];
foreach ($rows as $r) {
    $key = (int) $r['sport_id'] . '|' . (int) $r['team_id'];
    if (!isset($forms[$key])) {
        $forms[$key] = [
            'sport_id' => (int) $r['sport_id'],
            'sport_name' => $r['sport_name'],
            'sport_category' => $r['sport_category'],
            'team_id' => (int) $r['team_id'],
            'team_name' => $r['team_name'],
            'team_color' => $r['team_color'] ?? '#c62828',
            'team_department' => $r['team_department'] ?? '',
            'athletes' => [],
        ];
    }
    $forms[$key]['athletes'][] = $r;
}

if ($export === 'excel' || $export === 'csv') {
    $headers = ['Team', 'School', '#', 'Athlete Name', 'Student ID', 'Gender', 'Course', 'Year Level', 'Sport/Event', 'Category'];
    $csvRows = [];
    foreach ($forms as $form) {
        $catKey = strtolower((string) $form['sport_category']);
        $catLabel = $categoryOptions[$catKey] ?? ucfirst((string) $form['sport_category']);
        $school = trim((string) ($form['team_department'] ?? '')) ?: $form['team_name'];
        foreach ($form['athletes'] as $i => $athlete) {
            $csvRows[] = [
                $form['team_name'],
                $school,
                $i + 1,
                athleteFullNameReport($athlete),
                $athlete['student_id'],
                ucfirst((string) ($athlete['gender'] ?? '')),
                $athlete['department'] ?? '',
                $athlete['year_level'] ?? '',
                $form['sport_name'],
                $catLabel,
            ];
        }
    }
    exportCsv('team-athlete-list-' . date('Ymd') . '.csv', $headers, $csvRows);
}

$seasonLabel = $season ? seasonLabel($season) : 'No active season';
$eventTitle = $season
    ? (strtoupper(trim(($season['name'] ?? '') . ' ' . ($season['year_label'] ?? ''))))
    : strtoupper(APP_CAMPUS . ' PALARO');
$eventDates = '';
if ($season && !empty($season['start_date']) && !empty($season['end_date'])) {
    $eventDates = formatDate($season['start_date'], 'F j') . ' - ' . formatDate($season['end_date'], 'F j, Y');
} elseif ($season && !empty($season['start_date'])) {
    $eventDates = formatDate($season['start_date'], 'F j, Y');
}
$collegeLogo = BASE_URL . '/assets/img/jhcsc-logo.png';
$sdoLogo = appLogoUrl();

$courseYear = static function (array $athlete): string {
    $parts = array_filter([
        trim((string) ($athlete['department'] ?? '')),
        trim((string) ($athlete['year_level'] ?? '')),
    ]);
    return implode(' · ', $parts);
};

$pageTitle = 'Team Athlete List';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
    <div>
        <h1><i class="bi bi-people"></i> Team Athlete List</h1>
        <p class="text-muted mb-0">Athletes grouped by sport/event · <?= count($forms) ?> form<?= count($forms) === 1 ? '' : 's' ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/intramurals/roster/index.php" class="btn btn-outline-primary"><i class="bi bi-list-ul"></i> Athlete Roster</a>
        <a href="<?= BASE_URL ?>/intramurals/roster/gallery.php" class="btn btn-outline-primary"><i class="bi bi-images"></i> Entry Gallery</a>
        <a href="?sport=<?= urlencode($sportId) ?>&team=<?= urlencode($teamId) ?>&category=<?= urlencode($category) ?>&export=excel" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        <button type="button" class="btn btn-outline-secondary" onclick="printReport()"><i class="bi bi-printer"></i> Print / PDF</button>
        <a href="<?= BASE_URL ?>/intramurals/index.php" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

<div class="filter-bar no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
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
                <option value="<?= sanitize($val) ?>" <?= $category === $val ? 'selected' : '' ?>><?= sanitize($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Team</label>
            <select name="team" class="form-select" <?= !empty($lockTeamFilter) ? 'disabled' : '' ?>>
                <?php if (empty($lockTeamFilter)): ?>
                <option value="">All Teams</option>
                <?php endif; ?>
                <?php foreach ($teams as $t): ?>
                <option value="<?= (int) $t['id'] ?>" <?= $teamId === (string) $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($lockTeamFilter)): ?>
            <input type="hidden" name="team" value="<?= (int) $teamId ?>">
            <?php endif; ?>
        </div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Generate</button></div>
    </form>
    <p class="text-muted small mb-0 mt-2">Tip: Select an event and team for a single printable sheet. Leave blank to print all matching forms.</p>
</div>

<?php if (empty($forms)): ?>
<div class="alert alert-info no-print">No roster athletes found for the selected filters.</div>
<?php endif; ?>

<div class="team-list-print-root">
<?php
$formIndex = 0;
$totalForms = count($forms);
foreach ($forms as $form):
    $formIndex++;
    $catKey = strtolower((string) $form['sport_category']);
    $catLabel = strtoupper($categoryOptions[$catKey] ?? $form['sport_category']);
    $sportName = strtoupper((string) $form['sport_name']);
    $school = trim((string) ($form['team_department'] ?? '')) ?: $form['team_name'];
    $athleteCount = count($form['athletes']);
?>
<section class="entry-form-sheet<?= $formIndex < $totalForms ? ' entry-form-sheet-break' : '' ?>">
    <header class="entry-form-header">
        <div class="entry-form-header-top">
            <div class="entry-form-header-spacer"></div>
            <div class="entry-form-title-cluster">
                <img src="<?= sanitize($collegeLogo) ?>" alt="JHCSC" class="entry-form-logo" onerror="this.src='<?= sanitize($sdoLogo) ?>'">
                <div class="entry-form-title-block">
                    <div class="entry-form-republic">Republic of the Philippines</div>
                    <div class="entry-form-event-title"><?= sanitize($eventTitle) ?></div>
                    <div class="entry-form-office-heading">Sports Development Office</div>
                    <?php if ($eventDates !== ''): ?>
                    <div class="entry-form-event-dates"><?= sanitize($eventDates) ?></div>
                    <?php endif; ?>
                </div>
                <img src="<?= sanitize($sdoLogo) ?>" alt="Sports Development Office" class="entry-form-sdo-logo">
            </div>
            <div class="entry-form-meta-boxes">
                <div class="entry-form-code">JHCSCDC - SDO Form 1</div>
                <div class="entry-form-of-box">
                    <div class="entry-form-of-label">OFFICIAL LIST OF ATHLETES</div>
                    <div class="entry-form-of-sport"><?= sanitize($sportName) ?></div>
                </div>
            </div>
        </div>
        <div class="entry-form-fields">
            <div class="entry-form-field-school">
                <span class="entry-form-field-label">SCHOOL:</span>
                <span class="entry-form-field-value"><?= sanitize($school) ?></span>
            </div>
            <div class="entry-form-field-team">
                <span class="entry-form-team-badge">TEAM</span>
                <span class="entry-form-team-name" style="color:<?= sanitize($form['team_color'] ?: '#c62828') ?>"><?= sanitize($form['team_name']) ?></span>
            </div>
            <div class="entry-form-field-category">
                <span class="entry-form-field-label">CATEGORY:</span>
                <span class="entry-form-category-value"><?= sanitize($catLabel) ?></span>
            </div>
        </div>
    </header>

    <div class="team-list-body">
        <table class="team-list-table">
            <thead>
                <tr>
                    <th class="col-num col-header-strong">#</th>
                    <th class="col-name col-header-strong">Athlete Name</th>
                    <th class="col-id">Student ID</th>
                    <th class="col-gender">Gender</th>
                    <th class="col-course">Course &amp; Year</th>
                    <th class="col-event col-header-strong">Sport / Event</th>
                    <th class="col-category col-header-strong">Category</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($form['athletes'] as $i => $athlete): ?>
                <tr>
                    <td class="col-num"><?= $i + 1 ?></td>
                    <td class="col-name"><?= sanitize(athleteFullNameReport($athlete)) ?></td>
                    <td class="col-id"><?= sanitize((string) $athlete['student_id']) ?></td>
                    <td class="col-gender"><?= sanitize(ucfirst((string) ($athlete['gender'] ?? '')) ?: '—') ?></td>
                    <td class="col-course"><?= sanitize($courseYear($athlete) ?: '—') ?></td>
                    <td class="col-event"><?= sanitize($sportName) ?></td>
                    <td class="col-category"><?= sanitize($catLabel) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($athleteCount === 0): ?>
                <tr><td colspan="7" class="text-muted p-3">No athletes on roster for this event.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <footer class="entry-form-footer">
        <span><?= sanitize($seasonLabel) ?> · <?= sanitize($form['team_name']) ?> · <?= sanitize($sportName) ?> · <?= (int) $athleteCount ?> athlete<?= $athleteCount === 1 ? '' : 's' ?></span>
        <span><?= $totalForms > 1 ? 'Form ' . $formIndex . ' of ' . $totalForms : 'Team Athlete List' ?></span>
    </footer>
</section>
<?php endforeach; ?>
</div>

<style>
.team-list-print-root {
    --ef-border: #222;
    --ef-accent: #c62828;
    --ef-label-bg: #f3f3f3;
}

.entry-form-sheet {
    background: #fff;
    color: #111;
    border: 1px solid #c5c5c5;
    border-radius: 6px;
    padding: 1rem 1rem 0.75rem;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
    font-family: Arial, Helvetica, sans-serif;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
}

.entry-form-header-top {
    display: grid;
    grid-template-columns: minmax(180px, 1fr) auto minmax(180px, 1fr);
    gap: 0.75rem;
    align-items: center;
    margin-bottom: 0.7rem;
}

.entry-form-title-cluster {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.7rem;
    min-width: 0;
}

.entry-form-logo,
.entry-form-sdo-logo {
    width: 72px;
    height: 72px;
    object-fit: cover;
    border-radius: 50%;
    background: #fff;
    border: 1px solid #ddd;
    flex-shrink: 0;
}

.entry-form-title-block {
    text-align: center;
}

.entry-form-header-spacer {
    min-width: 0;
}

.entry-form-meta-boxes {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    justify-self: end;
    width: min(100%, 240px);
}

.entry-form-republic {
    font-family: "Times New Roman", Times, Georgia, serif;
    font-size: 0.95rem;
    font-weight: 600;
    letter-spacing: 0.03em;
    line-height: 1.2;
    margin-bottom: 0.12rem;
}

.entry-form-office-heading {
    font-family: "Times New Roman", Times, Georgia, serif;
    font-size: 1.05rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    line-height: 1.2;
    text-transform: uppercase;
    margin-top: 0.15rem;
    margin-bottom: 0;
}

.entry-form-event-title {
    font-family: "Times New Roman", Times, Georgia, serif;
    font-size: 1.25rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    line-height: 1.25;
    text-transform: uppercase;
}

.entry-form-event-dates {
    font-family: "Times New Roman", Times, Georgia, serif;
    font-size: 1rem;
    font-weight: 600;
    margin-top: 0.2rem;
}

.entry-form-code {
    border: 1.5px solid var(--ef-border);
    padding: 0.28rem 0.45rem;
    font-size: 0.78rem;
    font-weight: 700;
    text-align: center;
    background: #fff;
}

.entry-form-of-box {
    border: 1.5px solid var(--ef-border);
    padding: 0.4rem 0.5rem;
    text-align: center;
    background: #fff;
}

.entry-form-of-label {
    font-size: 0.72rem;
    font-weight: 700;
    line-height: 1.25;
    letter-spacing: 0.01em;
}

.entry-form-of-sport {
    margin-top: 0.2rem;
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--ef-accent);
    letter-spacing: 0.03em;
    line-height: 1.15;
}

.entry-form-fields {
    display: grid;
    grid-template-columns: minmax(0, 1.5fr) auto minmax(140px, 0.9fr);
    gap: 0.85rem;
    align-items: end;
    margin-bottom: 0.85rem;
}

.entry-form-field-school {
    display: flex;
    align-items: baseline;
    gap: 0.45rem;
    border-bottom: 1.5px solid var(--ef-border);
    padding-bottom: 0.2rem;
    min-height: 1.75rem;
}

.entry-form-field-label {
    font-weight: 700;
    font-size: 0.88rem;
    white-space: nowrap;
}

.entry-form-field-value {
    font-size: 0.98rem;
    font-weight: 600;
}

.entry-form-field-team {
    display: flex;
    align-items: center;
    gap: 0.45rem;
}

.entry-form-team-badge {
    border: 1.5px solid var(--ef-accent);
    color: var(--ef-accent);
    font-size: 0.72rem;
    font-weight: 800;
    padding: 0.2rem 0.45rem;
    letter-spacing: 0.04em;
}

.entry-form-team-name {
    font-weight: 800;
    font-size: 1rem;
}

.entry-form-field-category {
    display: flex;
    align-items: baseline;
    gap: 0.45rem;
    justify-content: flex-end;
}

.entry-form-category-value {
    color: var(--ef-accent);
    font-weight: 800;
    font-size: 1.1rem;
    border-bottom: 2px solid var(--ef-accent);
    min-width: 4.5rem;
    text-align: center;
    padding: 0 0.25rem 0.1rem;
}

.team-list-table {
    width: 100%;
    border-collapse: collapse;
    border: 1.5px solid var(--ef-border);
    font-size: 0.82rem;
}

.team-list-table th,
.team-list-table td {
    border: 1px solid var(--ef-border);
    padding: 0.4rem 0.45rem;
    vertical-align: middle;
}

.team-list-table thead th {
    background: var(--ef-label-bg);
    font-size: 0.92rem;
    font-weight: 600;
    letter-spacing: 0.02em;
    text-align: center;
    text-transform: uppercase;
}

.team-list-table thead th.col-header-strong {
    font-weight: 800;
    font-size: 1rem;
}

.team-list-table .col-num {
    width: 2.5rem;
    text-align: center;
    font-weight: 700;
}

.team-list-table .col-name {
    width: 22%;
    font-weight: 700;
    text-align: left;
}

.team-list-table .col-id {
    width: 12%;
    text-align: center;
}

.team-list-table .col-gender {
    width: 8%;
    text-align: center;
}

.team-list-table .col-course {
    width: 22%;
    text-align: left;
}

.team-list-table .col-event {
    width: 16%;
    text-align: left;
    font-weight: 600;
}

.team-list-table .col-category {
    width: 10%;
    text-align: center;
    font-weight: 700;
    color: #111;
}

.entry-form-footer {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    margin-top: 0.55rem;
    font-size: 0.75rem;
    color: #555;
}

@media (max-width: 991.98px) {
    .entry-form-header-top {
        grid-template-columns: 1fr;
        justify-items: center;
        text-align: center;
    }
    .entry-form-meta-boxes {
        width: 100%;
        justify-self: center;
    }
    .entry-form-header-spacer {
        display: none;
    }
    .entry-form-title-cluster {
        order: -1;
    }
    .entry-form-fields {
        grid-template-columns: 1fr;
        gap: 0.5rem;
    }
    .entry-form-field-category {
        justify-content: flex-start;
    }
    .team-list-body {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .team-list-table {
        min-width: 720px;
    }
}

@media (max-width: 575.98px) {
    .entry-form-office-heading,
    .entry-form-republic {
        font-size: 0.9rem;
    }
    .entry-form-event-title {
        font-size: 1rem;
    }
}

@media print {
    .sidebar, .season-bar, .alert, .page-header, .filter-bar, .no-print, .app-navbar, .footer, .app-nav-offcanvas {
        display: none !important;
    }
    .app-main {
        padding: 0 !important;
        margin: 0 !important;
        max-width: none !important;
    }
    .entry-form-sheet {
        border: none !important;
        border-radius: 0 !important;
        margin: 0 !important;
        padding: 0.18in 0.12in !important;
        box-shadow: none !important;
        overflow: visible !important;
    }
    .entry-form-sheet-break {
        page-break-after: always;
        break-after: page;
    }
    .entry-form-header-top {
        grid-template-columns: 1fr auto 1fr !important;
        align-items: center !important;
    }
    .entry-form-logo,
    .entry-form-sdo-logo {
        width: 0.8in;
        height: 0.8in;
    }
    .entry-form-fields {
        grid-template-columns: 1.5fr auto 0.9fr !important;
    }
    .team-list-table {
        min-width: 0 !important;
    }
    .team-list-table tr {
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .team-list-table thead th {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
