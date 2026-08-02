<?php
require_once __DIR__ . '/../includes/auth.php';
requireInventoryModule();

$db = getDB();
$search = get('search');
$category = get('category');
$availability = get('availability');
$condition = get('condition');
$page = max(1, (int) get('page', '1'));
$perPage = 12;

$where = ['e.is_active = 1'];
$params = [];

if ($search) {
    $where[] = '(e.name LIKE ? OR e.description LIKE ? OR e.barcode LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($category) {
    $where[] = 'e.category_id = ?';
    $params[] = $category;
}
if ($availability === 'available') {
    $where[] = 'e.quantity_available > 0';
} elseif ($availability === 'unavailable') {
    $where[] = 'e.quantity_available = 0';
} elseif ($availability === 'low_stock') {
    $where[] = 'e.quantity_available <= e.low_stock_threshold';
}
if ($condition) {
    $where[] = 'e.condition = ?';
    $params[] = $condition;
}

$whereClause = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM equipment e WHERE $whereClause");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pagination = paginate($total, $perPage, $page);

$sql = "SELECT e.*, c.name as category_name FROM equipment e
        JOIN equipment_categories c ON e.category_id = c.id
        WHERE $whereClause ORDER BY e.name ASC LIMIT {$pagination['offset']}, $perPage";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$equipment = $stmt->fetchAll();

$categories = $db->query('SELECT * FROM equipment_categories WHERE is_active = 1 ORDER BY name')->fetchAll();

$pageTitle = 'Equipment Inventory';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-box-seam"></i> Equipment Inventory</h1>
        <p class="text-muted mb-0">Browse and manage sports equipment</p>
    </div>
    <?php if (canManageInventory()): ?>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/equipment/add.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add Equipment</a>
        <a href="<?= BASE_URL ?>/equipment/maintenance.php" class="btn btn-outline-secondary"><i class="bi bi-tools"></i> Maintenance</a>
    </div>
    <?php endif; ?>
</div>

<div class="filter-bar">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Search</label>
            <input type="text" name="search" class="form-control" placeholder="Name, barcode..." value="<?= sanitize($search) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">Category</label>
            <select name="category" class="form-select">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $category == $cat['id'] ? 'selected' : '' ?>><?= sanitize($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Availability</label>
            <select name="availability" class="form-select">
                <option value="">All</option>
                <option value="available" <?= $availability === 'available' ? 'selected' : '' ?>>Available</option>
                <option value="unavailable" <?= $availability === 'unavailable' ? 'selected' : '' ?>>Unavailable</option>
                <option value="low_stock" <?= $availability === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Condition</label>
            <select name="condition" class="form-select">
                <option value="">All</option>
                <?php foreach (['excellent', 'good', 'fair', 'poor', 'damaged'] as $c): ?>
                <option value="<?= $c ?>" <?= $condition === $c ? 'selected' : '' ?>><?= ucfirst($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
            <a href="<?= BASE_URL ?>/equipment/index.php" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
</div>

<div class="row g-4">
    <?php if (empty($equipment)): ?>
    <div class="col-12 text-center py-5">
        <i class="bi bi-inbox" style="font-size: 4rem; color: #ccc;"></i>
        <p class="text-muted mt-3">No equipment found</p>
    </div>
    <?php else: ?>
    <?php foreach ($equipment as $eq): ?>
    <div class="col-md-6 col-lg-4 col-xl-3">
        <div class="card equipment-card h-100">
            <?php if ($eq['image']): ?>
            <img src="<?= UPLOAD_URL . sanitize($eq['image']) ?>" class="card-img-top" alt="<?= sanitize($eq['name']) ?>">
            <?php else: ?>
            <div class="placeholder-img"><i class="bi bi-box-seam"></i></div>
            <?php endif; ?>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="badge bg-secondary"><?= sanitize($eq['category_name']) ?></span>
                    <?= statusBadge($eq['condition']) ?>
                </div>
                <h5 class="card-title"><?= sanitize($eq['name']) ?></h5>
                <p class="card-text text-muted small"><?= sanitize(substr($eq['description'] ?? '', 0, 80)) ?></p>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span><strong><?= $eq['quantity_available'] ?></strong> / <?= $eq['quantity_total'] ?> available</span>
                    <?php if ($eq['quantity_available'] <= $eq['low_stock_threshold']): ?>
                    <span class="badge bg-warning">Low Stock</span>
                    <?php endif; ?>
                </div>
                <small class="text-muted"><i class="bi bi-geo-alt"></i> <?= sanitize($eq['location']) ?></small>
            </div>
            <div class="card-footer bg-white border-top-0 d-flex gap-2">
                <a href="<?= BASE_URL ?>/equipment/view.php?id=<?= $eq['id'] ?>" class="btn btn-sm btn-outline-primary flex-fill">View</a>
                <?php if ($eq['quantity_available'] > 0 && canBorrowEquipment()): ?>
                <a href="<?= BASE_URL ?>/requests/create.php?equipment_id=<?= $eq['id'] ?>" class="btn btn-sm btn-primary flex-fill">Request</a>
                <?php endif; ?>
                <?php if (canManageInventory()): ?>
                <a href="<?= BASE_URL ?>/equipment/edit.php?id=<?= $eq['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?= paginationLinks($pagination, BASE_URL . '/equipment/index.php?search=' . urlencode($search) . '&category=' . urlencode($category) . '&availability=' . urlencode($availability) . '&condition=' . urlencode($condition)) ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
