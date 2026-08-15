<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIncidentManageAccess();
ensureIncidentReportsTable();

$id = (int) get('id');
$report = getIncidentReportById($id);
$errors = [];

if (!$report) {
    flash('error', 'Report not found.');
    redirect(BASE_URL . '/admin/incidents/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $result = respondToIncidentReport(
        $id,
        (int) $_SESSION['user_id'],
        post('admin_response'),
        post('status', 'resolved')
    );
    if ($result['success']) {
        flash('success', $result['message'] ?? 'Response saved.');
        redirect(BASE_URL . '/admin/incidents/view.php?id=' . $id);
    }
    $errors[] = $result['message'] ?? 'Could not save response.';
    $report = getIncidentReportById($id) ?: $report;
}

$categories = getIncidentCategories();
$statuses = getIncidentStatuses();
$reporterName = trim(($report['reporter_first_name'] ?? '') . ' ' . ($report['reporter_last_name'] ?? ''));
$responder = trim(($report['responder_first_name'] ?? '') . ' ' . ($report['responder_last_name'] ?? ''));

$pageTitle = 'Review Incident Report';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-flag"></i> <?= sanitize($report['subject']) ?></h1>
        <p class="text-muted mb-0">
            <?= statusBadge($report['category']) ?>
            <?= statusBadge($report['status']) ?>
            · From <?= sanitize($reporterName ?: ($report['reporter_username'] ?? 'User')) ?>
            (<?= sanitize(roleLabel((string) ($report['reporter_role'] ?? ''))) ?>)
        </p>
    </div>
    <a href="<?= BASE_URL ?>/admin/incidents/index.php" class="btn btn-outline-secondary">Back</a>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Reporter message</div>
            <div class="card-body">
                <dl class="row small mb-3">
                    <dt class="col-4">Email</dt>
                    <dd class="col-8"><?= sanitize($report['reporter_email'] ?? '—') ?></dd>
                    <dt class="col-4">Type</dt>
                    <dd class="col-8"><?= sanitize($categories[$report['category']] ?? $report['category']) ?></dd>
                    <dt class="col-4">Submitted</dt>
                    <dd class="col-8"><?= formatDateTime($report['created_at']) ?></dd>
                </dl>
                <div class="fs-6" style="white-space: pre-wrap;"><?= sanitize($report['message']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Admin response</div>
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
                </div>
                <?php endif; ?>

                <?php if (!empty($report['admin_response'])): ?>
                <div class="alert alert-light border mb-3">
                    <div style="white-space: pre-wrap;"><?= sanitize($report['admin_response']) ?></div>
                    <div class="small text-muted mt-2">
                        <?= sanitize($statuses[$report['status']] ?? $report['status']) ?>
                        <?php if ($responder !== ''): ?> · <?= sanitize($responder) ?><?php endif; ?>
                        <?php if (!empty($report['responded_at'])): ?> · <?= formatDateTime($report['responded_at']) ?><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label" for="status">Status</label>
                        <select name="status" id="status" class="form-select">
                            <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?= sanitize($value) ?>" <?= post('status', $report['status']) === $value ? 'selected' : '' ?>><?= sanitize($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="admin_response">Reply to reporter *</label>
                        <textarea name="admin_response" id="admin_response" class="form-control" rows="6" required><?= sanitize(post('admin_response', (string) ($report['admin_response'] ?? ''))) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-reply"></i> Send Response</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
