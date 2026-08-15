<?php
require_once __DIR__ . '/../includes/auth.php';
requireIncidentSubmitAccess();
ensureIncidentReportsTable();

$userId = (int) $_SESSION['user_id'];
$errors = [];
$categories = getIncidentCategories();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $result = submitIncidentReport(
        $userId,
        post('subject'),
        post('message'),
        post('category', 'query')
    );
    if ($result['success']) {
        flash('success', $result['message'] ?? 'Report submitted.');
        redirect(BASE_URL . '/incidents/view.php?id=' . (int) $result['id']);
    }
    $errors[] = $result['message'] ?? 'Could not submit report.';
}

$reports = getIncidentReportsForUser($userId);

$pageTitle = 'Incident Reports';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-flag"></i> Incident Reports</h1>
        <p class="text-muted mb-0">Send queries and incident reports to the administrator. You will be notified when they reply.</p>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><i class="bi bi-plus-circle"></i> New Report / Query</div>
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
                </div>
                <?php endif; ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label" for="category">Type *</label>
                        <select name="category" id="category" class="form-select" required>
                            <?php foreach ($categories as $value => $label): ?>
                            <option value="<?= sanitize($value) ?>" <?= post('category', 'query') === $value ? 'selected' : '' ?>><?= sanitize($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="subject">Subject *</label>
                        <input type="text" name="subject" id="subject" class="form-control" required maxlength="200" value="<?= sanitize(post('subject')) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="message">Details *</label>
                        <textarea name="message" id="message" class="form-control" rows="6" required placeholder="Describe the issue, question, or incident for the administrator."><?= sanitize(post('message')) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Send to Admin</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">My Reports</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Subject</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Sent</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reports)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No reports yet.</td></tr>
                            <?php else: ?>
                            <?php foreach ($reports as $r): ?>
                            <tr>
                                <td>
                                    <a href="<?= BASE_URL ?>/incidents/view.php?id=<?= (int) $r['id'] ?>" class="fw-semibold text-decoration-none"><?= sanitize($r['subject']) ?></a>
                                    <div class="small text-muted text-truncate" style="max-width: 260px;"><?= sanitize($r['message']) ?></div>
                                </td>
                                <td><?= statusBadge($r['category']) ?></td>
                                <td><?= statusBadge($r['status']) ?></td>
                                <td class="small text-nowrap"><?= formatDateTime($r['created_at']) ?></td>
                                <td class="text-end"><a href="<?= BASE_URL ?>/incidents/view.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-primary">Open</a></td>
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
