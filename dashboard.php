<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

if (isIntramuralsOnlyRole()) {
    redirect(getHomeUrl());
}

checkOverdueRequests();
$stats = getDashboardStats();
$db = getDB();

$recentRequests = $db->query("
    SELECT br.*, e.name as equipment_name, u.first_name, u.last_name
    FROM borrowing_requests br
    JOIN equipment e ON br.equipment_id = e.id
    JOIN users u ON br.user_id = u.id
    ORDER BY br.created_at DESC LIMIT 5
")->fetchAll();

$lowStockItems = $db->query("
    SELECT e.*, c.name as category_name
    FROM equipment e
    JOIN equipment_categories c ON e.category_id = c.id
    WHERE e.is_active = 1 AND e.quantity_available <= e.low_stock_threshold
    ORDER BY e.quantity_available ASC LIMIT 5
")->fetchAll();

$popularEquipment = $db->query("
    SELECT e.name, COUNT(br.id) as borrow_count
    FROM borrowing_requests br
    JOIN equipment e ON br.equipment_id = e.id
    GROUP BY e.id, e.name
    ORDER BY borrow_count DESC LIMIT 5
")->fetchAll();

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-speedometer2"></i> Dashboard</h1>
        <p class="text-muted mb-0">Welcome back, <?= sanitize($_SESSION['user_name']) ?>!</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (canBorrowEquipment()): ?>
        <a href="<?= BASE_URL ?>/equipment/index.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> Request Equipment
        </a>
        <?php endif; ?>
        <?php if (isTeamScopedRole()): ?>
        <a href="<?= BASE_URL ?>/intramurals/index.php" class="btn btn-outline-primary">
            <i class="bi bi-trophy"></i> Intramurals
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-box-seam"></i></div>
                <div>
                    <div class="stat-value"><?= $stats['total_equipment'] ?></div>
                    <div class="stat-label">Equipment Types</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-check-circle"></i></div>
                <div>
                    <div class="stat-value"><?= $stats['available_items'] ?></div>
                    <div class="stat-label">Available</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-info bg-opacity-10 text-info"><i class="bi bi-arrow-right-circle"></i></div>
                <div>
                    <div class="stat-value"><?= $stats['borrowed_items'] ?></div>
                    <div class="stat-label">Borrowed</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="stat-value"><?= $stats['pending_requests'] ?></div>
                    <div class="stat-label">Pending</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-danger bg-opacity-10 text-danger"><i class="bi bi-exclamation-triangle"></i></div>
                <div>
                    <div class="stat-value"><?= $stats['overdue_borrowings'] ?></div>
                    <div class="stat-label">Overdue</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-secondary bg-opacity-10 text-secondary"><i class="bi bi-tools"></i></div>
                <div>
                    <div class="stat-value"><?= $stats['maintenance_items'] ?></div>
                    <div class="stat-label">Maintenance</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
