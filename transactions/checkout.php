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
    SELECT br.*, e.name as equipment_name, e.quantity_available,
           u.first_name, u.last_name, u.student_id, u.email
    FROM borrowing_requests br
    JOIN equipment e ON br.equipment_id = e.id
    JOIN users u ON br.user_id = u.id
    WHERE br.id = ? AND br.status = 'approved'
");
$stmt->execute([$requestId]);
$req = $stmt->fetch();

if (!$req) {
    flash('error', 'Request not found or not approved for checkout.');
    redirect(BASE_URL . '/transactions/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $verified = post('borrower_verified') ? 1 : 0;
    $notes = post('notes');

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE borrowing_requests SET status = 'checked_out' WHERE id = ?")->execute([$requestId]);

        $db->prepare('UPDATE equipment SET quantity_reserved = GREATEST(0, quantity_reserved - ?), quantity_borrowed = quantity_borrowed + ? WHERE id = ?')
           ->execute([$req['quantity'], $req['quantity'], $req['equipment_id']]);
        updateEquipmentQuantities($req['equipment_id']);

        $db->prepare('INSERT INTO borrowing_transactions (request_id, transaction_type, quantity, processed_by, borrower_verified, notes) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$requestId, 'checkout', $req['quantity'], $_SESSION['user_id'], $verified, $notes]);

        createNotification(
            $req['user_id'],
            'Equipment Checked Out',
            "Your equipment for request {$req['request_number']} has been checked out. Please return by " . formatDate($req['return_date']) . ".",
            'info',
            BASE_URL . '/requests/view.php?id=' . $requestId
        );

        auditLog($_SESSION['user_id'], 'checkout', 'borrowing_request', $requestId);
        $db->commit();

        flash('success', 'Equipment checked out successfully.');
        redirect(BASE_URL . '/requests/view.php?id=' . $requestId);
    } catch (Exception $e) {
        $db->rollBack();
        flash('error', 'Checkout failed. Please try again.');
        redirect(BASE_URL . '/transactions/checkout.php?request_id=' . $requestId);
    }
}

$pageTitle = 'Checkout Equipment';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="bi bi-box-arrow-right"></i> Equipment Checkout</h1>
</div>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Checkout Details - <?= sanitize($req['request_number']) ?></div>
            <div class="card-body">
                <div class="mb-3 p-3 bg-light rounded">
                    <p><strong>Borrower:</strong> <?= sanitize($req['first_name'] . ' ' . $req['last_name']) ?></p>
                    <p><strong>Email:</strong> <?= sanitize($req['email']) ?></p>
                    <?php if ($req['student_id']): ?>
                    <p><strong>Student ID:</strong> <?= sanitize($req['student_id']) ?></p>
                    <?php endif; ?>
                    <p><strong>Equipment:</strong> <?= sanitize($req['equipment_name']) ?></p>
                    <p><strong>Quantity:</strong> <?= $req['quantity'] ?></p>
                    <p><strong>Return Date:</strong> <?= formatDate($req['return_date']) ?></p>
                </div>

                <form method="POST">
                    <?= csrfField() ?>
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="borrower_verified" class="form-check-input" id="verified" required>
                        <label class="form-check-label" for="verified">I have verified the borrower's identity</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-check-lg"></i> Confirm Checkout</button>
                        <a href="<?= BASE_URL ?>/requests/view.php?id=<?= $requestId ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
