<?php
require_once __DIR__ . '/../includes/auth.php';
requireIncidentSubmitAccess();
ensureIncidentReportsTable();

$id = (int) get('id');
$report = getIncidentReportById($id);

if (!$report || (int) $report['reporter_user_id'] !== (int) $_SESSION['user_id']) {
    flash('error', 'Report not found.');
    redirect(BASE_URL . '/incidents/index.php');
}

$categories = getIncidentCategories();
$statuses = getIncidentStatuses();
$responder = trim(($report['responder_first_name'] ?? '') . ' ' . ($report['responder_last_name'] ?? ''));

$pageTitle = 'Incident Report';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-flag"></i> <?= sanitize($report['subject']) ?></h1>
        <p class="text-muted mb-0">
            <?= statusBadge($report['category']) ?>
            <?= statusBadge($report['status']) ?>
            · Sent <?= formatDateTime($report['created_at']) ?>
        </p>
    </div>
    <a href="<?= BASE_URL ?>/incidents/index.php" class="btn btn-outline-secondary">Back</a>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">Your message</div>
            <div class="card-body">
                <div class="small text-muted mb-2">Type: <?= sanitize($categories[$report['category']] ?? $report['category']) ?></div>
                <div style="white-space: pre-wrap;"><?= sanitize($report['message']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card <?= !empty($report['admin_response']) ? 'border-success' : '' ?>">
            <div class="card-header">Admin response</div>
            <div class="card-body">
                <?php if (!empty($report['admin_response'])): ?>
                <div style="white-space: pre-wrap;"><?= sanitize($report['admin_response']) ?></div>
                <div class="small text-muted mt-3">
                    Status: <?= sanitize($statuses[$report['status']] ?? $report['status']) ?>
                    <?php if ($responder !== ''): ?> · <?= sanitize($responder) ?><?php endif; ?>
                    <?php if (!empty($report['responded_at'])): ?> · <?= formatDateTime($report['responded_at']) ?><?php endif; ?>
                </div>
                <?php else: ?>
                <p class="text-muted mb-0">Waiting for an administrator reply.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
