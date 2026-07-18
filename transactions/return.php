<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'coordinator', 'staff']);

$requestId = (int) get('request_id');
if (!$requestId) {
    flash('error', 'Request not found.');
    redirect(BASE_URL . '/transactions/index.php');
}

$db = getDB();
$stmt = $db->prepare("
    SELECT br.*, e.name as equipment_name, e.id as equipment_id,
           u.first_name, u.last_name
    FROM borrowing_requests br
    JOIN equipment e ON br.equipment_id = e.id
    JOIN users u ON br.user_id = u.id
    WHERE br.id = ? AND br.status IN ('checked_out', 'overdue')
");
$stmt->execute([$requestId]);
$req = $stmt->fetch();

if (!$req) {
    flash('error', 'Request not found or not eligible for return.');
    redirect(BASE_URL . '/transactions/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $condition = post('condition_on_return', 'good');
    $damageNotes = post('damage_notes');
    $notes = post('notes');
    $reportDamage = post('report_damage');

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE borrowing_requests SET status = 'returned' WHERE id = ?")->execute([$requestId]);

        $db->prepare('UPDATE equipment SET quantity_borrowed = GREATEST(0, quantity_borrowed - ?) WHERE id = ?')
           ->execute([$req['quantity'], $req['equipment_id']]);

        if ($condition === 'damaged' || $reportDamage) {
            $db->prepare('UPDATE equipment SET quantity_damaged = quantity_damaged + ? WHERE id = ?')
               ->execute([$req['quantity'], $req['equipment_id']]);

            $db->prepare('INSERT INTO damage_reports (equipment_id, request_id, reported_by, quantity, damage_type, description) VALUES (?, ?, ?, ?, ?, ?)')
               ->execute([$req['equipment_id'], $requestId, $_SESSION['user_id'], $req['quantity'], post('damage_type', 'minor'), $damageNotes ?: 'Equipment returned in damaged condition']);
        }

        updateEquipmentQuantities($req['equipment_id']);

        $db->prepare('INSERT INTO borrowing_transactions (request_id, transaction_type, quantity, processed_by, condition_on_return, damage_notes, notes) VALUES (?, ?, ?, ?, ?, ?, ?)')
           ->execute([$requestId, 'return', $req['quantity'], $_SESSION['user_id'], $condition, $damageNotes, $notes]);

        createNotification(
            $req['user_id'],
            'Equipment Returned',
            "Your equipment for request {$req['request_number']} has been returned successfully.",
            'success',
            BASE_URL . '/requests/view.php?id=' . $requestId
        );

        auditLog($_SESSION['user_id'], 'return', 'borrowing_request', $requestId);
        $db->commit();

        flash('success', 'Equipment returned successfully.');
        redirect(BASE_URL . '/requests/view.php?id=' . $requestId);
    } catch (Exception $e) {
        $db->rollBack();
        flash('error', 'Return processing failed. Please try again.');
        redirect(BASE_URL . '/transactions/return.php?request_id=' . $requestId);
    }
}

$pageTitle = 'Return Equipment';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="bi bi-box-arrow-in-left"></i> Equipment Return</h1>
</div>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Return Details - <?= sanitize($req['request_number']) ?></div>
            <div class="card-body">
                <div class="mb-3 p-3 bg-light rounded">
                    <p><strong>Borrower:</strong> <?= sanitize($req['first_name'] . ' ' . $req['last_name']) ?></p>
                    <p><strong>Equipment:</strong> <?= sanitize($req['equipment_name']) ?></p>
                    <p><strong>Quantity:</strong> <?= $req['quantity'] ?></p>
                    <p><strong>Due Date:</strong> <?= formatDate($req['return_date']) ?></p>
                    <?php if ($req['return_date'] < date('Y-m-d')): ?>
                    <p class="text-danger"><i class="bi bi-exclamation-triangle"></i> OVERDUE</p>
                    <?php endif; ?>
                </div>

                <form method="POST">
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label">Condition on Return *</label>
                        <select name="condition_on_return" class="form-select" required id="conditionSelect">
                            <?php foreach (['excellent', 'good', 'fair', 'poor', 'damaged'] as $c): ?>
                            <option value="<?= $c ?>"><?= ucfirst($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3" id="damageSection" style="display:none;">
                        <div class="form-check mb-2">
                            <input type="checkbox" name="report_damage" class="form-check-input" id="reportDamage" value="1">
                            <label class="form-check-label" for="reportDamage">Report as damage</label>
                        </div>
                        <select name="damage_type" class="form-select mb-2">
                            <option value="minor">Minor Damage</option>
                            <option value="major">Major Damage</option>
                            <option value="lost">Lost</option>
                            <option value="stolen">Stolen</option>
                        </select>
                        <textarea name="damage_notes" class="form-control" rows="2" placeholder="Describe the damage..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success flex-fill"><i class="bi bi-check-lg"></i> Confirm Return</button>
                        <a href="<?= BASE_URL ?>/requests/view.php?id=<?= $requestId ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('conditionSelect').addEventListener('change', function() {
    document.getElementById('damageSection').style.display = ['damaged', 'poor'].includes(this.value) ? 'block' : 'none';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
