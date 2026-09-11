<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$db = getDB();
$seasonId = getCurrentSeasonId();
$search = get('search');
$sportId = get('sport');
$status = get('status');
$unscheduled = get('unscheduled');
$page = max(1, (int) get('page', '1'));
$perPage = 20;

$tmSportIds = isTournamentManager() ? getTournamentManagerSportIds() : [];
$isTmOnly = isTournamentManager() && !canManageMatches();

$where = ['1=1'];
$params = [];
if ($seasonId) {
    $where[] = 'm.season_id = ?';
    $params[] = $seasonId;
}
if ($isTmOnly) {
    if ($tmSportIds) {
        $placeholders = implode(',', array_fill(0, count($tmSportIds), '?'));
        $where[] = "m.sport_id IN ($placeholders)";
        $params = array_merge($params, $tmSportIds);
    } else {
        $where[] = '1=0';
    }
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

$sports = $db->query('SELECT id, name, category, tournament_format FROM intramural_sports WHERE is_active = 1 ORDER BY name')->fetchAll();
if ($isTmOnly) {
    $sports = array_values(array_filter($sports, static fn($s) => in_array((int) $s['id'], $tmSportIds, true)));
}

$pendingCount = 0;
if ($seasonId) {
    $p = $db->prepare('SELECT COUNT(*) FROM intramural_matches WHERE season_id = ? AND scheduled_at IS NULL');
    $p->execute([$seasonId]);
    $pendingCount = (int) $p->fetchColumn();
}

$pageTitle = 'Match Scheduling';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';

$queryBase = BASE_URL . '/intramurals/matches/index.php?search=' . urlencode($search) . '&sport=' . urlencode($sportId) . '&status=' . urlencode($status) . '&unscheduled=' . urlencode($unscheduled);
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-calendar3"></i> <?= $isTmOnly ? 'Assigned Event Matches' : 'Match Scheduling' ?></h1>
        <p class="text-muted mb-0">
            <?= $isTmOnly
                ? 'Update scores and standings for events assigned to you'
                : 'Generate fixtures by tournament style, auto-schedule, then edit any date/time as needed' ?>
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (canManageMatches()): ?>
        <a href="<?= BASE_URL ?>/intramurals/matches/generate.php<?= $sportId !== '' ? '?sport=' . (int) $sportId : '' ?>" class="btn btn-primary"><i class="bi bi-magic"></i> Generate Matches</a>
        <?php if (canManageIntramurals()): ?>
        <a href="<?= BASE_URL ?>/intramurals/matches/add.php" class="btn btn-outline-primary"><i class="bi bi-plus-lg"></i> Single Match</a>
        <?php endif; ?>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/intramurals/matches/calendar.php" class="btn btn-outline-primary"><i class="bi bi-calendar-week"></i> Calendar</a>
        <a href="<?= BASE_URL ?>/intramurals/index.php" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

<?php if ($pendingCount > 0 && canManageMatches() && !$isTmOnly): ?>
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
                            <span style="color:<?= sanitize($m['team_a_color'] ?: '#666') ?>"><?= sanitize($m['team_a_name'] ?: 'TBD') ?></span>
                            vs
                            <span style="color:<?= sanitize($m['team_b_color'] ?: '#666') ?>"><?= sanitize($m['team_b_name'] ?: 'TBD') ?></span>
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
                            <?php if (canManageIntramurals()): ?>
                            <a href="<?= BASE_URL ?>/intramurals/matches/edit.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <?php elseif (canRecordScores((int) $m['sport_id'])): ?>
                            <a href="<?= BASE_URL ?>/intramurals/matches/edit.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-primary">Score</a>
                            <?php endif; ?>
                            <?php if (canManageMatches()): ?>
                            <a href="<?= BASE_URL ?>/intramurals/matches/schedule.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-<?= empty($m['scheduled_at']) ? 'warning' : 'outline-secondary' ?>">
                                <?= empty($m['scheduled_at']) ? 'Set Date/Time' : 'Reschedule' ?>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($matches)): ?>
                    <tr><td colspan="9" class="text-muted p-3">
                        <?php if ($isTmOnly && empty($tmSportIds)): ?>
                        No events are assigned to you for this season.
                        <?php else: ?>
                        No matches found. <?php if (canManageMatches()): ?><a href="<?= BASE_URL ?>/intramurals/matches/generate.php">Generate fixtures</a><?php endif; ?>
                        <?php endif; ?>
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-3"><?= paginationLinks($pagination, $queryBase) ?></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
