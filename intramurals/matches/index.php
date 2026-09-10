<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
requireMatchResultsAccess();

$db = getDB();
$seasonId = getCurrentSeasonId();
$season = getCurrentSeason();
if ($seasonId) {
    ensureVenueGameNumbersCurrent($seasonId);
    restoreNeededDecidingRubbersForSeason($seasonId);
}
$search = get('search');
$status = get('status');
$unscheduled = get('unscheduled');
$dateFromRaw = get('date_from');
$dateToRaw = get('date_to');
$normalizeFilterDate = static function (string $value): string {
    $value = trim($value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        return '';
    }

    return $value;
};
$dateFrom = $normalizeFilterDate($dateFromRaw);
$dateTo = $normalizeFilterDate($dateToRaw);
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
$datesFilterActive = $dateFrom !== '' || $dateTo !== '';
$defaultSort = defaultMatchScheduleSort();
$sort = get('sort', $defaultSort);
if ($sort === '') {
    $sort = $defaultSort;
}
if (!in_array($sort, ['schedule', 'game_number'], true)) {
    $sort = $defaultSort;
}
$page = max(1, (int) get('page', '1'));
$perPage = 20;

$sports = filterSportsForUser($db->query('SELECT id, name, category, event_group, tournament_format FROM intramural_sports ORDER BY ' . intramuralSportsOrderBy())->fetchAll());
$validSportIds = [];
foreach ($sports as $s) {
    $validSportIds[(int) $s['id']] = true;
}

// Multi-select sports filter (sports[]). Legacy single ?sport= still supported.
$selectedSportIds = [];
if (isset($_GET['sports']) && is_array($_GET['sports'])) {
    foreach ($_GET['sports'] as $rawId) {
        $id = (int) $rawId;
        if ($id > 0 && isset($validSportIds[$id])) {
            $selectedSportIds[$id] = $id;
        }
    }
} elseif (get('sport') !== '') {
    $id = (int) get('sport');
    if ($id > 0 && isset($validSportIds[$id])) {
        $selectedSportIds[$id] = $id;
    }
}
$selectedSportIds = array_values($selectedSportIds);
$sportId = count($selectedSportIds) === 1 ? (string) $selectedSportIds[0] : '';
$sportsFilterActive = $selectedSportIds !== [];

$where = ['1=1'];
$params = [];
if ($seasonId) {
    $where[] = 'm.season_id = ?';
    $params[] = $seasonId;
}
if ($search) {
    $where[] = '(ta.name LIKE ? OR tb.name LIKE ? OR m.venue LIKE ? OR m.referee_name LIKE ? OR m.round_label LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%", "%$search%"]);
}
if ($sportsFilterActive) {
    $placeholders = implode(',', array_fill(0, count($selectedSportIds), '?'));
    $where[] = "m.sport_id IN ($placeholders)";
    $params = array_merge($params, $selectedSportIds);
}
if ($status !== '') {
    $where[] = 'm.status = ?';
    $params[] = $status;
}
if ($unscheduled === '1' && !$datesFilterActive) {
    $where[] = 'm.scheduled_at IS NULL';
}
if ($datesFilterActive) {
    if ($dateFrom !== '' && $dateTo !== '') {
        $where[] = 'm.scheduled_at IS NOT NULL AND DATE(m.scheduled_at) BETWEEN ? AND ?';
        $params[] = $dateFrom;
        $params[] = $dateTo;
    } elseif ($dateFrom !== '') {
        $where[] = 'm.scheduled_at IS NOT NULL AND DATE(m.scheduled_at) >= ?';
        $params[] = $dateFrom;
    } else {
        $where[] = 'm.scheduled_at IS NOT NULL AND DATE(m.scheduled_at) <= ?';
        $params[] = $dateTo;
    }
}
if (isTournamentManager() && !canManageIntramurals()) {
    $tmSportIds = getTmSportIds();
    if (empty($tmSportIds)) {
        $where[] = '0=1';
    } else {
        $placeholders = implode(',', array_fill(0, count($tmSportIds), '?'));
        $where[] = "m.sport_id IN ($placeholders)";
        $params = array_merge($params, $tmSportIds);
    }
}
$whereClause = implode(' AND ', $where);
appendUnitManagerMatchFilter($whereClause, $params);

