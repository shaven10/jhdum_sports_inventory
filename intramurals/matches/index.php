<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
requireMatchResultsAccess();

$db = getDB();
$seasonId = getCurrentSeasonId();
$season = getCurrentSeason();
$search = get('search');
$sportId = get('sport');
$status = get('status');
$unscheduled = get('unscheduled');
$page = max(1, (int) get('page', '1'));
$perPage = 20;

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
if ($sportId !== '') {
    $where[] = 'm.sport_id = ?';
    $params[] = (int) $sportId;
}
if ($status !== '') {
    $where[] = 'm.status = ?';
    $params[] = $status;
}
if ($unscheduled === '1') {
    $where[] = 'm.scheduled_at IS NULL';
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

$countStmt = $db->prepare("SELECT COUNT(*) FROM intramural_matches m
    LEFT JOIN intramural_teams ta ON m.team_a_id = ta.id
    LEFT JOIN intramural_teams tb ON m.team_b_id = tb.id
    WHERE $whereClause");
$countStmt->execute($params);
$pagination = paginate((int) $countStmt->fetchColumn(), $perPage, $page);

$sql = "SELECT m.*, s.name as sport_name, s.category as sport_category, s.tournament_format,
               ta.name as team_a_name, ta.color as team_a_color,
               tb.name as team_b_name, tb.color as team_b_color
        FROM intramural_matches m
        JOIN intramural_sports s ON m.sport_id = s.id
        LEFT JOIN intramural_teams ta ON m.team_a_id = ta.id
        LEFT JOIN intramural_teams tb ON m.team_b_id = tb.id
        WHERE $whereClause
        ORDER BY (m.scheduled_at IS NULL) DESC, m.round_number ASC, m.match_order ASC, m.scheduled_at ASC
        LIMIT {$pagination['offset']}, $perPage";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$matches = $stmt->fetchAll();

$sports = filterSportsForUser($db->query('SELECT id, name, category, tournament_format FROM intramural_sports ORDER BY name')->fetchAll());

$pendingCount = 0;
$generatedCount = 0;
$generatedUnplayedCount = 0;
$totalMatchCount = 0;
if ($seasonId) {
    $p = $db->prepare('SELECT COUNT(*) FROM intramural_matches WHERE season_id = ? AND scheduled_at IS NULL');
    $p->execute([$seasonId]);
    $pendingCount = (int) $p->fetchColumn();
    if (canDeleteAllMatches()) {
        $sportFilter = $sportId !== '' ? (int) $sportId : null;
        $generatedCount = countGeneratedMatches($seasonId, $sportFilter, true);
        $generatedUnplayedCount = countGeneratedMatches($seasonId, $sportFilter, false);
        $totalMatchCount = countSeasonMatches($seasonId, $sportFilter);
    }
}

$pageTitle = 'Match Scheduling';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';

$queryBase = BASE_URL . '/intramurals/matches/index.php?search=' . urlencode($search) . '&sport=' . urlencode($sportId) . '&status=' . urlencode($status) . '&unscheduled=' . urlencode($unscheduled);

$filterSportLabel = 'All events';
if ($sportId !== '') {
    foreach ($sports as $s) {
        if ((string) $s['id'] === $sportId) {
            $filterSportLabel = sportLabel($s);
            break;
        }
    }
}
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-calendar3"></i> Match Scheduling</h1>
        <p class="text-muted mb-0">Generate fixtures by tournament style, auto-schedule, then edit any date/time as needed. Click a team name to view that match’s official players.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (canGenerateMatches()): ?>
        <a href="<?= BASE_URL ?>/intramurals/matches/generate.php<?= $sportId !== '' ? '?sport=' . (int) $sportId : '' ?>" class="btn btn-primary"><i class="bi bi-magic"></i> Generate Matches</a>
        <?php if ($sportId !== ''): ?>
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
</div>

<?php if ($pendingCount > 0 && canManageMatches()): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><i class="bi bi-clock"></i> <?= $pendingCount ?> generated match<?= $pendingCount === 1 ? '' : 'es' ?> still need a date &amp; time.</span>
    <a href="?unscheduled=1" class="btn btn-sm btn-warning">Show unscheduled</a>
</div>
<?php endif; ?>

<div class="filter-bar">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label">Search</label><input type="text" name="search" class="form-control" value="<?= sanitize($search) ?>"></div>
        <div class="col-md-3">
            <label class="form-label">Sport</label>
            <select name="sport" class="form-select">
                <option value="">All</option>
                <?php foreach ($sports as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $sportId === (string) $s['id'] ? 'selected' : '' ?>><?= sanitize(sportLabel($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
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
        <div class="col-md-2"><button class="btn btn-primary w-100">Filter</button></div>
    </form>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date/Time</th>
                        <th>Sport</th>
                        <th>Category</th>
                        <th>Round</th>
                        <th>Match</th>
                        <th>Score</th>
                        <th>Venue</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($matches as $m): ?>
                    <tr class="<?= empty($m['scheduled_at']) ? 'table-warning' : '' ?>">
                        <td>
                            <?php if (!empty($m['scheduled_at'])): ?>
                            <?= formatDateTime($m['scheduled_at']) ?>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark">Unscheduled</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= sanitize($m['sport_name']) ?>
                            <br><small class="text-muted"><?= sanitize(tournamentFormatLabel($m['tournament_format'] ?? null)) ?></small>
                        </td>
                        <td><span class="badge bg-secondary"><?= ucfirst($m['sport_category']) ?></span></td>
                        <td><?= sanitize($m['round_label'] ?: ('R' . (int) ($m['round_number'] ?? 1))) ?></td>
                        <td>
                            <?= matchTeamRosterTrigger($m, 'a') ?>
                            vs
                            <?= matchTeamRosterTrigger($m, 'b') ?>
                        </td>
                        <td>
                            <?php if ($m['score_a'] !== null && $m['score_b'] !== null): ?>
                            <strong><?= (int) $m['score_a'] ?> - <?= (int) $m['score_b'] ?></strong>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td><?= sanitize($m['venue'] ?: '-') ?></td>
                        <td><?= statusBadge($m['status']) ?></td>
                        <td class="text-nowrap">
                            <a href="<?= BASE_URL ?>/intramurals/matches/view.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                            <?php if (!empty($m['team_a_id']) || !empty($m['team_b_id'])): ?>
                            <a href="<?= BASE_URL ?>/intramurals/matches/view.php?id=<?= $m['id'] ?>#match-rosters" class="btn btn-sm btn-outline-info" title="Verify official roster">Roster</a>
                            <?php endif; ?>
                            <?php if (canManageMatches()): ?>
                            <a href="<?= BASE_URL ?>/intramurals/matches/schedule.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-<?= empty($m['scheduled_at']) ? 'warning' : 'outline-secondary' ?>">
                                <?= empty($m['scheduled_at']) ? 'Set Date/Time' : 'Reschedule' ?>
                            </a>
                            <?php if (canManageIntramurals()): ?>
                            <a href="<?= BASE_URL ?>/intramurals/matches/edit.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <?php endif; ?>
                            <?php if (canDeleteAllMatches()): ?>
                            <form method="POST" action="<?= BASE_URL ?>/intramurals/matches/delete_generated.php" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="single">
                                <input type="hidden" name="match_id" value="<?= (int) $m['id'] ?>">
                                <input type="hidden" name="return" value="<?= sanitize($queryBase . '&page=' . $page) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Delete this match permanently?">Delete</button>
                            </form>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($matches)): ?>
                    <tr><td colspan="9" class="text-muted p-3">No matches found. <?php if (canManageMatches()): ?><a href="<?= BASE_URL ?>/intramurals/matches/generate.php">Generate fixtures</a><?php endif; ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-3"><?= paginationLinks($pagination, $queryBase) ?></div>

<?php if (canDeleteAllMatches() && $totalMatchCount > 0): ?>
<div class="modal fade" id="deleteMatchesModal" tabindex="-1" aria-labelledby="deleteMatchesModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= BASE_URL ?>/intramurals/matches/delete_generated.php" id="deleteMatchesForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="bulk">
                <input type="hidden" name="sport_id" value="<?= sanitize($sportId) ?>">
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

<?php require __DIR__ . '/_roster_dialog.php'; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
