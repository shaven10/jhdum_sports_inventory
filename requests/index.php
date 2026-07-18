<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$db = getDB();
$status = get('status');
$page = max(1, (int) get('page', '1'));
$perPage = 15;

$where = ['1=1'];
$params = [];

if ($_SESSION['user_role'] === 'student') {
    $where[] = 'br.user_id = ?';
    $params[] = $_SESSION['user_id'];
}

if ($status) {
    $where[] = 'br.status = ?';
    $params[] = $status;
}

$whereClause = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM borrowing_requests br WHERE $whereClause");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pagination = paginate($total, $perPage, $page);

$sql = "SELECT br.*, e.name as equipment_name, u.first_name, u.last_name
        FROM borrowing_requests br
        JOIN equipment e ON br.equipment_id = e.id
        JOIN users u ON br.user_id = u.id
        WHERE $whereClause ORDER BY br.created_at DESC
        LIMIT {$pagination['offset']}, $perPage";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pageTitle = 'Borrowing Requests';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-clipboard-check"></i> Borrowing Requests</h1>
    </div>
    <?php if ($_SESSION['user_role'] === 'student'): ?>
    <a href="<?= BASE_URL ?>/equipment/index.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Request</a>
    <?php endif; ?>
</div>

<div class="filter-bar">
    <form method="GET" class="d-flex gap-2 flex-wrap">
        <select name="status" class="form-select" style="max-width: 200px;">
            <option value="">All Status</option>
            <?php foreach (['pending', 'approved', 'rejected', 'checked_out', 'returned', 'overdue', 'cancelled'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="<?= BASE_URL ?>/requests/index.php" class="btn btn-outline-secondary">Reset</a>
    </form>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Request #</th>
                        <?php if ($_SESSION['user_role'] !== 'student'): ?><th>Borrower</th><?php endif; ?>
                        <th>Equipment</th>
                        <th>Qty</th>
                        <th>Borrow Date</th>
                        <th>Return Date</th>
                        <th>Purpose</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No requests found</td></tr>
                    <?php else: ?>
                    <?php foreach ($requests as $req): ?>
                    <tr>
                        <td><strong><?= sanitize($req['request_number']) ?></strong></td>
                        <?php if ($_SESSION['user_role'] !== 'student'): ?>
                        <td><?= sanitize($req['first_name'] . ' ' . $req['last_name']) ?></td>
                        <?php endif; ?>
                        <td><?= sanitize($req['equipment_name']) ?></td>
                        <td><?= $req['quantity'] ?></td>
                        <td><?= formatDate($req['borrow_date']) ?></td>
                        <td><?= formatDate($req['return_date']) ?></td>
                        <td><?= purposeLabel($req['purpose']) ?></td>
                        <td><?= statusBadge($req['status']) ?></td>
                        <td><a href="<?= BASE_URL ?>/requests/view.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?= paginationLinks($pagination, BASE_URL . '/requests/index.php?status=' . urlencode($status)) ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
