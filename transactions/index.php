<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'coordinator', 'staff']);

$db = getDB();
$status = get('status', 'approved');
$page = max(1, (int) get('page', '1'));

$where = "br.status IN ('approved', 'checked_out', 'overdue', 'returned')";
if ($status !== 'all') {
    $where = "br.status = " . $db->quote($status);
}

$requests = $db->query("
    SELECT br.*, e.name as equipment_name, u.first_name, u.last_name
    FROM borrowing_requests br
    JOIN equipment e ON br.equipment_id = e.id
    JOIN users u ON br.user_id = u.id
    WHERE $where
    ORDER BY br.updated_at DESC
    LIMIT 50
")->fetchAll();

$pageTitle = 'Transactions';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="bi bi-arrow-left-right"></i> Equipment Transactions</h1>
</div>

<div class="filter-bar mb-4">
    <div class="btn-group">
        <a href="?status=approved" class="btn btn-<?= $status === 'approved' ? 'primary' : 'outline-primary' ?>">Ready for Checkout</a>
        <a href="?status=checked_out" class="btn btn-<?= $status === 'checked_out' ? 'primary' : 'outline-primary' ?>">Checked Out</a>
        <a href="?status=overdue" class="btn btn-<?= $status === 'overdue' ? 'primary' : 'outline-primary' ?>">Overdue</a>
        <a href="?status=returned" class="btn btn-<?= $status === 'returned' ? 'primary' : 'outline-primary' ?>">Returned</a>
        <a href="?status=all" class="btn btn-<?= $status === 'all' ? 'primary' : 'outline-primary' ?>">All</a>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Request #</th><th>Borrower</th><th>Equipment</th><th>Qty</th><th>Return Date</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No transactions found</td></tr>
                    <?php else: ?>
                    <?php foreach ($requests as $req): ?>
                    <tr>
                        <td><?= sanitize($req['request_number']) ?></td>
                        <td><?= sanitize($req['first_name'] . ' ' . $req['last_name']) ?></td>
                        <td><?= sanitize($req['equipment_name']) ?></td>
                        <td><?= $req['quantity'] ?></td>
                        <td><?= formatDate($req['return_date']) ?></td>
                        <td><?= statusBadge($req['status']) ?></td>
                        <td>
                            <?php if ($req['status'] === 'approved'): ?>
                            <a href="<?= BASE_URL ?>/transactions/checkout.php?request_id=<?= $req['id'] ?>" class="btn btn-sm btn-primary">Checkout</a>
                            <?php elseif (in_array($req['status'], ['checked_out', 'overdue'])): ?>
                            <a href="<?= BASE_URL ?>/transactions/return.php?request_id=<?= $req['id'] ?>" class="btn btn-sm btn-success">Return</a>
                            <?php else: ?>
                            <a href="<?= BASE_URL ?>/requests/view.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
