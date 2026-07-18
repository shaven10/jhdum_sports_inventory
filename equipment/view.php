<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$id = (int) get('id');
if (!$id) {
    flash('error', 'Equipment not found.');
    redirect(BASE_URL . '/equipment/index.php');
}

$db = getDB();
$stmt = $db->prepare('SELECT e.*, c.name as category_name FROM equipment e JOIN equipment_categories c ON e.category_id = c.id WHERE e.id = ?');
$stmt->execute([$id]);
$eq = $stmt->fetch();

if (!$eq) {
    flash('error', 'Equipment not found.');
    redirect(BASE_URL . '/equipment/index.php');
}

$usageHistory = $db->prepare("
    SELECT br.*, u.first_name, u.last_name
    FROM borrowing_requests br
    JOIN users u ON br.user_id = u.id
    WHERE br.equipment_id = ?
    ORDER BY br.created_at DESC LIMIT 10
");
$usageHistory->execute([$id]);
$history = $usageHistory->fetchAll();

$pageTitle = $eq['name'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1><?= sanitize($eq['name']) ?></h1>
        <p class="text-muted mb-0"><?= sanitize($eq['category_name']) ?> &middot; <?= sanitize($eq['barcode']) ?></p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($eq['quantity_available'] > 0 && $_SESSION['user_role'] === 'student'): ?>
        <a href="<?= BASE_URL ?>/requests/create.php?equipment_id=<?= $eq['id'] ?>" class="btn btn-primary"><i class="bi bi-clipboard-plus"></i> Request to Borrow</a>
        <?php endif; ?>
        <?php if (canManageInventory()): ?>
        <a href="<?= BASE_URL ?>/equipment/edit.php?id=<?= $eq['id'] ?>" class="btn btn-outline-secondary"><i class="bi bi-pencil"></i> Edit</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/equipment/index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card">
            <?php if ($eq['image']): ?>
            <img src="<?= UPLOAD_URL . sanitize($eq['image']) ?>" class="card-img-top" alt="">
            <?php else: ?>
            <div class="placeholder-img"><i class="bi bi-box-seam"></i></div>
            <?php endif; ?>
            <div class="card-body">
                <h6>Barcode / QR</h6>
                <p class="font-monospace bg-light p-2 rounded text-center"><?= sanitize($eq['barcode']) ?></p>
                <p class="text-muted small"><?= sanitize($eq['description']) ?></p>
                <p><i class="bi bi-geo-alt"></i> <?= sanitize($eq['location']) ?></p>
                <?= statusBadge($eq['condition']) ?>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="row g-3 mb-4">
            <div class="col-4 col-md-2">
                <div class="card text-center p-3">
                    <div class="fs-4 fw-bold text-primary"><?= $eq['quantity_total'] ?></div>
                    <small class="text-muted">Total</small>
                </div>
            </div>
            <div class="col-4 col-md-2">
                <div class="card text-center p-3">
                    <div class="fs-4 fw-bold text-success"><?= $eq['quantity_available'] ?></div>
                    <small class="text-muted">Available</small>
                </div>
            </div>
            <div class="col-4 col-md-2">
                <div class="card text-center p-3">
                    <div class="fs-4 fw-bold text-info"><?= $eq['quantity_borrowed'] ?></div>
                    <small class="text-muted">Borrowed</small>
                </div>
            </div>
            <div class="col-4 col-md-2">
                <div class="card text-center p-3">
                    <div class="fs-4 fw-bold text-warning"><?= $eq['quantity_reserved'] ?></div>
                    <small class="text-muted">Reserved</small>
                </div>
            </div>
            <div class="col-4 col-md-2">
                <div class="card text-center p-3">
                    <div class="fs-4 fw-bold text-danger"><?= $eq['quantity_damaged'] ?></div>
                    <small class="text-muted">Damaged</small>
                </div>
            </div>
            <div class="col-4 col-md-2">
                <div class="card text-center p-3">
                    <div class="fs-4 fw-bold text-secondary"><?= $eq['quantity_maintenance'] ?></div>
                    <small class="text-muted">Maintenance</small>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history"></i> Usage History</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Request #</th><th>Borrower</th><th>Qty</th><th>Status</th><th>Date</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($history)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No usage history</td></tr>
                            <?php else: ?>
                            <?php foreach ($history as $h): ?>
                            <tr>
                                <td><a href="<?= BASE_URL ?>/requests/view.php?id=<?= $h['id'] ?>"><?= sanitize($h['request_number']) ?></a></td>
                                <td><?= sanitize($h['first_name'] . ' ' . $h['last_name']) ?></td>
                                <td><?= $h['quantity'] ?></td>
                                <td><?= statusBadge($h['status']) ?></td>
                                <td><?= formatDate($h['created_at']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
