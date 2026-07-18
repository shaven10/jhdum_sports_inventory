<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'coordinator', 'staff']);

$db = getDB();
$categories = $db->query('SELECT * FROM equipment_categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/equipment/add.php');
    }

    $name = post('name');
    $categoryId = (int) post('category_id');
    $description = post('description');
    $quantity = max(0, (int) post('quantity'));
    $condition = post('condition', 'good');
    $location = post('location', 'Sports Office');
    $barcode = post('barcode') ?: generateBarcode();
    $lowStock = max(1, (int) post('low_stock_threshold', '3'));

    if (empty($name)) $errors[] = 'Equipment name is required.';
    if (!$categoryId) $errors[] = 'Category is required.';
    if ($quantity < 0) $errors[] = 'Quantity must be 0 or greater.';

    $image = null;
    if (!empty($_FILES['image']['name'])) {
        $image = uploadImage($_FILES['image']);
        if (!$image) $errors[] = 'Invalid image file.';
    }

    if (empty($errors)) {
        $stmt = $db->prepare('INSERT INTO equipment (category_id, name, description, barcode, quantity_total, quantity_available, `condition`, location, image, low_stock_threshold) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$categoryId, $name, $description, $barcode, $quantity, $quantity, $condition, $location, $image, $lowStock]);

        $id = (int) $db->lastInsertId();
        auditLog($_SESSION['user_id'], 'create', 'equipment', $id, null, ['name' => $name, 'quantity' => $quantity]);
        flash('success', 'Equipment added successfully.');
        redirect(BASE_URL . '/equipment/view.php?id=' . $id);
    }
}

$pageTitle = 'Add Equipment';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="bi bi-plus-circle"></i> Add Equipment</h1>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Equipment Name *</label>
                            <input type="text" name="name" class="form-control" required value="<?= sanitize(post('name')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Category *</label>
                            <select name="category_id" class="form-select" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= post('category_id') == $cat['id'] ? 'selected' : '' ?>><?= sanitize($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3"><?= sanitize(post('description')) ?></textarea>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Quantity *</label>
                            <input type="number" name="quantity" class="form-control" min="0" required value="<?= sanitize(post('quantity', '1')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Condition</label>
                            <select name="condition" class="form-select">
                                <?php foreach (['excellent', 'good', 'fair', 'poor', 'damaged'] as $c): ?>
                                <option value="<?= $c ?>" <?= post('condition', 'good') === $c ? 'selected' : '' ?>><?= ucfirst($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Location</label>
                            <input type="text" name="location" class="form-control" value="<?= sanitize(post('location', 'Sports Storage Room')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Low Stock Threshold</label>
                            <input type="number" name="low_stock_threshold" class="form-control" min="1" value="<?= sanitize(post('low_stock_threshold', '3')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Barcode (auto-generated if empty)</label>
                            <input type="text" name="barcode" class="form-control" value="<?= sanitize(post('barcode')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Equipment Image</label>
                            <input type="file" name="image" class="form-control" accept="image/*">
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Equipment</button>
                        <a href="<?= BASE_URL ?>/equipment/index.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
