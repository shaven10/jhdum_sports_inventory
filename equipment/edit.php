<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'coordinator', 'staff']);

$id = (int) get('id');
if (!$id) {
    flash('error', 'Equipment not found.');
    redirect(BASE_URL . '/equipment/index.php');
}

$db = getDB();
$stmt = $db->prepare('SELECT * FROM equipment WHERE id = ?');
$stmt->execute([$id]);
$eq = $stmt->fetch();

if (!$eq) {
    flash('error', 'Equipment not found.');
    redirect(BASE_URL . '/equipment/index.php');
}

$categories = $db->query('SELECT * FROM equipment_categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/equipment/edit.php?id=' . $id);
    }

    $action = post('action', 'update');

    if ($action === 'delete' && hasRole('admin')) {
        $db->prepare('UPDATE equipment SET is_active = 0 WHERE id = ?')->execute([$id]);
        auditLog($_SESSION['user_id'], 'delete', 'equipment', $id, $eq, null);
        flash('success', 'Equipment deleted successfully.');
        redirect(BASE_URL . '/equipment/index.php');
    }

    $name = post('name');
    $categoryId = (int) post('category_id');
    $description = post('description');
    $quantityTotal = max(0, (int) post('quantity_total'));
    $condition = post('condition');
    $location = post('location');
    $barcode = post('barcode');
    $lowStock = max(1, (int) post('low_stock_threshold'));

    if (empty($name)) $errors[] = 'Equipment name is required.';

    $image = $eq['image'];
    if (!empty($_FILES['image']['name'])) {
        $newImage = uploadImage($_FILES['image']);
        if ($newImage) {
            deleteImage($eq['image']);
            $image = $newImage;
        } else {
            $errors[] = 'Invalid image file.';
        }
    }

    if (empty($errors)) {
        $oldValues = $eq;
        $stmt = $db->prepare('UPDATE equipment SET category_id=?, name=?, description=?, barcode=?, quantity_total=?, `condition`=?, location=?, image=?, low_stock_threshold=? WHERE id=?');
        $stmt->execute([$categoryId, $name, $description, $barcode, $quantityTotal, $condition, $location, $image, $lowStock, $id]);
        updateEquipmentQuantities($id);

        auditLog($_SESSION['user_id'], 'update', 'equipment', $id, $oldValues, ['name' => $name]);
        flash('success', 'Equipment updated successfully.');
        redirect(BASE_URL . '/equipment/view.php?id=' . $id);
    }
}

$pageTitle = 'Edit Equipment';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="bi bi-pencil"></i> Edit Equipment</h1>
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
                    <input type="hidden" name="action" value="update">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Equipment Name *</label>
                            <input type="text" name="name" class="form-control" required value="<?= sanitize($eq['name']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Category *</label>
                            <select name="category_id" class="form-select" required>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $eq['category_id'] == $cat['id'] ? 'selected' : '' ?>><?= sanitize($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3"><?= sanitize($eq['description']) ?></textarea>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Total Quantity</label>
                            <input type="number" name="quantity_total" class="form-control" min="0" value="<?= $eq['quantity_total'] ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Condition</label>
                            <select name="condition" class="form-select">
                                <?php foreach (['excellent', 'good', 'fair', 'poor', 'damaged'] as $c): ?>
                                <option value="<?= $c ?>" <?= $eq['condition'] === $c ? 'selected' : '' ?>><?= ucfirst($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Location</label>
                            <input type="text" name="location" class="form-control" value="<?= sanitize($eq['location']) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Low Stock Threshold</label>
                            <input type="number" name="low_stock_threshold" class="form-control" min="1" value="<?= $eq['low_stock_threshold'] ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Barcode</label>
                            <input type="text" name="barcode" class="form-control" value="<?= sanitize($eq['barcode']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Equipment Image</label>
                            <input type="file" name="image" class="form-control" accept="image/*">
                            <?php if ($eq['image']): ?>
                            <small class="text-muted">Current: <?= sanitize($eq['image']) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Update</button>
                        <a href="<?= BASE_URL ?>/equipment/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
                        <?php if (hasRole('admin')): ?>
                        <button type="submit" name="action" value="delete" class="btn btn-outline-danger ms-auto" data-confirm="Are you sure you want to delete this equipment?"><i class="bi bi-trash"></i> Delete</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
