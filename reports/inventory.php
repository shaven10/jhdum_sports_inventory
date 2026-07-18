<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'coordinator', 'staff']);

$db = getDB();
$category = get('category');

$where = 'e.is_active = 1';
$params = [];
if ($category) {
    $where .= ' AND e.category_id = ?';
    $params[] = $category;
}

$stmt = $db->prepare("SELECT e.*, c.name as category_name FROM equipment e JOIN equipment_categories c ON e.category_id = c.id WHERE $where ORDER BY c.name, e.name");
$stmt->execute($params);
$equipment = $stmt->fetchAll();
$categories = $db->query('SELECT * FROM equipment_categories ORDER BY name')->fetchAll();

$totals = ['total' => 0, 'available' => 0, 'borrowed' => 0, 'reserved' => 0, 'damaged' => 0];
foreach ($equipment as $eq) {
    $totals['total'] += $eq['quantity_total'];
    $totals['available'] += $eq['quantity_available'];
    $totals['borrowed'] += $eq['quantity_borrowed'];
    $totals['reserved'] += $eq['quantity_reserved'];
    $totals['damaged'] += $eq['quantity_damaged'];
}

$pageTitle = 'Inventory Report';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <h1><i class="bi bi-file-earmark-bar-graph"></i> Inventory Report</h1>
    <button onclick="printReport()" class="btn btn-outline-secondary no-print"><i class="bi bi-printer"></i> Print</button>
</div>

<div class="filter-bar no-print mb-4">
    <form method="GET" class="d-flex gap-2">
        <select name="category" class="form-select" style="max-width:250px">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= $category == $cat['id'] ? 'selected' : '' ?>><?= sanitize($cat['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
    </form>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($totals as $label => $val): ?>
    <div class="col"><div class="card text-center p-3"><div class="fs-5 fw-bold"><?= $val ?></div><small class="text-muted"><?= ucfirst($label) ?></small></div></div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-bordered mb-0">
            <thead class="table-light"><tr><th>Category</th><th>Equipment</th><th>Total</th><th>Available</th><th>Borrowed</th><th>Reserved</th><th>Damaged</th><th>Condition</th><th>Location</th></tr></thead>
            <tbody>
                <?php foreach ($equipment as $eq): ?>
                <tr>
                    <td><?= sanitize($eq['category_name']) ?></td>
                    <td><?= sanitize($eq['name']) ?></td>
                    <td><?= $eq['quantity_total'] ?></td>
                    <td><?= $eq['quantity_available'] ?></td>
                    <td><?= $eq['quantity_borrowed'] ?></td>
                    <td><?= $eq['quantity_reserved'] ?></td>
                    <td><?= $eq['quantity_damaged'] ?></td>
                    <td><?= ucfirst($eq['condition']) ?></td>
                    <td><?= sanitize($eq['location']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="text-muted mt-3 small">Generated on <?= date('F d, Y h:i A') ?> | <?= APP_CAMPUS ?></p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
