<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole(['admin']);
ensureAnnouncementsTable();

$db = getDB();
$errors = [];
$roleOptions = getAnnouncementTargetRoleOptions();
$selectedRoles = array_keys($roleOptions);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $action = post('action', 'publish');

    if ($action === 'publish') {
        $postedRoles = $_POST['roles'] ?? [];
        if (!is_array($postedRoles)) {
            $postedRoles = [];
        }
        $selectedRoles = normalizeAnnouncementRoles($postedRoles);

        $result = publishAnnouncement(
            post('title'),
            post('message'),
            post('type', 'info'),
            (int) $_SESSION['user_id'],
            $selectedRoles
        );
        if ($result['success']) {
            flash('success', $result['message'] ?? 'Announcement published.');
            redirect(BASE_URL . '/admin/announcements/index.php');
        }
        $errors[] = $result['message'] ?? 'Could not publish announcement.';
    }

    if ($action === 'deactivate') {
        $id = (int) post('announcement_id');
        if ($id && setAnnouncementActive($id, false)) {
            auditLog((int) $_SESSION['user_id'], 'deactivate_announcement', 'announcement', $id);
            flash('success', 'Announcement deactivated.');
        } else {
            flash('error', 'Could not deactivate announcement.');
        }
        redirect(BASE_URL . '/admin/announcements/index.php');
    }

    if ($action === 'activate') {
        $id = (int) post('announcement_id');
        if ($id && setAnnouncementActive($id, true)) {
            auditLog((int) $_SESSION['user_id'], 'activate_announcement', 'announcement', $id);
            flash('success', 'Announcement reactivated.');
        } else {
            flash('error', 'Could not reactivate announcement.');
        }
        redirect(BASE_URL . '/admin/announcements/index.php');
    }
}

$announcements = $db->query("SELECT a.*, u.first_name, u.last_name
    FROM announcements a
    LEFT JOIN users u ON a.created_by = u.id
    ORDER BY a.created_at DESC
    LIMIT 100")->fetchAll();

$roleCounts = [];
try {
    $countRows = $db->query("SELECT role, COUNT(*) AS cnt FROM users WHERE is_active = 1 AND role <> 'student' GROUP BY role")->fetchAll();
    foreach ($countRows as $row) {
        $roleCounts[(string) $row['role']] = (int) $row['cnt'];
    }
} catch (Throwable $e) {
    $roleCounts = [];
}

$pageTitle = 'Announcements';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-megaphone"></i> Announcements</h1>
        <p class="text-muted mb-0">Choose which roles receive each announcement. Students are never included. Recipients also get a notification.</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-outline-secondary">Back to Admin</a>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><i class="bi bi-plus-circle"></i> New Announcement</div>
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
                </div>
                <?php endif; ?>
                <form method="POST" id="announcementForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="publish">
                    <div class="mb-3">
                        <label class="form-label" for="title">Title *</label>
                        <input type="text" name="title" id="title" class="form-control" required maxlength="200" value="<?= sanitize(post('title')) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="message">Message *</label>
                        <textarea name="message" id="message" class="form-control" rows="5" required><?= sanitize(post('message')) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="type">Priority</label>
                        <select name="type" id="type" class="form-select">
                            <?php foreach (['info' => 'Info', 'success' => 'Success', 'warning' => 'Warning', 'danger' => 'Urgent'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= post('type', 'info') === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label mb-0">Send to roles *</label>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary" id="selectAllRoles">All</button>
                                <button type="button" class="btn btn-outline-secondary" id="clearRoles">None</button>
                            </div>
                        </div>
                        <div class="border rounded p-2" style="max-height: 220px; overflow-y: auto;">
                            <?php foreach ($roleOptions as $value => $label): ?>
                            <div class="form-check">
                                <input class="form-check-input announcement-role" type="checkbox" name="roles[]" value="<?= sanitize($value) ?>" id="role_<?= sanitize($value) ?>"
                                    <?= in_array($value, $selectedRoles, true) ? 'checked' : '' ?>
                                    data-count="<?= (int) ($roleCounts[$value] ?? 0) ?>">
                                <label class="form-check-label" for="role_<?= sanitize($value) ?>">
                                    <?= sanitize($label) ?>
                                    <span class="text-muted small">(<?= (int) ($roleCounts[$value] ?? 0) ?>)</span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text" id="recipientEstimate">Select at least one role.</div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send"></i> Publish &amp; Notify
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history"></i> Recent Announcements</span>
                <a href="<?= BASE_URL ?>/announcements/index.php" class="btn btn-sm btn-outline-primary">Staff view</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Title</th>
                                <th>Roles</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Sent</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($announcements)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No announcements yet.</td></tr>
                            <?php else: ?>
                            <?php foreach ($announcements as $a): ?>
                            <tr>
                                <td>
                                    <a href="<?= BASE_URL ?>/announcements/view.php?id=<?= (int) $a['id'] ?>" class="fw-semibold text-decoration-none">
                                        <?= sanitize($a['title']) ?>
                                    </a>
                                    <div class="small text-muted text-truncate" style="max-width: 220px;"><?= sanitize($a['message']) ?></div>
                                </td>
                                <td class="small" style="max-width: 160px;"><?= sanitize(formatAnnouncementRoles($a)) ?></td>
                                <td><?= statusBadge($a['type']) ?></td>
                                <td>
                                    <?php if (!empty($a['is_active'])): ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-nowrap">
                                    <?= formatDateTime($a['created_at']) ?>
                                    <?php if (!empty($a['first_name'])): ?>
                                    <div class="text-muted"><?= sanitize(trim($a['first_name'] . ' ' . ($a['last_name'] ?? ''))) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="announcement_id" value="<?= (int) $a['id'] ?>">
                                        <?php if (!empty($a['is_active'])): ?>
                                        <input type="hidden" name="action" value="deactivate">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Deactivate</button>
                                        <?php else: ?>
                                        <input type="hidden" name="action" value="activate">
                                        <button type="submit" class="btn btn-sm btn-outline-success">Activate</button>
                                        <?php endif; ?>
                                    </form>
                                </td>
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

<script>
(function () {
    const boxes = Array.from(document.querySelectorAll('.announcement-role'));
    const estimate = document.getElementById('recipientEstimate');
    const selectAll = document.getElementById('selectAllRoles');
    const clearAll = document.getElementById('clearRoles');

    function updateEstimate() {
        let total = 0;
        let selected = 0;
        boxes.forEach(function (box) {
            if (box.checked) {
                selected += 1;
                total += parseInt(box.getAttribute('data-count') || '0', 10);
            }
        });
        if (!selected) {
            estimate.textContent = 'Select at least one role.';
            return;
        }
        estimate.textContent = 'Will notify about ' + total + ' active account' + (total === 1 ? '' : 's') + ' across ' + selected + ' role' + (selected === 1 ? '' : 's') + '.';
    }

    boxes.forEach(function (box) {
        box.addEventListener('change', updateEstimate);
    });
    if (selectAll) {
        selectAll.addEventListener('click', function () {
            boxes.forEach(function (box) { box.checked = true; });
            updateEstimate();
        });
    }
    if (clearAll) {
        clearAll.addEventListener('click', function () {
            boxes.forEach(function (box) { box.checked = false; });
            updateEstimate();
        });
    }
    updateEstimate();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