if ($seasonId && canManageMatches()) {
    $sportIdsToAdvance = $sportsFilterActive
        ? $selectedSportIds
        : array_values(array_filter(array_map(static fn($s) => (int) $s['id'], $sports)));
    foreach ($sportIdsToAdvance as $advanceSportId) {
        if ($advanceSportId > 0 && !isResultsLocked(null, $advanceSportId)) {
            advanceBracketFromResults($advanceSportId, $seasonId);
        }
    }
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM intramural_matches m
    LEFT JOIN intramural_teams ta ON m.team_a_id = ta.id
    LEFT JOIN intramural_teams tb ON m.team_b_id = tb.id
    WHERE $whereClause");
$countStmt->execute($params);
$pagination = paginate((int) $countStmt->fetchColumn(), $perPage, $page);

$orderBy = matchScheduleSqlOrderClause($sort);
$selectEffectiveVenue = ', COALESCE(NULLIF(TRIM(m.venue), \'\'), NULLIF(TRIM(s.venue), \'\')) AS effective_venue';

$sql = "SELECT m.*, s.name as sport_name, s.category as sport_category, s.tournament_format,
               ta.name as team_a_name, ta.color as team_a_color,
               tb.name as team_b_name, tb.color as team_b_color{$selectEffectiveVenue}
        FROM intramural_matches m
        JOIN intramural_sports s ON m.sport_id = s.id
        LEFT JOIN intramural_teams ta ON m.team_a_id = ta.id
        LEFT JOIN intramural_teams tb ON m.team_b_id = tb.id
        WHERE $whereClause
        ORDER BY $orderBy
        LIMIT {$pagination['offset']}, $perPage";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$matches = $stmt->fetchAll();
$matchesBySport = $sort === 'game_number'
    ? groupMatchesByVenueDaySchedule($matches)
    : groupMatchesBySportSchedule($matches);

$printSql = "SELECT m.*, s.name as sport_name, s.category as sport_category, s.tournament_format,
               ta.name as team_a_name, ta.color as team_a_color,
               tb.name as team_b_name, tb.color as team_b_color{$selectEffectiveVenue}
        FROM intramural_matches m
        JOIN intramural_sports s ON m.sport_id = s.id
        LEFT JOIN intramural_teams ta ON m.team_a_id = ta.id
        LEFT JOIN intramural_teams tb ON m.team_b_id = tb.id
        WHERE $whereClause
        ORDER BY $orderBy";
$printStmt = $db->prepare($printSql);
$printStmt->execute($params);
$printMatchesBySport = $sort === 'game_number'
    ? groupMatchesByVenueDaySchedule($printStmt->fetchAll())
    : groupMatchesBySportSchedule($printStmt->fetchAll());
$printMatchTotal = 0;
foreach ($printMatchesBySport as $pg) {
    $printMatchTotal += count($pg['matches']);
}

$pendingCount = 0;
$generatedCount = 0;
$generatedUnplayedCount = 0;
$totalMatchCount = 0;
if ($seasonId) {
    $pendingSql = 'SELECT COUNT(*) FROM intramural_matches m WHERE m.season_id = ? AND m.scheduled_at IS NULL';
    $pendingParams = [$seasonId];
    appendUnitManagerMatchFilter($pendingSql, $pendingParams);
    $p = $db->prepare($pendingSql);
    $p->execute($pendingParams);
    $pendingCount = (int) $p->fetchColumn();
    if (canDeleteAllMatches()) {
        $sportFilter = $sportsFilterActive ? $selectedSportIds : null;
        $generatedCount = countGeneratedMatches($seasonId, $sportFilter, true);
        $generatedUnplayedCount = countGeneratedMatches($seasonId, $sportFilter, false);
        $totalMatchCount = countSeasonMatches($seasonId, $sportFilter);
    }
}

$pageTitle = 'Match Scheduling';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
echo '<div class="no-print">' . renderResultsLockAlerts() . '</div>';

$sportsQuery = '';
foreach ($selectedSportIds as $sid) {
    $sportsQuery .= '&sports[]=' . (int) $sid;
}
$queryBase = BASE_URL . '/intramurals/matches/index.php?search=' . urlencode($search) . $sportsQuery
    . '&status=' . urlencode($status)
    . '&unscheduled=' . urlencode($unscheduled)
    . '&date_from=' . urlencode($dateFrom)
    . '&date_to=' . urlencode($dateTo)
    . '&sort=' . urlencode($sort);

$selectedSportLabels = [];
$selectedSportIdSet = array_flip($selectedSportIds);
foreach ($sports as $s) {
    if (isset($selectedSportIdSet[(int) $s['id']])) {
        $selectedSportLabels[] = sportLabel($s);
    }
}
if ($selectedSportLabels === []) {
    $filterSportLabel = 'All events';
} elseif (count($selectedSportLabels) === 1) {
    $filterSportLabel = $selectedSportLabels[0];
} elseif (count($selectedSportLabels) <= 3) {
    $filterSportLabel = implode(', ', $selectedSportLabels);
} else {
    $filterSportLabel = count($selectedSportLabels) . ' selected events';
}

$filtersActive = $search !== '' || $sportsFilterActive || $status !== '' || $unscheduled === '1' || $datesFilterActive;
$filterSummary = [];
if ($search !== '') {
    $filterSummary[] = 'Search: “' . $search . '”';
}
if ($sportsFilterActive) {
    $filterSummary[] = 'Sports: ' . $filterSportLabel;
}
if ($status !== '') {
    $filterSummary[] = 'Status: ' . ucfirst($status);
}
if ($unscheduled === '1') {
    $filterSummary[] = 'Needs date/time';
}
if ($datesFilterActive) {
    if ($dateFrom !== '' && $dateTo !== '') {
        $filterSummary[] = 'Dates: ' . formatDate($dateFrom) . ' – ' . formatDate($dateTo);
    } elseif ($dateFrom !== '') {
        $filterSummary[] = 'From: ' . formatDate($dateFrom);
    } else {
        $filterSummary[] = 'Until: ' . formatDate($dateTo);
    }
}
if ($sort === 'game_number') {
    $filterSummary[] = 'Sorted by game #';
}

$reportMetaParts = [];
if ($season) {
    $reportMetaParts[] = seasonLabel($season);
}
if ($filtersActive || $sort === 'game_number') {
    $reportMetaParts[] = implode(' · ', $filterSummary);
} else {
    $reportMetaParts[] = 'All matches';
}
$reportMeta = implode(' · ', $reportMetaParts);
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
    <div>
        <h1><i class="bi bi-calendar3"></i> Match Scheduling</h1>
        <p class="text-muted mb-0">Generate fixtures by tournament style, auto-schedule, then edit any date/time as needed. Matches are grouped <?= $sort === 'game_number' ? 'by venue and date, sorted by game #' : 'by sport and sorted by date/time' ?>. Click a team name to view that match’s official players.<?php if (hasRole('unit_manager') && !canManageIntramurals() && getUserTeamId()): ?> Showing only matches involving your assigned team.<?php endif; ?></p>
    </div>
    <?php if ($printMatchTotal > 0): ?>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-outline-secondary" onclick="printReport()">
            <i class="bi bi-printer"></i> Print / PDF
        </button>
    </div>
    <?php endif; ?>
</div>

<?= renderReportHeader('Match Schedule', [
    'subtitle' => $sort === 'game_number' ? 'Intramural matches grouped by venue and date' : 'Intramural match fixtures grouped by sport',
    'meta' => $reportMeta,
]) ?>

<?php if ($printMatchTotal > 0): ?>
<div class="d-none d-print-block match-schedule-print">
    <?php
    $scheduleGroups = $printMatchesBySport;
    $showActions = false;
    $plainTeamLabels = true;
    $scheduleGroupMode = $sort === 'game_number' ? 'venue_day' : 'sport';
    require __DIR__ . '/_schedule_groups.php';
    ?>
</div>
<?php endif; ?>

<?php if ($pendingCount > 0 && canManageMatches()): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
    <span><i class="bi bi-clock"></i> <?= $pendingCount ?> generated match<?= $pendingCount === 1 ? '' : 'es' ?> still need a date &amp; time.</span>
    <a href="?unscheduled=1" class="btn btn-sm btn-warning">Show unscheduled</a>
</div>
<?php endif; ?>

<div class="filter-bar no-print" id="matchFilterBar">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <?php if (canGenerateMatches()): ?>
            <a href="<?= BASE_URL ?>/intramurals/matches/generate.php<?= $sportId !== '' ? '?sport=' . (int) $sportId : '' ?>" class="btn btn-primary"><i class="bi bi-magic"></i> Generate Matches</a>
            <?php if ($sportId !== '' && !isResultsLocked(null, (int) $sportId)): ?>
            <form method="POST" action="<?= BASE_URL ?>/intramurals/matches/advance.php" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="sport_id" value="<?= (int) $sportId ?>">
                <input type="hidden" name="return_to" value="<?= sanitize($queryBase) ?>">
                <button type="submit" class="btn btn-outline-success" data-confirm="Update TBD teams for this event from completed match results?">
                    <i class="bi bi-diagram-3"></i> Update Bracket
                </button>
            </form>
            <?php endif; ?>
            <?php if (canManageIntramurals()): ?>
            <a href="<?= BASE_URL ?>/intramurals/matches/add.php" class="btn btn-outline-primary"><i class="bi bi-plus-lg"></i> Single Match</a>
            <?php endif; ?>
            <?php if (canDeleteAllMatches() && $totalMatchCount > 0): ?>
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteMatchesModal">
                <i class="bi bi-trash"></i> Delete Matches
            </button>
            <?php endif; ?>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/intramurals/matches/calendar.php" class="btn btn-outline-primary"><i class="bi bi-calendar-week"></i> Calendar</a>
            <a href="<?= BASE_URL ?>/intramurals/index.php" class="btn btn-outline-secondary">Back</a>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <?php if ($filtersActive || $sort === 'game_number'): ?>
            <span class="text-muted small"><?= sanitize(implode(' · ', $filterSummary)) ?></span>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-secondary" id="matchFiltersToggle" data-bs-toggle="collapse" data-bs-target="#matchFilters" aria-expanded="false" aria-controls="matchFilters">
                <i class="bi bi-funnel"></i> Filters
                <i class="bi bi-chevron-down ms-1 filter-toggle-icon"></i>
            </button>
        </div>
    </div>
    <div id="matchFilters" class="collapse">
        <form method="GET" class="row g-2 align-items-end mt-2" id="matchFilterForm">
            <div class="col-md-3"><label class="form-label">Search</label><input type="text" name="search" class="form-control" value="<?= sanitize($search) ?>"></div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <?php foreach (['scheduled', 'ongoing', 'completed', 'forfeit', 'cancelled'] as $st): ?>
                    <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Schedule</label>
                <select name="unscheduled" class="form-select">
                    <option value="">All</option>
                    <option value="1" <?= $unscheduled === '1' ? 'selected' : '' ?> >Needs date/time</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Sort by</label>
                <select name="sort" class="form-select">
                    <option value="schedule" <?= $sort === 'schedule' ? 'selected' : '' ?>>Date/time (by sport)</option>
                    <option value="game_number" <?= $sort === 'game_number' ? 'selected' : '' ?>>Game # (by venue &amp; date)</option>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100">Apply</button></div>

            <div class="col-md-6 col-lg-4">
                <label class="form-label">Dates <span class="text-muted fw-normal">(inclusive)</span></label>
                <div class="input-group">
                    <input type="date" name="date_from" id="matchDateFrom" class="form-control" value="<?= sanitize($dateFrom) ?>" aria-label="From date">
                    <span class="input-group-text">to</span>
                    <input type="date" name="date_to" id="matchDateTo" class="form-control" value="<?= sanitize($dateTo) ?>" aria-label="To date">
                </div>
                <div class="form-text">Show matches scheduled on these calendar days (both dates included). Leave blank for all dates.</div>
            </div>
            <div class="col-md-6 col-lg-auto align-self-end">
                <button type="button" class="btn btn-outline-secondary" id="matchDatesClear" title="Clear date range">Clear dates</button>
            </div>

            <div class="col-12">
                <div class="sport-display-picker" id="matchSportsPicker">
                    <div class="sport-display-picker__header">
                        <div>
                            <label class="form-label mb-0" for="matchSportsSearch">Sports to display</label>
                            <div class="form-text mt-0" id="matchSportsSummary">
                                <?php if (!$sportsFilterActive): ?>
                                Showing all sports
                                <?php elseif (count($selectedSportIds) === 1): ?>
                                1 sport selected
                                <?php else: ?>
                                <?= count($selectedSportIds) ?> sports selected
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary" id="matchSportsSelectAll">Select all</button>
                            <button type="button" class="btn btn-outline-secondary" id="matchSportsClear">Show all</button>
                        </div>
                    </div>

                    <div class="sport-display-picker__chips<?= $sportsFilterActive ? '' : ' d-none' ?>" id="matchSportsChips" aria-live="polite">
                        <?php foreach ($sports as $s): ?>
                            <?php if (!isset($selectedSportIdSet[(int) $s['id']])) {
                                continue;
                            } ?>
                            <button type="button"
                                    class="sport-display-chip"
                                    data-sport-id="<?= (int) $s['id'] ?>"
                                    title="Remove <?= sanitize(sportLabel($s)) ?>">
                                <span><?= sanitize(sportLabel($s)) ?></span>
                                <i class="bi bi-x-lg" aria-hidden="true"></i>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="sport-display-picker__search">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search"
                               id="matchSportsSearch"
                               class="form-control form-control-sm"
                               placeholder="Search sports…"
                               autocomplete="off"
                               aria-controls="matchSportsList">
                    </div>

                    <div class="sport-display-picker__list" id="matchSportsList" role="group" aria-label="Sports to display">
                        <?php if (empty($sports)): ?>
                        <div class="text-muted small p-2">No sports available.</div>
                        <?php else: ?>
                            <?php foreach (groupSportsByEventGroup($sports) as $groupKey => $groupSports): ?>
                            <?php if ($groupSports === []) continue; ?>
                            <div class="sport-display-picker__group small text-uppercase fw-semibold text-muted px-2 pt-2"><?= sanitize(sportEventGroupLabel($groupKey)) ?></div>
                            <?php foreach ($groupSports as $s): ?>
                                <?php
                                $sid = (int) $s['id'];
                                $label = sportLabel($s);
                                $checked = isset($selectedSportIdSet[$sid]);
                                $dataSportLabel = function_exists('mb_strtolower')
                                    ? mb_strtolower($label)
                                    : strtolower($label);
                                ?>
                                <label class="sport-display-option<?= $checked ? ' is-checked' : '' ?>" data-sport-label="<?= sanitize($dataSportLabel) ?>">
                                    <input class="form-check-input match-sport-check"
                                           type="checkbox"
                                           name="sports[]"
                                           value="<?= $sid ?>"
                                           <?= $checked ? 'checked' : '' ?>>
                                    <span class="sport-display-option__body">
                                        <span class="sport-display-option__name"><?= sanitize($label) ?></span>
                                        <?php if (!empty($s['category'])): ?>
                                        <span class="badge bg-secondary"><?= sanitize(ucfirst((string) $s['category'])) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                            <?php endforeach; ?>
                            <div class="sport-display-picker__empty text-muted small p-2 d-none" id="matchSportsEmpty">No sports match your search.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card no-print">
    <div class="card-body p-0">
        <?php if (empty($matches)): ?>
        <div class="text-muted p-3">
            No matches found.
            <?php if (canManageMatches()): ?>
            <a href="<?= BASE_URL ?>/intramurals/matches/generate.php">Generate fixtures</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <?php
        $scheduleGroups = $matchesBySport;
        $showActions = true;
        $plainTeamLabels = false;
        $scheduleGroupMode = $sort === 'game_number' ? 'venue_day' : 'sport';
        require __DIR__ . '/_schedule_groups.php';
        ?>
        <?php endif; ?>
    </div>
</div>

<div class="mt-3 no-print"><?= paginationLinks($pagination, $queryBase) ?></div>

<p class="text-muted small mt-2 no-print">For PDF: click <strong>Print / PDF</strong> and choose “Save as PDF” in your browser print dialog. The report includes all matches matching your current filters (not just this page).</p>

<?= renderReportFooter($reportMeta) ?>

<?php if (canDeleteAllMatches() && $totalMatchCount > 0): ?>
<div class="modal fade no-print" id="deleteMatchesModal" tabindex="-1" aria-labelledby="deleteMatchesModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= BASE_URL ?>/intramurals/matches/delete_generated.php" id="deleteMatchesForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="bulk">
                <?php if ($sportsFilterActive): ?>
                    <?php foreach ($selectedSportIds as $sid): ?>
                    <input type="hidden" name="sport_ids[]" value="<?= (int) $sid ?>">
                    <?php endforeach; ?>
                <?php else: ?>
                <input type="hidden" name="sport_id" value="">
                <?php endif; ?>
                <input type="hidden" name="return" value="<?= sanitize($queryBase . '&page=' . $page) ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteMatchesModalLabel"><i class="bi bi-trash"></i> Delete Matches</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">
                        Scope:
                        <strong><?= sanitize($filterSportLabel) ?></strong>
                        · Season <?= $season ? sanitize(seasonLabel($season)) : '' ?>
                    </p>
                    <ul class="small text-muted mb-3">
                        <li><strong><?= (int) $totalMatchCount ?></strong> total matches (generated + manual)</li>
                        <li><strong><?= (int) $generatedUnplayedCount ?></strong> unplayed generated</li>
                        <li><strong><?= (int) $generatedCount ?></strong> total generated</li>
                    </ul>

                    <div class="mb-3">
                        <label class="form-label">What to delete</label>
                        <div class="form-check">
                            <input class="form-check-input delete-scope-radio" type="radio" name="delete_scope" id="deleteScopeGenerated" value="generated" checked onchange="toggleDeleteScope()">
                            <label class="form-check-label" for="deleteScopeGenerated">
                                <strong>Generated matches only</strong> — auto-generated fixtures; manual matches kept
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input delete-scope-radio" type="radio" name="delete_scope" id="deleteScopeAll" value="all" onchange="toggleDeleteScope()">
                            <label class="form-check-label" for="deleteScopeAll">
                                <strong>All matches</strong> — generated and manually added (includes completed results)
                            </label>
                        </div>
                    </div>

                    <div id="generatedDeleteOptions" class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="include_completed" value="1" id="includeCompletedGenerated">
                            <label class="form-check-label" for="includeCompletedGenerated">
                                Also delete completed, ongoing, and forfeit generated matches
                            </label>
                        </div>
                    </div>

                    <div id="allDeleteOptions" class="mb-3" style="display:none">
                        <div class="alert alert-danger py-2">
                            <i class="bi bi-exclamation-octagon-fill"></i>
                            This permanently removes every match in the selected scope, including recorded scores.
                        </div>
                        <label class="form-label" for="confirmDeletePhrase">Type <code>DELETE ALL</code> to confirm</label>
                        <input type="text" name="confirm_phrase" id="confirmDeletePhrase" class="form-control" autocomplete="off" placeholder="DELETE ALL">
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="confirm_delete" value="1" id="confirmDeleteMatches" required>
                        <label class="form-check-label" for="confirmDeleteMatches">
                            I understand deleted matches cannot be recovered without re-entry
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="deleteMatchesSubmit">Delete Matches</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function toggleDeleteScope() {
    const all = document.getElementById('deleteScopeAll')?.checked;
    const genOpts = document.getElementById('generatedDeleteOptions');
    const allOpts = document.getElementById('allDeleteOptions');
    const phrase = document.getElementById('confirmDeletePhrase');
    const submit = document.getElementById('deleteMatchesSubmit');
    if (genOpts) genOpts.style.display = all ? 'none' : '';
    if (allOpts) allOpts.style.display = all ? '' : 'none';
    if (phrase) phrase.required = !!all;
    if (submit) submit.textContent = all ? 'Delete All Matches' : 'Delete Generated Matches';
}
toggleDeleteScope();
</script>
<?php endif; ?>

<script>
(function () {
    const picker = document.getElementById('matchSportsPicker');
    if (!picker) return;

    const checks = Array.prototype.slice.call(picker.querySelectorAll('.match-sport-check'));
    const summary = document.getElementById('matchSportsSummary');
    const chipsWrap = document.getElementById('matchSportsChips');
    const searchInput = document.getElementById('matchSportsSearch');
    const emptyMsg = document.getElementById('matchSportsEmpty');
    const selectAll = document.getElementById('matchSportsSelectAll');
    const clearBtn = document.getElementById('matchSportsClear');

    function selectedChecks() {
        return checks.filter(function (cb) { return cb.checked; });
    }

    function updateSummary() {
        const selected = selectedChecks();
        const count = selected.length;
        if (!summary) return;
        if (count === 0) {
            summary.textContent = 'Showing all sports';
        } else if (count === 1) {
            summary.textContent = '1 sport selected';
        } else {
            summary.textContent = count + ' sports selected';
        }
    }

    function rebuildChips() {
        if (!chipsWrap) return;
        chipsWrap.innerHTML = '';
        const selected = selectedChecks();
        if (selected.length === 0) {
            chipsWrap.classList.add('d-none');
            return;
        }
        chipsWrap.classList.remove('d-none');
        selected.forEach(function (cb) {
            const label = cb.closest('.sport-display-option');
            const nameEl = label ? label.querySelector('.sport-display-option__name') : null;
            const name = nameEl ? nameEl.textContent.trim() : ('Sport #' + cb.value);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'sport-display-chip';
            btn.dataset.sportId = cb.value;
            btn.title = 'Remove ' + name;
            btn.innerHTML = '<span></span><i class="bi bi-x-lg" aria-hidden="true"></i>';
            btn.querySelector('span').textContent = name;
            chipsWrap.appendChild(btn);
        });
    }

    function syncOptionStyles() {
        checks.forEach(function (cb) {
            const option = cb.closest('.sport-display-option');
            if (!option) return;
            option.classList.toggle('is-checked', cb.checked);
        });
    }

    function refresh() {
        syncOptionStyles();
        updateSummary();
        rebuildChips();
    }

    checks.forEach(function (cb) {
        cb.addEventListener('change', refresh);
    });

    if (chipsWrap) {
        chipsWrap.addEventListener('click', function (e) {
            const chip = e.target.closest('.sport-display-chip');
            if (!chip) return;
            const id = chip.dataset.sportId;
            const match = checks.find(function (cb) { return String(cb.value) === String(id); });
            if (match) {
                match.checked = false;
                refresh();
            }
        });
    }

    if (selectAll) {
        selectAll.addEventListener('click', function () {
            checks.forEach(function (cb) {
                const option = cb.closest('.sport-display-option');
                if (option && option.classList.contains('d-none')) return;
                cb.checked = true;
            });
            refresh();
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            checks.forEach(function (cb) { cb.checked = false; });
            if (searchInput) {
                searchInput.value = '';
                filterList('');
            }
            refresh();
        });
    }

    function filterList(query) {
        const q = (query || '').trim().toLowerCase();
        let visible = 0;
        picker.querySelectorAll('.sport-display-option').forEach(function (option) {
            const label = option.getAttribute('data-sport-label') || '';
            const show = !q || label.indexOf(q) !== -1;
            option.classList.toggle('d-none', !show);
            if (show) visible++;
        });
        if (emptyMsg) {
            emptyMsg.classList.toggle('d-none', visible > 0 || checks.length === 0);
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            filterList(searchInput.value);
        });
    }

    refresh();
})();

