<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$showInventory = canViewInventoryDashboard();
$showCompetition = canViewCompetitionDashboard();
$showCoachPanel = isCoach() && !canManageIntramurals();

$db = getDB();
$inventoryStats = [];
$recentRequests = [];
$lowStockItems = [];
$popularEquipment = [];
$competitionStats = [];
$recentResults = [];
$overallStandings = [];
$coachAssignments = [];
$teamNames = [];
$sportNames = [];

if ($showInventory) {
    checkOverdueRequests();
    $inventoryStats = getDashboardStats();

    $recentRequests = $db->query("
        SELECT br.*, e.name AS equipment_name, u.first_name, u.last_name
        FROM borrowing_requests br
        JOIN equipment e ON br.equipment_id = e.id
        JOIN users u ON br.user_id = u.id
        ORDER BY br.created_at DESC LIMIT 5
    ")->fetchAll();

    $lowStockItems = $db->query("
        SELECT e.*, c.name AS category_name
        FROM equipment e
        JOIN equipment_categories c ON e.category_id = c.id
        WHERE e.is_active = 1 AND e.quantity_available <= e.low_stock_threshold
        ORDER BY e.quantity_available ASC LIMIT 5
    ")->fetchAll();

    $popularEquipment = $db->query("
        SELECT e.name, COUNT(br.id) AS borrow_count
        FROM borrowing_requests br
        JOIN equipment e ON br.equipment_id = e.id
        GROUP BY e.id, e.name
        ORDER BY borrow_count DESC LIMIT 5
    ")->fetchAll();
}

try {
    if ($showCompetition) {
        $competitionStats = getIntramuralsStats();
        $recentResults = getDashboardRecentMatchResults(8);
        $overallData = computeOverallStandings();
        $overallStandings = array_slice($overallData['standings'], 0, 8);
    }
    if ($showCoachPanel) {
        $coachAssignments = getCoachAssignments();
        if ($coachAssignments) {
            foreach ($db->query('SELECT id, name FROM intramural_teams')->fetchAll() as $t) {
                $teamNames[(int) $t['id']] = $t['name'];
            }
            foreach ($db->query('SELECT id, name, category FROM intramural_sports')->fetchAll() as $s) {
                $sportNames[(int) $s['id']] = sportLabel($s);
            }
        }
    }
} catch (Throwable $e) {
    // Intramurals tables may not be installed yet.
}

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

$dashboardSubtitle = 'Welcome back, ' . ($_SESSION['user_name'] ?? '');
if (isSecretariat()) {
    $dashboardSubtitle = 'Match results, overall standings, and competition management';
} elseif (isTournamentManager() && !canManageIntramurals()) {
    $dashboardSubtitle = 'Results and standings for your assigned events';
} elseif (hasRole('unit_manager')) {
    $dashboardSubtitle = 'Team management, match results, and overall standings';
} elseif (canManageIntramurals()) {
    $dashboardSubtitle = 'Inventory overview, match results, and overall standings';
} elseif ($showCoachPanel) {
    $dashboardSubtitle = hasCoachAssignments()
        ? 'Your assigned teams and events'
        : 'No event assignments yet — ask your unit manager to assign you';
}

$dashboardActions = '';
if (canBorrowEquipment()) {
    $dashboardActions .= '<a href="' . BASE_URL . '/equipment/index.php" class="btn btn-light"><i class="bi bi-plus-circle"></i> Request Equipment</a>';
}
if ($showCompetition) {
    $dashboardActions .= '<a href="' . BASE_URL . '/intramurals/matches/index.php" class="btn btn-light"><i class="bi bi-list-check"></i> Match Results</a>';
    $dashboardActions .= '<a href="' . BASE_URL . '/intramurals/standings/overall.php" class="btn btn-outline-light"><i class="bi bi-award"></i> Overall Standing</a>';
}
if ($showCoachPanel && canViewIntramurals()) {
    $dashboardActions .= '<a href="' . BASE_URL . '/intramurals/index.php" class="btn btn-outline-light"><i class="bi bi-trophy"></i> Intramurals</a>';
}
if (hasRole('unit_manager') && getUserTeamId()) {
    $teamId = (int) getUserTeamId();
    $dashboardActions .= '<a href="' . BASE_URL . '/intramurals/teams/view.php?id=' . $teamId . '" class="btn btn-outline-light"><i class="bi bi-shield"></i> My Team</a>';
}
if (isSecretariat()) {
    $dashboardActions .= '<a href="' . BASE_URL . '/intramurals/matches/generate.php" class="btn btn-outline-light"><i class="bi bi-magic"></i> Generate Matches</a>';
}
if (isAdmin()) {
    $dashboardActions .= '<a href="' . BASE_URL . '/admin/index.php" class="btn btn-outline-light"><i class="bi bi-shield-lock"></i> Admin Panel</a>';
}

echo renderDashboardHero('Dashboard', $dashboardSubtitle, [
    'icon' => 'bi-speedometer2',
    'actions' => $dashboardActions,
]);
?>

<?php if (isAdmin()): ?>
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-shield-lock"></i> Admin Quick Links</span>
                <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-sm btn-outline-primary">Open Admin Panel</a>
            </div>
            <div class="card-body">
                <div class="row g-2 admin-quick-links">
                    <div class="col-6 col-md-3 col-xl-2"><a class="btn btn-outline-secondary w-100" href="<?= BASE_URL ?>/users/index.php"><i class="bi bi-people"></i> Users</a></div>
                    <div class="col-6 col-md-3 col-xl-2"><a class="btn btn-outline-secondary w-100" href="<?= BASE_URL ?>/settings/index.php"><i class="bi bi-gear"></i> Settings</a></div>
                    <div class="col-6 col-md-3 col-xl-2"><a class="btn btn-outline-secondary w-100" href="<?= BASE_URL ?>/settings/theme.php"><i class="bi bi-palette"></i> Theme</a></div>
                    <div class="col-6 col-md-3 col-xl-2"><a class="btn btn-outline-secondary w-100" href="<?= BASE_URL ?>/audit/index.php"><i class="bi bi-journal-check"></i> Audit</a></div>
                    <div class="col-6 col-md-3 col-xl-2"><a class="btn btn-outline-secondary w-100" href="<?= BASE_URL ?>/intramurals/seasons/index.php"><i class="bi bi-calendar3"></i> Seasons</a></div>
                    <div class="col-6 col-md-3 col-xl-2"><a class="btn btn-outline-secondary w-100" href="<?= BASE_URL ?>/intramurals/roster/lock.php"><i class="bi bi-lock"></i> Roster Lock</a></div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (shouldShowRosterLockStatus()): ?>
<?= renderRosterLockAlerts() ?>
<?php endif; ?>

<?php if ($showCompetition): ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary bi bi-calendar2-event-fill" aria-hidden="true"></div>
                <div>
                    <div class="stat-value"><?= $competitionStats['scheduled_games'] ?? 0 ?></div>
                    <div class="stat-label">Scheduled</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning bi bi-play-circle-fill" aria-hidden="true"></div>
                <div>
                    <div class="stat-value"><?= $competitionStats['ongoing_games'] ?? 0 ?></div>
                    <div class="stat-label">Ongoing</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-success bg-opacity-10 text-success bi bi-check-circle-fill" aria-hidden="true"></div>
                <div>
                    <div class="stat-value"><?= $competitionStats['completed_games'] ?? 0 ?></div>
                    <div class="stat-label">Completed</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-danger bg-opacity-10 text-danger bi bi-shield-fill" aria-hidden="true"></div>
                <div>
                    <div class="stat-value"><?= $competitionStats['total_teams'] ?? 0 ?></div>
                    <div class="stat-label">Teams</div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($showInventory): ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary bi bi-box-seam-fill" aria-hidden="true"></div>
                <div>
                    <div class="stat-value"><?= $inventoryStats['total_equipment'] ?></div>
                    <div class="stat-label">Equipment Types</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-success bg-opacity-10 text-success bi bi-check2-circle" aria-hidden="true"></div>
                <div>
                    <div class="stat-value"><?= $inventoryStats['available_items'] ?></div>
                    <div class="stat-label">Available</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-info bg-opacity-10 text-info bi bi-box-arrow-right" aria-hidden="true"></div>
                <div>
                    <div class="stat-value"><?= $inventoryStats['borrowed_items'] ?></div>
                    <div class="stat-label">Borrowed</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning bi bi-hourglass-split" aria-hidden="true"></div>
                <div>
                    <div class="stat-value"><?= $inventoryStats['pending_requests'] ?></div>
                    <div class="stat-label">Pending</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-danger bg-opacity-10 text-danger bi bi-exclamation-triangle-fill" aria-hidden="true"></div>
                <div>
                    <div class="stat-value"><?= $inventoryStats['overdue_borrowings'] ?></div>
                    <div class="stat-label">Overdue</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-secondary bg-opacity-10 text-secondary bi bi-wrench-adjustable" aria-hidden="true"></div>
                <div>
                    <div class="stat-value"><?= $inventoryStats['maintenance_items'] ?></div>
                    <div class="stat-label">Maintenance</div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($coachAssignments)): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-person-badge"></i> My Coach Assignments</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Team</th><th>Event</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($coachAssignments as $a): ?>
                    <tr>
                        <td><?= sanitize($teamNames[(int) $a['team_id']] ?? 'Team #' . $a['team_id']) ?></td>
                        <td><?= sanitize($sportNames[(int) $a['sport_id']] ?? 'Event #' . $a['sport_id']) ?></td>
                        <td class="text-end">
                            <a href="<?= BASE_URL ?>/intramurals/teams/view.php?id=<?= (int) $a['team_id'] ?>&sport=<?= (int) $a['sport_id'] ?>" class="btn btn-sm btn-outline-primary">View Roster</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php elseif ($showCoachPanel): ?>
<div class="alert alert-warning mb-4">You have no event coach assignments for this season. Ask your unit manager to assign you under <strong>Teams → Event Coaches</strong>.</div>
<?php endif; ?>

<div class="row g-4">
    <?php if ($showCompetition): ?>
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list-check"></i> Recent Match Results</span>
                <a href="<?= BASE_URL ?>/intramurals/matches/index.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Sport</th>
                                <th>Match</th>
                                <th>Score</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentResults)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No completed matches yet</td></tr>
                            <?php else: ?>
                            <?php foreach ($recentResults as $m): ?>
                            <tr>
                                <td><?= sanitize($m['sport_name']) ?></td>
                                <td>
                                    <span style="color:<?= sanitize($m['team_a_color'] ?? '#333') ?>"><?= sanitize($m['team_a_name'] ?? 'TBD') ?></span>
                                    vs
                                    <span style="color:<?= sanitize($m['team_b_color'] ?? '#333') ?>"><?= sanitize($m['team_b_name'] ?? 'TBD') ?></span>
                                </td>
                                <td>
                                    <?php if ($m['status'] === 'forfeit'): ?>
                                    <span class="text-muted">Forfeit</span>
                                    <?php else: ?>
                                    <?= (int) ($m['score_a'] ?? 0) ?> – <?= (int) ($m['score_b'] ?? 0) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= statusBadge($m['status']) ?></td>
                                <td><a href="<?= BASE_URL ?>/intramurals/matches/view.php?id=<?= (int) $m['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-award"></i> Overall Standings</span>
                <a href="<?= BASE_URL ?>/intramurals/standings/overall.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead class="table-light"><tr><th>#</th><th>Team</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php if (empty($overallStandings)): ?>
                        <tr><td colspan="3" class="text-muted text-center py-4">No standings yet</td></tr>
                        <?php else: ?>
                        <?php foreach ($overallStandings as $row): ?>
                        <tr>
                            <td><?= (int) $row['rank'] ?></td>
                            <td>
                                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= sanitize($row['color']) ?>"></span>
                                <?= sanitize($row['team_name']) ?>
                            </td>
                            <td><strong><?= (int) $row['total'] ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($showInventory): ?>
<div class="row g-4 mt-0">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history"></i> Recent Borrowing Requests</span>
                <a href="<?= BASE_URL ?>/requests/index.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Request #</th>
                                <th>Borrower</th>
                                <th>Equipment</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentRequests)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No requests yet</td></tr>
                            <?php else: ?>
                            <?php foreach ($recentRequests as $req): ?>
                            <tr>
                                <td><a href="<?= BASE_URL ?>/requests/view.php?id=<?= $req['id'] ?>"><?= sanitize($req['request_number']) ?></a></td>
                                <td><?= sanitize($req['first_name'] . ' ' . $req['last_name']) ?></td>
                                <td><?= sanitize($req['equipment_name']) ?></td>
                                <td><?= statusBadge($req['status']) ?></td>
                                <td><?= formatDate($req['created_at']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <?php if (!empty($lowStockItems) && canManageInventory()): ?>
        <div class="card mb-4">
            <div class="card-header text-warning"><i class="bi bi-exclamation-triangle"></i> Low Stock Alert</div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php foreach ($lowStockItems as $item): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= sanitize($item['name']) ?></span>
                        <span class="badge bg-warning"><?= $item['quantity_available'] ?> left</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><i class="bi bi-star"></i> Most Borrowed Equipment</div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php if (empty($popularEquipment)): ?>
                    <li class="list-group-item text-muted">No data yet</li>
                    <?php else: ?>
                    <?php foreach ($popularEquipment as $eq): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= sanitize($eq['name']) ?></span>
                        <span class="badge bg-primary"><?= $eq['borrow_count'] ?> borrows</span>
                    </li>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
