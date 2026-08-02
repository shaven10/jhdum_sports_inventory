<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$db = getDB();
$seasonId = getCurrentSeasonId();
$search = get('search');
$sportId = get('sport');
$status = get('status');
$page = max(1, (int) get('page', '1'));
$perPage = 20;

$where = ['1=1'];
$params = [];
if ($seasonId) {
    $where[] = 'm.season_id = ?';
    $params[] = $seasonId;
}
if ($search) {
    $where[] = '(ta.name LIKE ? OR tb.name LIKE ? OR m.venue LIKE ? OR m.referee_name LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}
if ($sportId !== '') {
    $where[] = 'm.sport_id = ?';
    $params[] = (int) $sportId;
}
if ($status !== '') {
    $where[] = 'm.status = ?';
    $params[] = $status;
}
$whereClause = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM intramural_matches m JOIN intramural_teams ta ON m.team_a_id = ta.id JOIN intramural_teams tb ON m.team_b_id = tb.id WHERE $whereClause");
$countStmt->execute($params);
$pagination = paginate((int) $countStmt->fetchColumn(), $perPage, $page);

$sql = "SELECT m.*, s.name as sport_name, s.category as sport_category,
               ta.name as team_a_name, ta.color as team_a_color,
               tb.name as team_b_name, tb.color as team_b_color
        FROM intramural_matches m
        JOIN intramural_sports s ON m.sport_id = s.id
        JOIN intramural_teams ta ON m.team_a_id = ta.id
        JOIN intramural_teams tb ON m.team_b_id = tb.id
        WHERE $whereClause
        ORDER BY m.scheduled_at DESC
        LIMIT {$pagination['offset']}, $perPage";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$matches = $stmt->fetchAll();

$sports = $db->query('SELECT id, name, category FROM intramural_sports WHERE is_active = 1 ORDER BY name')->fetchAll();

$pageTitle = 'Match Scheduling';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-calendar3"></i> Match Scheduling</h1>
        <p class="text-muted mb-0">Create schedules, assign venues and referees</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (canManageMatches()): ?>
        <a href="<?= BASE_URL ?>/intramurals/matches/add.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Schedule Match</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/intramurals/matches/calendar.php" class="btn btn-outline-primary"><i class="bi bi-calendar-week"></i> Calendar</a>
        <a href="<?= BASE_URL ?>/intramurals/index.php" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

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
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">All</option>
                <?php foreach (['scheduled', 'ongoing', 'completed', 'forfeit', 'cancelled'] as $st): ?>
                <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
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
                    <tr><th>Date/Time</th><th>Sport</th><th>Match</th><th>Score</th><th>Venue</th><th>Referee</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($matches as $m): ?>
                    <tr>
                        <td><?= formatDateTime($m['scheduled_at']) ?></td>
                        <td><?= sanitize($m['sport_name']) ?> <small class="text-muted">(<?= ucfirst($m['sport_category']) ?>)</small></td>
                        <td>
                            <span style="color:<?= sanitize($m['team_a_color']) ?>"><?= sanitize($m['team_a_name']) ?></span>
                            vs
                            <span style="color:<?= sanitize($m['team_b_color']) ?>"><?= sanitize($m['team_b_name']) ?></span>
                        </td>
                        <td>
                            <?php if ($m['score_a'] !== null && $m['score_b'] !== null): ?>
                            <strong><?= (int) $m['score_a'] ?> - <?= (int) $m['score_b'] ?></strong>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td><?= sanitize($m['venue'] ?: '-') ?></td>
                        <td><?= sanitize($m['referee_name'] ?: '-') ?></td>
                        <td><?= statusBadge($m['status']) ?></td>
                        <td class="text-nowrap">
                            <a href="<?= BASE_URL ?>/intramurals/matches/view.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                            <?php if (canManageMatches()): ?>
                            <a href="<?= BASE_URL ?>/intramurals/matches/edit.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($matches)): ?>
                    <tr><td colspan="8" class="text-muted p-3">No matches found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-3"><?= paginationLinks($pagination, BASE_URL . '/intramurals/matches/index.php?search=' . urlencode($search) . '&sport=' . urlencode($sportId) . '&status=' . urlencode($status)) ?></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
