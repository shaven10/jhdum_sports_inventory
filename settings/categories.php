<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $action = post('action');

    if ($action === 'add') {
        $name = post('name');
        $description = post('description');
        if ($name) {
            $db->prepare('INSERT INTO equipment_categories (name, description) VALUES (?, ?)')->execute([$name, $description]);
            flash('success', 'Category added.');
        }
    }

    if ($action === 'toggle') {
        $id = (int) post('id');
        $db->prepare('UPDATE equipment_categories SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash('success', 'Category updated.');
    }

    redirect(BASE_URL . '/settings/categories.php');
}

$categories = $db->query('SELECT c.*, (SELECT COUNT(*) FROM equipment e WHERE e.category_id = c.id) as equipment_count FROM equipment_categories c ORDER BY c.name')->fetchAll();

$pageTitle = 'Equipment Categories';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header"><h1><i class="bi bi-tags"></i> Equipment Categories</h1></div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Add Category</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3"><label class="form-label">Name</label><input type="text" name="name" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
                    <button type="submit" class="btn btn-primary w-100">Add Category</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead class="table-light"><tr><th>Name</th><th>Description</th><th>Equipment</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td><?= sanitize($cat['name']) ?></td>
                            <td><?= sanitize($cat['description'] ?? '-') ?></td>
                            <td><?= $cat['equipment_count'] ?></td>
                            <td><?= $cat['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                            <td>
                                <form method="POST" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $cat['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
