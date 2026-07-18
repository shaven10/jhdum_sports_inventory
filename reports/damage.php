<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'coordinator', 'staff']);

$db = getDB();
$reports = $db->query("
    SELECT dr.*, e.name as equipment_name, u.first_name, u.last_name
    FROM damage_reports dr
    JOIN equipment e ON dr.equipment_id = e.id
    JOIN users u ON dr.reported_by = u.id
    ORDER BY dr.created_at DESC
")->fetchAll();

$pageTitle = 'Damage Report';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <h1><i class="bi bi-shield-exclamation"></i> Damage & Loss Report</h1>
    <button onclick="printReport()" class="btn btn-outline-secondary no-print"><i class="bi bi-printer"></i> Print</button>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-bordered mb-0">
            <thead class="table-light"><tr><th>Date</th><th>Equipment</th><th>Qty</th><th>Type</th><th>Description</th><th>Reported By</th><th>Status</th></tr></thead>
            <tbody>
                <?php if (empty($reports)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No damage reports</td></tr>
                <?php else: ?>
                <?php foreach ($reports as $r): ?>
                <tr>
                    <td><?= formatDate($r['created_at']) ?></td>
                    <td><?= sanitize($r['equipment_name']) ?></td>
                    <td><?= $r['quantity'] ?></td>
                    <td><?= ucfirst($r['damage_type']) ?></td>
                    <td><?= sanitize($r['description']) ?></td>
                    <td><?= sanitize($r['first_name'] . ' ' . $r['last_name']) ?></td>
                    <td><?= statusBadge($r['status']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
