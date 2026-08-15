<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
ensureSportCategoryEnum();

if (isTournamentManager() && !canManageIntramurals()) {
    flash('error', 'Entry form gallery is not available for tournament manager accounts.');
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
applyUnitManagerTeamScope($teamId);

$sports = filterSportsForUser(filterSportsForCoach($db->query('SELECT * FROM intramural_sports ORDER BY name, category')->fetchAll()));
$teams = filterTeamsForCoach($db->query('SELECT id, name, color FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll());
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
    flash('error', 'You do not have permission to view this event gallery.');
    redirect(BASE_URL . '/intramurals/roster/gallery.php');
}

$sql = "SELECT r.*, a.first_name, a.last_name, a.student_id, a.birthdate, a.department, a.year_level, a.photo, a.gender,
               s.id AS sport_id, s.name AS sport_name, s.category AS sport_category, s.players_per_event,
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

/** Group by sport + team for one official entry form each. */
$forms = [];
foreach ($rows as $r) {
    $key = (int) $r['sport_id'] . '|' . (int) $r['team_id'];
    if (!isset($forms[$key])) {
        $forms[$key] = [
            'sport_id' => (int) $r['sport_id'],
            'sport_name' => $r['sport_name'],
            'sport_category' => $r['sport_category'],
            'players_per_event' => (int) ($r['players_per_event'] ?? 0),
            'team_id' => (int) $r['team_id'],
            'team_name' => $r['team_name'],
            'team_color' => $r['team_color'],
            'team_department' => $r['team_department'] ?? '',
            'athletes' => [],
        ];
    }
    $forms[$key]['athletes'][] = $r;
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

/**
 * Pad athlete list to the event/category roster size (players_per_event).
 *
 * @param list<array> $athletes
 * @return list<array|null>
 */
$padAthletes = static function (array $athletes, int $playersPerEvent): array {
    $count = count($athletes);
    $slots = $playersPerEvent > 0 ? $playersPerEvent : max($count, 1);
    $slots = max($slots, $count);
    while (count($athletes) < $slots) {
        $athletes[] = null;
    }
    return $athletes;
};

/**
 * @param array|null $athlete
 */
$courseYear = static function (?array $athlete): string {
    if (!$athlete) {
        return '';
    }
    $parts = array_filter([
        trim((string) ($athlete['department'] ?? '')),
        trim((string) ($athlete['year_level'] ?? '')),
    ]);
    return implode(' · ', $parts);
};

$pageTitle = 'Official Entry Form & Gallery';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
    <div>
        <h1><i class="bi bi-images"></i> Official Entry Form &amp; Gallery</h1>
        <p class="text-muted mb-0">Player gallery from official rosters · <?= count($forms) ?> team form<?= count($forms) === 1 ? '' : 's' ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/intramurals/roster/index.php" class="btn btn-outline-primary"><i class="bi bi-list-ul"></i> Athlete Roster</a>
        <a href="<?= BASE_URL ?>/intramurals/roster/team_list.php" class="btn btn-outline-primary"><i class="bi bi-people"></i> Team Athlete List</a>
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
                <?php foreach ($sports as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= $sportId === (string) $s['id'] ? 'selected' : '' ?>><?= sanitize(sportLabel($s)) ?></option>
                <?php endforeach; ?>
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
    <p class="text-muted small mb-0 mt-2">Tip: Select an event and team for a single printable entry form. Leave blank to print all matching team forms.</p>
</div>

<?php if (empty($forms)): ?>
<div class="alert alert-info no-print">No official roster entries found for the selected filters.</div>
<?php endif; ?>

<div class="entry-gallery-print-root">
<?php
$formIndex = 0;
$totalForms = count($forms);
foreach ($forms as $form):
    $formIndex++;
    $catKey = strtolower((string) $form['sport_category']);
    $catLabel = strtoupper($categoryOptions[$catKey] ?? $form['sport_category']);
    $sportName = strtoupper((string) $form['sport_name']);
    $athletes = $padAthletes($form['athletes'], (int) $form['players_per_event']);
    $pages = array_chunk($athletes, 12);
    $pageCount = count($pages);
?>
<?php foreach ($pages as $pageNo => $pageAthletes): ?>
<?php
    $rowsOfSix = array_chunk($pageAthletes, 6);
    $pageLabel = ($pageNo + 1) . ' of ' . $pageCount;
?>
<section class="entry-form-sheet<?= $formIndex < $totalForms || $pageNo + 1 < $pageCount ? ' entry-form-sheet-break' : '' ?>">
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
                <div class="entry-form-code">JHCSCDC - SDO Form 2</div>
                <div class="entry-form-of-box">
                    <div class="entry-form-of-label">OFFICIAL ENTRY FORM AND GALLERY OF</div>
                    <div class="entry-form-of-sport"><?= sanitize($sportName) ?></div>
                </div>
            </div>
        </div>
        <div class="entry-form-fields">
            <div class="entry-form-field-school">
                <span class="entry-form-field-label">SCHOOL:</span>
                <span class="entry-form-field-value"><?= sanitize(trim((string) ($form['team_department'] ?? '')) ?: $form['team_name']) ?></span>
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

    <div class="entry-form-gallery" style="--entry-watermark:url('<?= sanitize($collegeLogo) ?>')">
        <?php foreach ($rowsOfSix as $rowIndex => $rowAthletes): ?>
        <?php
            $colCount = count($rowAthletes);
            $seqBase = ($pageNo * 12) + ($rowIndex * 6);
        ?>
        <table class="entry-form-table">
            <colgroup>
                <col class="entry-form-label-col">
                <?php for ($c = 0; $c < $colCount; $c++): ?>
                <col>
                <?php endfor; ?>
            </colgroup>
            <thead>
                <tr class="entry-form-head-row">
                    <th class="entry-form-corner" scope="col"></th>
                    <?php foreach ($rowAthletes as $i => $_): ?>
                    <th scope="col">PARTICIPANT <?= $seqBase + $i + 1 ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <tr class="entry-form-photo-row">
                    <th scope="row"></th>
                    <?php foreach ($rowAthletes as $athlete): ?>
                    <td>
                        <div class="entry-form-photo-frame">
                            <?php if ($athlete && !empty($athlete['photo'])): ?>
                            <img src="<?= sanitize(UPLOAD_URL_ATHLETES . $athlete['photo']) ?>" alt="<?= sanitize(athleteFullNameReport($athlete)) ?>" class="entry-form-photo">
                            <?php else: ?>
                            <div class="entry-form-photo-placeholder">Attach Recent<br>Photo 1x1</div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <tr class="entry-form-info-row">
                    <th scope="row">Name</th>
                    <?php foreach ($rowAthletes as $athlete): ?>
                    <td><?= $athlete ? sanitize(athleteFullNameReport($athlete)) : '' ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr class="entry-form-info-row">
                    <th scope="row">Date of Birth</th>
                    <?php foreach ($rowAthletes as $athlete): ?>
                    <td><?= $athlete && !empty($athlete['birthdate']) ? sanitize(formatDate($athlete['birthdate'], 'm/d/Y')) : '' ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr class="entry-form-info-row">
                    <th scope="row">Student ID Number</th>
                    <?php foreach ($rowAthletes as $athlete): ?>
                    <td><?= $athlete ? sanitize((string) $athlete['student_id']) : '' ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr class="entry-form-info-row entry-form-info-row-last">
                    <th scope="row">Course &amp; Year</th>
                    <?php foreach ($rowAthletes as $athlete): ?>
                    <td><?= sanitize($courseYear($athlete)) ?></td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
        <?php endforeach; ?>
    </div>

    <footer class="entry-form-footer">
        <span><?= sanitize($seasonLabel) ?> · <?= sanitize($form['team_name']) ?> · <?= sanitize($sportName) ?></span>
        <span>Page <?= sanitize($pageLabel) ?><?= $totalForms > 1 ? ' · Form ' . $formIndex . ' of ' . $totalForms : '' ?></span>
    </footer>
</section>
<?php endforeach; ?>
<?php endforeach; ?>
</div>

<style>
.entry-gallery-print-root {
    --ef-border: #222;
    --ef-accent: #c62828;
    --ef-label-bg: #f3f3f3;
    --ef-photo-size: 1.2in;
    --ef-row-h: 34px;
    --ef-label-w: 8.25rem;
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

.entry-form-logo {
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

.entry-form-sdo-logo {
    width: 72px;
    height: 72px;
    object-fit: cover;
    border-radius: 50%;
    background: #fff;
    border: 1px solid #ddd;
    flex-shrink: 0;
}

.entry-form-meta-boxes {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    justify-self: end;
    width: min(100%, 240px);
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

.entry-form-republic {
    font-family: "Times New Roman", Times, Georgia, serif;
    font-size: 0.95rem;
    font-weight: 600;
    letter-spacing: 0.03em;
    line-height: 1.2;
    margin-bottom: 0.12rem;
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

.entry-form-gallery {
    position: relative;
}

.entry-form-gallery::before {
    content: "";
    position: absolute;
    inset: 10% 16%;
    background-image: var(--entry-watermark);
    background-repeat: no-repeat;
    background-position: center;
    background-size: contain;
    opacity: 0.06;
    pointer-events: none;
    z-index: 0;
}

.entry-form-table {
    position: relative;
    z-index: 1;
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
    border: 1.5px solid var(--ef-border);
    margin-bottom: 0.85rem;
    background: rgba(255, 255, 255, 0.92);
}

.entry-form-table .entry-form-label-col {
    width: var(--ef-label-w);
}

.entry-form-table th,
.entry-form-table td {
    border: 1px solid var(--ef-border);
    vertical-align: middle;
    text-align: center;
    overflow: hidden;
}

.entry-form-head-row th {
    height: 28px;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.03em;
    background: var(--ef-label-bg);
    padding: 0.2rem 0.15rem;
}

.entry-form-corner {
    background: var(--ef-label-bg) !important;
}

.entry-form-photo-row th,
.entry-form-info-row th {
    width: var(--ef-label-w);
    background: var(--ef-label-bg);
    font-size: 0.78rem;
    font-weight: 700;
    text-align: left;
    padding: 0.35rem 0.45rem;
    white-space: nowrap;
}

.entry-form-photo-row td {
    height: calc(var(--ef-photo-size) + 0.12in);
    padding: 0.06in;
    background: #fff;
}

.entry-form-photo-frame {
    width: var(--ef-photo-size);
    height: var(--ef-photo-size);
    min-width: var(--ef-photo-size);
    min-height: var(--ef-photo-size);
    border: 1px solid #999;
    box-sizing: border-box;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #fafafa;
    overflow: hidden;
    margin: 0 auto;
    aspect-ratio: 1 / 1;
    flex-shrink: 0;
}

.entry-form-photo {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.entry-form-photo-placeholder {
    font-size: 0.7rem;
    color: #666;
    text-align: center;
    line-height: 1.3;
    padding: 0.35rem;
}

.entry-form-info-row td {
    height: var(--ef-row-h);
    padding: 0.25rem 0.3rem;
    font-size: 0.78rem;
    font-weight: 600;
    line-height: 1.2;
    word-break: break-word;
    background: #fff;
}

.entry-form-footer {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    margin-top: 0.15rem;
    font-size: 0.75rem;
    color: #555;
}

@media (max-width: 991.98px) {
    .entry-gallery-print-root {
        --ef-row-h: 32px;
        --ef-label-w: 7.5rem;
    }
    .entry-form-header-top {
        grid-template-columns: 1fr;
        justify-items: center;
        text-align: center;
    }
    .entry-form-meta-boxes {
        width: 100%;
        justify-content: center;
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
    .entry-form-gallery {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .entry-form-table {
        min-width: calc(var(--ef-label-w) + (var(--ef-photo-size) * 6) + 1.5rem);
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
    .entry-form-of-sport {
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
    .entry-gallery-print-root {
        --ef-photo-size: 1.2in;
        --ef-row-h: 0.28in;
        --ef-label-w: 1.15in;
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
    .entry-form-gallery {
        overflow: visible !important;
    }
    .entry-form-table {
        min-width: 0 !important;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .entry-form-header-top {
        grid-template-columns: 1fr auto 1fr !important;
        align-items: center !important;
    }
    .entry-form-sdo-logo,
    .entry-form-logo {
        width: 0.8in;
        height: 0.8in;
    }
    .entry-form-fields {
        grid-template-columns: 1.5fr auto 0.9fr !important;
    }
    .entry-form-gallery::before {
        opacity: 0.07;
    }
    .entry-form-info-row td,
    .entry-form-info-row th,
    .entry-form-head-row th {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
