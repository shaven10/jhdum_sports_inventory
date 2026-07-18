<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$id = (int) get('id');
if (!$id) {
    flash('error', 'Request not found.');
    redirect(BASE_URL . '/requests/index.php');
}

$db = getDB();
$stmt = $db->prepare("
    SELECT br.*, e.name as equipment_name, e.quantity_available, e.barcode,
           u.first_name, u.last_name, u.email, u.student_id, u.department,
           r.first_name as reviewer_first, r.last_name as reviewer_last
    FROM borrowing_requests br
    JOIN equipment e ON br.equipment_id = e.id
    JOIN users u ON br.user_id = u.id
    LEFT JOIN users r ON br.reviewed_by = r.id
    WHERE br.id = ?
");
$stmt->execute([$id]);
$req = $stmt->fetch();

if (!$req) {
    flash('error', 'Request not found.');
    redirect(BASE_URL . '/requests/index.php');
}

if ($_SESSION['user_role'] === 'student' && $req['user_id'] != $_SESSION['user_id']) {
    flash('error', 'You do not have permission to view this request.');
    redirect(BASE_URL . '/requests/index.php');
}

$transactions = $db->prepare('SELECT bt.*, u.first_name, u.last_name FROM borrowing_transactions bt JOIN users u ON bt.processed_by = u.id WHERE bt.request_id = ? ORDER BY bt.transaction_date');
$transactions->execute([$id]);
$txnList = $transactions->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $action = post('action');

    if ($action === 'approve' && canApproveRequests() && $req['status'] === 'pending') {
        if ($req['quantity'] > $req['quantity_available']) {
            flash('error', 'Insufficient stock to approve this request.');
        } else {
            $db->prepare("UPDATE borrowing_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_notes = ? WHERE id = ?")
               ->execute([$_SESSION['user_id'], post('review_notes'), $id]);
            $db->prepare('UPDATE equipment SET quantity_reserved = quantity_reserved + ? WHERE id = ?')
               ->execute([$req['quantity'], $req['equipment_id']]);
            updateEquipmentQuantities($req['equipment_id']);

            createNotification($req['user_id'], 'Request Approved', "Your request {$req['request_number']} has been approved. Please proceed to checkout.", 'success', BASE_URL . '/requests/view.php?id=' . $id);
            auditLog($_SESSION['user_id'], 'approve_request', 'borrowing_request', $id);
            flash('success', 'Request approved successfully.');
        }
        redirect(BASE_URL . '/requests/view.php?id=' . $id);
    }

    if ($action === 'reject' && canApproveRequests() && $req['status'] === 'pending') {
        $db->prepare("UPDATE borrowing_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_notes = ? WHERE id = ?")
           ->execute([$_SESSION['user_id'], post('review_notes'), $id]);
        createNotification($req['user_id'], 'Request Rejected', "Your request {$req['request_number']} has been rejected.", 'danger', BASE_URL . '/requests/view.php?id=' . $id);
        auditLog($_SESSION['user_id'], 'reject_request', 'borrowing_request', $id);
        flash('success', 'Request rejected.');
        redirect(BASE_URL . '/requests/view.php?id=' . $id);
    }

    if ($action === 'cancel' && $req['user_id'] == $_SESSION['user_id'] && in_array($req['status'], ['pending', 'approved'])) {
        if ($req['status'] === 'approved') {
            $db->prepare('UPDATE equipment SET quantity_reserved = GREATEST(0, quantity_reserved - ?) WHERE id = ?')
               ->execute([$req['quantity'], $req['equipment_id']]);
            updateEquipmentQuantities($req['equipment_id']);
        }
        $db->prepare("UPDATE borrowing_requests SET status = 'cancelled' WHERE id = ?")->execute([$id]);
        flash('success', 'Request cancelled.');
        redirect(BASE_URL . '/requests/view.php?id=' . $id);
    }
}

