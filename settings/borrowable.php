<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);
ensureEquipmentBorrowableColumn();

$db = getDB();
$search = get('search');
$category = get('category');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $action = post('action');

    if ($action === 'toggle') {
        $id = (int) post('id');
        $stmt = $db->prepare('SELECT id, name, is_borrowable FROM equipment WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $next = ((int) $row['is_borrowable'] === 1) ? 0 : 1;
            setEquipmentBorrowable($id, $next === 1);
            auditLog($_SESSION['user_id'], $next ? 'allow_borrow' : 'disallow_borrow', 'equipment', $id, null, ['name' => $row['name']]);
            flash('success', $row['name'] . ' is now ' . ($next ? 'allowed' : 'not allowed') . ' for student borrowing.');
        }
        redirect(BASE_URL . '/settings/borrowable.php' . ($search || $category ? '?' . http_build_query(array_filter(['search' => $search, 'category' => $category])) : ''));
    }

    if ($action === 'save') {
        $ids = $_POST['equipment_id'] ?? [];
        $checked = $_POST['borrowable'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        if (!is_array($checked)) {
            $checked = [];
        }
        $update = $db->prepare('UPDATE equipment SET is_borrowable = ? WHERE id = ?');
        $changed = 0;
        foreach ($ids as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0) {
                continue;
            }
            $allow = isset($checked[$id]) ? 1 : 0;
            $update->execute([$allow, $id]);
            $changed++;
        }
        auditLog($_SESSION['user_id'], 'update_borrowable_equipment', 'equipment', null, null, ['updated' => $changed]);
        flash('success', 'Borrowable equipment list saved (' . $changed . ' item' . ($changed === 1 ? '' : 's') . ').');
        redirect(BASE_URL . '/settings/borrowable.php' . ($search || $category ? '?' . http_build_query(array_filter(['search' => $search, 'category' => $category])) : ''));
    }

    if ($action === 'allow_all' || $action === 'disallow_all') {
        $allow = $action === 'allow_all' ? 1 : 0;
        $db->exec('UPDATE equipment SET is_borrowable = ' . $allow . ' WHERE is_active = 1');
        auditLog($_SESSION['user_id'], $allow ? 'allow_all_borrow' : 'disallow_all_borrow', 'equipment');
        flash('success', $allow ? 'All active equipment is now allowed for borrowing.' : 'All active equipment is now blocked from student borrowing.');
        redirect(BASE_URL . '/settings/borrowable.php');
    }
}

$where = ['e.is_active = 1'];
$params = [];
if ($search) {
    $where[] = '(e.name LIKE ? OR e.barcode LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($category) {
    $where[] = 'e.category_id = ?';
    $params[] = $category;
}
$whereClause = implode(' AND ', $where);
$stmt = $db->prepare("SELECT e.*, c.name AS category_name
    FROM equipment e
    JOIN equipment_categories c ON e.category_id = c.id
    WHERE $whereClause
    ORDER BY c.name, e.name");
$stmt->execute($params);
$equipment = $stmt->fetchAll();

$grouped = [];
foreach ($equipment as $eq) {
    $grouped[$eq['category_name']][] = $eq;
}

$allowedCount = 0;
foreach ($equipment as $eq) {
    if (isEquipmentBorrowable($eq)) {
        $allowedCount++;
    }
}

$categories = $db->query('SELECT * FROM equipment_categories WHERE is_active = 1 ORDER BY name')->fetchAll();

$pageTitle = 'Borrowable Equipment';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-box-arrow-up"></i> Borrowable Equipment</h1>
        <p class="text-muted mb-0">Choose which inventory items students may request to borrow. Unchecked items stay in staff inventory only.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/settings/index.php" class="btn btn-outline-secondary"><i class="bi bi-gear"></i> System Settings</a>
        <a href="<?= BASE_URL ?>/equipment/index.php" class="btn btn-outline-primary"><i class="bi bi-box-seam"></i> Equipment</a>
    </div>
</div>

<div class="alert alert-secondary py-2">
    <i class="bi bi-info-circle"></i>
    <?= (int) $allowedCount ?> of <?= count($equipment) ?> listed item<?= count($equipment) === 1 ? '' : 's' ?> allowed for student borrowing.
    Items that are not allowed still appear for staff, but students cannot request them.
</div>

<div class="filter-bar mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Search</label>
            <input type="text" name="search" class="form-control" placeholder="Name or barcode" value="<?= sanitize($search) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Category</label>
            <select name="category" class="form-select">
                <option value="">All categories</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= (int) $cat['id'] ?>" <?= (string) $category === (string) $cat['id'] ? 'selected' : '' ?>><?= sanitize($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-5">
            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
            <a href="<?= BASE_URL ?>/settings/borrowable.php" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
</div>

<form method="POST" class="mb-3 d-flex gap-2 flex-wrap">
    <?= csrfField() ?>
    <button type="submit" name="action" value="allow_all" class="btn btn-sm btn-outline-success" data-confirm="Allow students to borrow all active equipment?">Allow all</button>
    <button type="submit" name="action" value="disallow_all" class="btn btn-sm btn-outline-danger" data-confirm="Block student borrowing for all active equipment?">Disallow all</button>
</form>

<?php if (empty($equipment)): ?>
<div class="alert alert-info">No equipment matches this filter.</div>
<?php else: ?>
<form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($search): ?><input type="hidden" name="search" value="<?= sanitize($search) ?>"><?php endif; ?>
    <?php if ($category): ?><input type="hidden" name="category" value="<?= sanitize($category) ?>"><?php endif; ?>
    <?php foreach ($grouped as $catName => $items): ?>
    <div class="card mb-3">
        <div class="card-header fw-semibold"><?= sanitize($catName) ?> <span class="badge bg-secondary"><?= count($items) ?></span></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:3rem;" class="text-center">Borrow</th>
                        <th>Equipment</th>
                        <th>Barcode</th>
                        <th>Stock</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $eq): ?>
                    <?php $allowed = isEquipmentBorrowable($eq); ?>
                    <tr class="<?= $allowed ? '' : 'table-light' ?>">
                        <td class="text-center">
                            <input type="hidden" name="equipment_id[]" value="<?= (int) $eq['id'] ?>">
                            <input type="checkbox" class="form-check-input" name="borrowable[<?= (int) $eq['id'] ?>]" value="1" <?= $allowed ? 'checked' : '' ?> title="Allow students to borrow">
                        </td>
                        <td>
                            <strong><?= sanitize($eq['name']) ?></strong>
                            <div class="small text-muted"><?= sanitize($eq['location'] ?: '') ?></div>
                        </td>
                        <td class="font-monospace small"><?= sanitize($eq['barcode'] ?: '—') ?></td>
                        <td><?= (int) $eq['quantity_available'] ?> / <?= (int) $eq['quantity_total'] ?></td>
                        <td>
                            <?php if ($allowed): ?>
                            <span class="badge bg-success">Allowed</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">Not borrowable</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save borrowable list</button>
        <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-outline-secondary">Admin Panel</a>
    </div>
</form>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
