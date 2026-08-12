<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'coordinator', 'staff']);

$db = getDB();
$from = get('from', date('Y-m-01'));
$to = get('to', date('Y-m-d'));
$status = get('status');

$where = ['br.created_at BETWEEN ? AND ?'];
$params = [$from . ' 00:00:00', $to . ' 23:59:59'];
if ($status) {
    $where[] = 'br.status = ?';
    $params[] = $status;
}

$whereClause = implode(' AND ', $where);
$stmt = $db->prepare("
    SELECT br.*, e.name as equipment_name, u.first_name, u.last_name
    FROM borrowing_requests br
    JOIN equipment e ON br.equipment_id = e.id
    JOIN users u ON br.user_id = u.id
    WHERE $whereClause
    ORDER BY br.created_at DESC
");
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pageTitle = 'Borrowing Report';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <h1><i class="bi bi-file-earmark-text"></i> Borrowing Report</h1>
    <button onclick="printReport()" class="btn btn-outline-secondary no-print"><i class="bi bi-printer"></i> Print</button>
</div>

<?= renderReportHeader('Borrowing Report', ['meta' => 'Period: ' . formatDate($from) . ' – ' . formatDate($to)]) ?>

<div class="filter-bar no-print mb-4">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= sanitize($from) ?>"></div>
        <div class="col-md-3"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= sanitize($to) ?>"></div>
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">All</option>
                <?php foreach (['pending', 'approved', 'rejected', 'checked_out', 'returned', 'overdue'] as $s): ?>
                <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3"><button type="submit" class="btn btn-primary">Generate</button></div>
    </form>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-bordered mb-0">
            <thead class="table-light"><tr><th>Request #</th><th>Borrower</th><th>Equipment</th><th>Qty</th><th>Borrow Date</th><th>Return Date</th><th>Purpose</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($requests as $r): ?>
                <tr>
                    <td><?= sanitize($r['request_number']) ?></td>
                    <td><?= sanitize($r['first_name'] . ' ' . $r['last_name']) ?></td>
                    <td><?= sanitize($r['equipment_name']) ?></td>
                    <td><?= $r['quantity'] ?></td>
                    <td><?= formatDate($r['borrow_date']) ?></td>
                    <td><?= formatDate($r['return_date']) ?></td>
                    <td><?= purposeLabel($r['purpose']) ?></td>
                    <td><?= ucfirst(str_replace('_', ' ', $r['status'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="text-muted mt-3 small no-print">Total records: <?= count($requests) ?> | Period: <?= formatDate($from) ?> - <?= formatDate($to) ?></p>
<?= renderReportFooter('Borrowing Report') ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