(function () {
    const clearDates = document.getElementById('matchDatesClear');
    const from = document.getElementById('matchDateFrom');
    const to = document.getElementById('matchDateTo');
    if (!clearDates || !from || !to) return;
    clearDates.addEventListener('click', function () {
        from.value = '';
        to.value = '';
    });
})();

(function () {
    const bar = document.getElementById('matchFilterBar');
    const panel = document.getElementById('matchFilters');
    const toggle = document.getElementById('matchFiltersToggle');
    if (!bar || !panel || !toggle || typeof bootstrap === 'undefined' || !bootstrap.Collapse) return;

    const canHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    if (!canHover) return;

    const collapse = bootstrap.Collapse.getOrCreateInstance(panel, { toggle: false });
    let hideTimer = null;

    function stillInside() {
        return toggle.matches(':hover')
            || panel.matches(':hover')
            || panel.contains(document.activeElement)
            || toggle.contains(document.activeElement);
    }

    function cancelHide() {
        if (hideTimer) {
            clearTimeout(hideTimer);
            hideTimer = null;
        }
    }

    function scheduleHide() {
        cancelHide();
        hideTimer = setTimeout(function () {
            hideTimer = null;
            if (stillInside()) return;
            collapse.hide();
        }, 280);
    }

    [toggle, panel].forEach(function (el) {
        el.addEventListener('mouseenter', cancelHide);
        el.addEventListener('mouseleave', scheduleHide);
        el.addEventListener('focusin', cancelHide);
        el.addEventListener('focusout', function () {
            setTimeout(function () {
                if (!stillInside()) scheduleHide();
            }, 0);
        });
    });
})();
</script>

<?php require __DIR__ . '/_roster_dialog.php'; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
