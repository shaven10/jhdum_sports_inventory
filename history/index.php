<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$db = getDB();
$userId = $_SESSION['user_id'];
$status = get('status');

$where = ['br.user_id = ?'];
$params = [$userId];
if ($status) {
    $where[] = 'br.status = ?';
    $params[] = $status;
}

$whereClause = implode(' AND ', $where);
$stmt = $db->prepare("
    SELECT br.*, e.name as equipment_name
    FROM borrowing_requests br
    JOIN equipment e ON br.equipment_id = e.id
    WHERE $whereClause
    ORDER BY br.created_at DESC
");
$stmt->execute($params);
$history = $stmt->fetchAll();

$pageTitle = 'Borrowing History';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header"><h1><i class="bi bi-clock-history"></i> Borrowing History</h1></div>

<div class="filter-bar mb-4">
    <div class="btn-group">
        <a href="?" class="btn btn-<?= !$status ? 'primary' : 'outline-primary' ?>">All</a>
        <?php foreach (['pending', 'approved', 'checked_out', 'returned', 'overdue', 'rejected'] as $s): ?>
        <a href="?status=<?= $s ?>" class="btn btn-<?= $status === $s ? 'primary' : 'outline-primary' ?>"><?= ucfirst(str_replace('_', ' ', $s)) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light"><tr><th>Request #</th><th>Equipment</th><th>Qty</th><th>Borrow Date</th><th>Return Date</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($history)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No borrowing history</td></tr>
                <?php else: ?>
                <?php foreach ($history as $h): ?>
                <tr>
                    <td><?= sanitize($h['request_number']) ?></td>
                    <td><?= sanitize($h['equipment_name']) ?></td>
                    <td><?= $h['quantity'] ?></td>
                    <td><?= formatDate($h['borrow_date']) ?></td>
                    <td><?= formatDate($h['return_date']) ?></td>
                    <td><?= statusBadge($h['status']) ?></td>
                    <td><a href="<?= BASE_URL ?>/requests/view.php?id=<?= $h['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
