<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$db = getDB();
$equipmentId = (int) get('equipment_id');
$errors = [];

$equipment = null;
if ($equipmentId) {
    $stmt = $db->prepare('SELECT e.*, c.name as category_name FROM equipment e JOIN equipment_categories c ON e.category_id = c.id WHERE e.id = ? AND e.is_active = 1');
    $stmt->execute([$equipmentId]);
    $equipment = $stmt->fetch();
}

if (!$equipment) {
    flash('error', 'Please select equipment to borrow.');
    redirect(BASE_URL . '/equipment/index.php');
}

$maxBorrowDays = getSetting('max_borrow_days', MAX_BORROW_DAYS);
$maxBorrowItems = getSetting('max_borrow_items', MAX_BORROW_ITEMS);
$defaultDays = getSetting('default_borrow_days', DEFAULT_BORROW_DAYS);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/requests/create.php?equipment_id=' . $equipmentId);
    }

    $quantity = max(1, (int) post('quantity'));
    $borrowDate = post('borrow_date');
    $returnDate = post('return_date');
    $purpose = post('purpose');
    $purposeDetails = post('purpose_details');

    if ($quantity > $equipment['quantity_available']) {
        $errors[] = 'Requested quantity exceeds available stock.';
    }
    if ($quantity > $maxBorrowItems) {
        $errors[] = "Maximum $maxBorrowItems items allowed per request.";
    }
    if (empty($borrowDate) || empty($returnDate)) {
        $errors[] = 'Borrow and return dates are required.';
    }
    if ($returnDate < $borrowDate) {
        $errors[] = 'Return date must be after borrow date.';
    }
    $daysDiff = (strtotime($returnDate) - strtotime($borrowDate)) / 86400;
    if ($daysDiff > $maxBorrowDays) {
        $errors[] = "Maximum borrowing period is $maxBorrowDays days.";
    }
    if (empty($purpose)) {
        $errors[] = 'Purpose is required.';
    }

    $activeCount = $db->prepare("SELECT COUNT(*) FROM borrowing_requests WHERE user_id = ? AND status IN ('pending', 'approved', 'checked_out', 'overdue')");
    $activeCount->execute([$_SESSION['user_id']]);
    if ((int) $activeCount->fetchColumn() >= $maxBorrowItems) {
        $errors[] = 'You have reached the maximum number of active borrowing requests.';
    }

    if (empty($errors)) {
        $requestNumber = generateRequestNumber();
        $stmt = $db->prepare('INSERT INTO borrowing_requests (request_number, user_id, equipment_id, quantity, borrow_date, return_date, purpose, purpose_details, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$requestNumber, $_SESSION['user_id'], $equipmentId, $quantity, $borrowDate, $returnDate, $purpose, $purposeDetails, 'pending']);

        $requestId = (int) $db->lastInsertId();

        createNotification(
            $_SESSION['user_id'],
            'Request Submitted',
            "Your borrowing request $requestNumber has been submitted and is pending approval.",
            'info',
            BASE_URL . '/requests/view.php?id=' . $requestId
        );

        $coordinators = $db->query("SELECT id FROM users WHERE role IN ('admin', 'coordinator') AND is_active = 1")->fetchAll();
        foreach ($coordinators as $coord) {
            createNotification(
                $coord['id'],
                'New Borrowing Request',
                "New request $requestNumber from {$_SESSION['user_name']} for {$equipment['name']}.",
                'warning',
                BASE_URL . '/requests/view.php?id=' . $requestId
            );
        }

        auditLog($_SESSION['user_id'], 'create_request', 'borrowing_request', $requestId);
        flash('success', 'Borrowing request submitted successfully.');
        redirect(BASE_URL . '/requests/view.php?id=' . $requestId);
    }
}

$pageTitle = 'Request Equipment';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="bi bi-clipboard-plus"></i> Request Equipment</h1>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Selected Equipment</div>
            <div class="card-body">
                <h5><?= sanitize($equipment['name']) ?></h5>
                <p class="text-muted"><?= sanitize($equipment['category_name']) ?></p>
                <p><strong>Available:</strong> <?= $equipment['quantity_available'] ?> units</p>
                <p><?= sanitize($equipment['description']) ?></p>
            </div>
        </div>
        <div class="card mt-3">
            <div class="card-body">
                <h6>Borrowing Policy</h6>
                <p class="small text-muted"><?= sanitize(getSetting('borrowing_policy', '')) ?></p>
                <ul class="small text-muted">
                    <li>Max borrowing period: <?= $maxBorrowDays ?> days</li>
                    <li>Max items per request: <?= $maxBorrowItems ?></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" id="max_quantity" value="<?= min($equipment['quantity_available'], $maxBorrowItems) ?>">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Quantity *</label>
                            <input type="number" name="quantity" id="quantity" class="form-control" min="1" max="<?= min($equipment['quantity_available'], $maxBorrowItems) ?>" value="1" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Borrow Date *</label>
                            <input type="date" name="borrow_date" id="borrow_date" class="form-control" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Return Date *</label>
                            <input type="date" name="return_date" id="return_date" class="form-control" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime("+$defaultDays days")) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Purpose *</label>
                            <select name="purpose" class="form-select" required>
                                <option value="">Select Purpose</option>
                                <option value="pe_class">PE Class</option>
                                <option value="training">Training</option>
                                <option value="tournament">Tournament</option>
                                <option value="practice">Practice</option>
                                <option value="event">Event</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Purpose Details</label>
                            <textarea name="purpose_details" class="form-control" rows="3" placeholder="Provide additional details about your borrowing purpose..."></textarea>
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Submit Request</button>
                        <a href="<?= BASE_URL ?>/equipment/index.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