$pageTitle = 'Request ' . $req['request_number'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1>Request <?= sanitize($req['request_number']) ?></h1>
        <?= statusBadge($req['status']) ?>
    </div>
    <a href="<?= BASE_URL ?>/requests/index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header">Request Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="text-muted small">Equipment</label>
                        <p class="fw-bold"><?= sanitize($req['equipment_name']) ?> (<?= sanitize($req['barcode']) ?>)</p>
                    </div>
                    <div class="col-md-6">
                        <label class="text-muted small">Quantity</label>
                        <p class="fw-bold"><?= $req['quantity'] ?> unit(s)</p>
                    </div>
                    <div class="col-md-6">
                        <label class="text-muted small">Borrow Date</label>
                        <p><?= formatDate($req['borrow_date']) ?></p>
                    </div>
                    <div class="col-md-6">
                        <label class="text-muted small">Return Date</label>
                        <p><?= formatDate($req['return_date']) ?></p>
                    </div>
                    <div class="col-md-6">
                        <label class="text-muted small">Purpose</label>
                        <p><?= purposeLabel($req['purpose']) ?></p>
                    </div>
                    <div class="col-md-6">
                        <label class="text-muted small">Submitted</label>
                        <p><?= formatDateTime($req['created_at']) ?></p>
                    </div>
                    <?php if ($req['purpose_details']): ?>
                    <div class="col-12">
                        <label class="text-muted small">Purpose Details</label>
                        <p><?= sanitize($req['purpose_details']) ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if ($req['reviewed_by']): ?>
                    <div class="col-12 border-top pt-3">
                        <label class="text-muted small">Reviewed By</label>
                        <p><?= sanitize($req['reviewer_first'] . ' ' . $req['reviewer_last']) ?> on <?= formatDateTime($req['reviewed_at']) ?></p>
                        <?php if ($req['review_notes']): ?>
                        <p class="text-muted"><em><?= sanitize($req['review_notes']) ?></em></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($txnList)): ?>
        <div class="card">
            <div class="card-header">Transaction History</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead class="table-light"><tr><th>Type</th><th>Qty</th><th>Processed By</th><th>Date</th><th>Notes</th></tr></thead>
                    <tbody>
                        <?php foreach ($txnList as $txn): ?>
                        <tr>
                            <td><?= ucfirst($txn['transaction_type']) ?></td>
                            <td><?= $txn['quantity'] ?></td>
                            <td><?= sanitize($txn['first_name'] . ' ' . $txn['last_name']) ?></td>
                            <td><?= formatDateTime($txn['transaction_date']) ?></td>
                            <td><?= sanitize($txn['notes'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header">Borrower Information</div>
            <div class="card-body">
                <p><strong><?= sanitize($req['first_name'] . ' ' . $req['last_name']) ?></strong></p>
                <p class="text-muted mb-1"><i class="bi bi-envelope"></i> <?= sanitize($req['email']) ?></p>
                <?php if ($req['student_id']): ?>
                <p class="text-muted mb-1"><i class="bi bi-card-text"></i> <?= sanitize($req['student_id']) ?></p>
                <?php endif; ?>
                <?php if ($req['department']): ?>
                <p class="text-muted"><i class="bi bi-building"></i> <?= sanitize($req['department']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($req['status'] === 'pending' && canApproveRequests()): ?>
        <div class="card mb-4">
            <div class="card-header">Review Request</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label">Review Notes</label>
                        <textarea name="review_notes" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" name="action" value="approve" class="btn btn-success flex-fill"><i class="bi bi-check-lg"></i> Approve</button>
                        <button type="submit" name="action" value="reject" class="btn btn-danger flex-fill"><i class="bi bi-x-lg"></i> Reject</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($req['status'] === 'approved' && canManageInventory()): ?>
        <div class="card mb-4">
            <div class="card-body text-center">
                <a href="<?= BASE_URL ?>/transactions/checkout.php?request_id=<?= $id ?>" class="btn btn-primary w-100"><i class="bi bi-box-arrow-right"></i> Process Checkout</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (in_array($req['status'], ['checked_out', 'overdue']) && canManageInventory()): ?>
        <div class="card mb-4">
            <div class="card-body text-center">
                <a href="<?= BASE_URL ?>/transactions/return.php?request_id=<?= $id ?>" class="btn btn-success w-100"><i class="bi bi-box-arrow-in-left"></i> Process Return</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (in_array($req['status'], ['pending', 'approved']) && $req['user_id'] == $_SESSION['user_id']): ?>
        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <button type="submit" name="action" value="cancel" class="btn btn-outline-danger w-100" data-confirm="Cancel this request?">Cancel Request</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
