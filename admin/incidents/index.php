<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIncidentManageAccess();
ensureIncidentReportsTable();

$statusFilter = get('status');
$reports = getIncidentReportsForAdmin($statusFilter !== '' ? $statusFilter : null);
$openCount = countOpenIncidentReports();
$statuses = getIncidentStatuses();
$categories = getIncidentCategories();

$pageTitle = 'Incident Reports';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-flag"></i> Incident Reports</h1>
        <p class="text-muted mb-0">Queries and reports from tournament managers and unit managers. <?= (int) $openCount ?> open.</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-outline-secondary">Back to Admin</a>
</div>

<div class="filter-bar mb-3">
    <form method="GET" class="d-flex flex-wrap gap-2 align-items-end">
        <div>
            <label class="form-label">Status</label>
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach ($statuses as $value => $label): ?>
                <option value="<?= sanitize($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= sanitize($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Subject</th>
                        <th>From</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Sent</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reports)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No incident reports found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($reports as $r): ?>
                    <?php
                    $reporterName = trim(($r['reporter_first_name'] ?? '') . ' ' . ($r['reporter_last_name'] ?? ''));
                    $roleLabel = roleLabel((string) ($r['reporter_role'] ?? ''));
                    ?>
                    <tr class="<?= in_array($r['status'], ['open', 'in_progress'], true) ? '' : 'table-light' ?>">
                        <td>
                            <a href="<?= BASE_URL ?>/admin/incidents/view.php?id=<?= (int) $r['id'] ?>" class="fw-semibold text-decoration-none"><?= sanitize($r['subject']) ?></a>
                            <div class="small text-muted text-truncate" style="max-width: 280px;"><?= sanitize($r['message']) ?></div>
                        </td>
                        <td>
                            <?= sanitize($reporterName ?: ($r['reporter_username'] ?? 'User')) ?>
                            <div class="small text-muted"><?= sanitize($roleLabel) ?></div>
                        </td>
                        <td><?= statusBadge($r['category']) ?></td>
                        <td><?= statusBadge($r['status']) ?></td>
                        <td class="small text-nowrap"><?= formatDateTime($r['created_at']) ?></td>
                        <td class="text-end"><a href="<?= BASE_URL ?>/admin/incidents/view.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-primary">Review</a></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
