<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'coordinator', 'staff']);

$db = getDB();
$overdue = $db->query("
    SELECT br.*, e.name as equipment_name, u.first_name, u.last_name, u.email, u.phone,
           DATEDIFF(CURDATE(), br.return_date) as days_overdue
    FROM borrowing_requests br
    JOIN equipment e ON br.equipment_id = e.id
    JOIN users u ON br.user_id = u.id
    WHERE br.status IN ('checked_out', 'overdue') AND br.return_date < CURDATE()
    ORDER BY br.return_date ASC
")->fetchAll();

$pageTitle = 'Overdue Report';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <h1><i class="bi bi-exclamation-triangle"></i> Overdue Borrowings</h1>
    <button onclick="printReport()" class="btn btn-outline-secondary no-print"><i class="bi bi-printer"></i> Print</button>
</div>

<?= renderReportHeader('Overdue Borrowings Report') ?>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-bordered mb-0">
            <thead class="table-light"><tr><th>Request #</th><th>Borrower</th><th>Contact</th><th>Equipment</th><th>Qty</th><th>Due Date</th><th>Days Overdue</th><th>Action</th></tr></thead>
            <tbody>
                <?php if (empty($overdue)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No overdue borrowings</td></tr>
                <?php else: ?>
                <?php foreach ($overdue as $r): ?>
                <tr class="table-danger">
                    <td><?= sanitize($r['request_number']) ?></td>
                    <td><?= sanitize($r['first_name'] . ' ' . $r['last_name']) ?></td>
                    <td><?= sanitize($r['email']) ?><br><small><?= sanitize($r['phone'] ?? '') ?></small></td>
                    <td><?= sanitize($r['equipment_name']) ?></td>
                    <td><?= $r['quantity'] ?></td>
                    <td><?= formatDate($r['return_date']) ?></td>
                    <td><strong><?= $r['days_overdue'] ?> days</strong></td>
                    <td class="no-print"><a href="<?= BASE_URL ?>/transactions/return.php?request_id=<?= $r['id'] ?>" class="btn btn-sm btn-success">Return</a></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= renderReportFooter('Overdue Report') ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
