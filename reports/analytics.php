<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'coordinator', 'staff']);

$db = getDB();
$year = (int) get('year', date('Y'));

$mostBorrowed = $db->query("
    SELECT e.name, c.name as category, COUNT(br.id) as borrow_count, SUM(br.quantity) as total_qty
    FROM borrowing_requests br
    JOIN equipment e ON br.equipment_id = e.id
    JOIN equipment_categories c ON e.category_id = c.id
    WHERE YEAR(br.created_at) = $year
    GROUP BY e.id ORDER BY borrow_count DESC LIMIT 10
")->fetchAll();

$monthlyStats = $db->query("
    SELECT MONTH(created_at) as month, COUNT(*) as total,
           SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned,
           SUM(CASE WHEN status IN ('checked_out', 'overdue') THEN 1 ELSE 0 END) as active
    FROM borrowing_requests WHERE YEAR(created_at) = $year
    GROUP BY MONTH(created_at) ORDER BY month
")->fetchAll();

$monthNames = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

$categoryStats = $db->query("
    SELECT c.name, COUNT(br.id) as count
    FROM borrowing_requests br
    JOIN equipment e ON br.equipment_id = e.id
    JOIN equipment_categories c ON e.category_id = c.id
    WHERE YEAR(br.created_at) = $year
    GROUP BY c.id ORDER BY count DESC
")->fetchAll();

$pageTitle = 'Analytics';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <h1><i class="bi bi-graph-up"></i> Analytics & Statistics</h1>
    <form method="GET" class="d-flex gap-2">
        <select name="year" class="form-select" style="width:auto">
            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
            <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit" class="btn btn-primary">View</button>
    </form>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Most Borrowed Equipment (<?= $year ?>)</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead class="table-light"><tr><th>#</th><th>Equipment</th><th>Category</th><th>Borrows</th><th>Total Qty</th></tr></thead>
                    <tbody>
                        <?php foreach ($mostBorrowed as $i => $eq): ?>
                        <tr><td><?= $i + 1 ?></td><td><?= sanitize($eq['name']) ?></td><td><?= sanitize($eq['category']) ?></td><td><?= $eq['borrow_count'] ?></td><td><?= $eq['total_qty'] ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Borrowing by Category (<?= $year ?>)</div>
            <div class="card-body">
                <?php foreach ($categoryStats as $cs): ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span><?= sanitize($cs['name']) ?></span>
                    <div class="progress flex-grow-1 mx-3" style="height:20px">
                        <?php $maxCat = max(array_column($categoryStats, 'count') ?: [1]); ?>
                        <div class="progress-bar" style="width:<?= ($cs['count'] / $maxCat) * 100 ?>%"><?= $cs['count'] ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card">
            <div class="card-header">Monthly Borrowing Statistics (<?= $year ?>)</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead class="table-light"><tr><th>Month</th><th>Total Requests</th><th>Returned</th><th>Active</th></tr></thead>
                    <tbody>
                        <?php foreach ($monthlyStats as $ms): ?>
                        <tr>
                            <td><?= $monthNames[$ms['month']] ?></td>
                            <td><?= $ms['total'] ?></td>
                            <td><?= $ms['returned'] ?></td>
                            <td><?= $ms['active'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
