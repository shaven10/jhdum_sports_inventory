<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();

$db = getDB();
$search = get('search');
$page = max(1, (int) get('page', '1'));
$perPage = 12;

$where = ['1=1'];
$params = [];
if ($search) {
    $where[] = '(t.name LIKE ? OR t.short_name LIKE ? OR t.department LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (isCoach() && !canManageIntramurals()) {
    $coachTeamIds = getCoachTeamIds();
    if (empty($coachTeamIds)) {
        $where[] = '0=1';
    } else {
        $placeholders = implode(',', array_fill(0, count($coachTeamIds), '?'));
        $where[] = "t.id IN ($placeholders)";
        $params = array_merge($params, $coachTeamIds);
    }
}
$whereClause = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM intramural_teams t WHERE $whereClause");
$countStmt->execute($params);
$pagination = paginate((int) $countStmt->fetchColumn(), $perPage, $page);

$seasonId = getCurrentSeasonId();
$sportCountSql = $seasonId
    ? '(SELECT COUNT(DISTINCT r.sport_id) FROM intramural_registrations r WHERE r.team_id = t.id AND r.season_id = ' . (int) $seasonId . ')'
    : '(SELECT COUNT(DISTINCT r.sport_id) FROM intramural_registrations r WHERE r.team_id = t.id)';
$stmt = $db->prepare("SELECT t.*,
    (SELECT COUNT(*) FROM intramural_athletes a WHERE a.team_id = t.id AND a.is_active = 1) as member_count,
    $sportCountSql as sport_count
    FROM intramural_teams t WHERE $whereClause ORDER BY t.is_active DESC, t.name ASC
    LIMIT {$pagination['offset']}, $perPage");
$stmt->execute($params);
$teams = $stmt->fetchAll();

$pageTitle = 'Team Management';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-shield-shaded"></i> Teams / Houses</h1>
        <p class="text-muted mb-0"><?= isCoach() && !canManageIntramurals() ? 'Teams and events you are assigned to coach' : 'Register teams, colors, logos, and rosters' ?></p>
    </div>
    <div class="d-flex gap-2">
        <?php if (canManageIntramurals()): ?>
        <a href="<?= BASE_URL ?>/intramurals/teams/add.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add Team</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/intramurals/index.php" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

<div class="filter-bar">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Search</label>
            <input type="text" name="search" class="form-control" value="<?= sanitize($search) ?>" placeholder="Team name, department...">
        </div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Filter</button></div>
    </form>
</div>

<div class="row g-3">
    <?php foreach ($teams as $t): ?>
    <div class="col-md-6 col-xl-4">
        <div class="card h-100 <?= $t['is_active'] ? '' : 'opacity-75' ?>">
            <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                    <?php if ($t['logo']): ?>
                    <img src="<?= UPLOAD_URL_TEAMS . sanitize($t['logo']) ?>" alt="" class="rounded" style="width:56px;height:56px;object-fit:cover;border:3px solid <?= sanitize($t['color']) ?>">
                    <?php else: ?>
                    <div class="rounded d-flex align-items-center justify-content-center text-white fw-bold" style="width:56px;height:56px;background:<?= sanitize($t['color']) ?>">
                        <?= sanitize(strtoupper(substr($t['short_name'] ?: $t['name'], 0, 2))) ?>
                    </div>
                    <?php endif; ?>
                    <div class="flex-grow-1">
                        <h5 class="mb-1"><?= sanitize($t['name']) ?></h5>
                        <div class="text-muted small"><?= sanitize($t['department'] ?: 'No department') ?></div>
                        <div class="mt-2">
                            <span class="badge bg-primary"><?= (int) $t['member_count'] ?> athletes</span>
                            <span class="badge bg-secondary"><?= (int) $t['sport_count'] ?> sports</span>
                            <?php if (!$t['is_active']): ?><span class="badge bg-warning text-dark">Inactive</span><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php if ($t['coach_name']): ?>
                <div class="mt-3 small"><i class="bi bi-person"></i> Coach: <?= sanitize($t['coach_name']) ?></div>
                <?php endif; ?>
                <div class="mt-3 d-flex gap-2">
                    <a href="<?= BASE_URL ?>/intramurals/teams/view.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary">View Roster</a>
                    <?php if (canEditOwnTeam((int) $t['id']) || canManageIntramurals()): ?>
                    <a href="<?= BASE_URL ?>/intramurals/teams/coaches.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary">Coaches</a>
                    <a href="<?= BASE_URL ?>/intramurals/teams/edit.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                    <?php elseif (hasRole('coach') && in_array((int) $t['id'], getCoachTeamIds(), true)): ?>
                    <a href="<?= BASE_URL ?>/intramurals/teams/view.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary">My Events</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($teams)): ?>
    <div class="col-12"><div class="alert alert-info">No teams found.</div></div>
    <?php endif; ?>
</div>

<div class="mt-3"><?= paginationLinks($pagination, BASE_URL . '/intramurals/teams/index.php?search=' . urlencode($search)) ?></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
