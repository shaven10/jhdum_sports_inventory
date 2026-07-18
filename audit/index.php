<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$db = getDB();
$action = get('action');
$userId = get('user_id');

$where = ['1=1'];
$params = [];
if ($action) {
    $where[] = 'al.action = ?';
    $params[] = $action;
}
if ($userId) {
    $where[] = 'al.user_id = ?';
    $params[] = $userId;
}

$whereClause = implode(' AND ', $where);
$stmt = $db->prepare("
    SELECT al.*, u.first_name, u.last_name, u.username
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE $whereClause
    ORDER BY al.created_at DESC LIMIT 100
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$loginHistory = $db->query("
    SELECT lh.*, u.username, u.first_name, u.last_name
    FROM login_history lh
    JOIN users u ON lh.user_id = u.id
    ORDER BY lh.created_at DESC LIMIT 50
")->fetchAll();

$pageTitle = 'Audit Logs';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header"><h1><i class="bi bi-journal-text"></i> Audit Logs</h1></div>

<ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#activity">Activity Logs</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#login">Login History</a></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="activity">
        <div class="filter-bar mb-3">
            <form method="GET" class="d-flex gap-2">
                <select name="action" class="form-select" style="max-width:250px">
                    <option value="">All Actions</option>
                    <?php foreach (['login', 'logout', 'create', 'update', 'delete', 'create_request', 'approve_request', 'reject_request', 'checkout', 'return'] as $a): ?>
                    <option value="<?= $a ?>" <?= $action === $a ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $a)) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">Filter</button>
            </form>
        </div>
        <div class="card">
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>Date</th><th>User</th><th>Action</th><th>Entity</th><th>IP</th></tr></thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= formatDateTime($log['created_at']) ?></td>
                            <td><?= $log['username'] ? sanitize($log['first_name'] . ' ' . $log['last_name']) : 'System' ?></td>
                            <td><span class="badge bg-secondary"><?= sanitize($log['action']) ?></span></td>
                            <td><?= sanitize($log['entity_type'] ?? '-') ?> #<?= $log['entity_id'] ?? '-' ?></td>
                            <td><small><?= sanitize($log['ip_address'] ?? '-') ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="tab-pane fade" id="login">
        <div class="card">
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>Date</th><th>User</th><th>Status</th><th>IP Address</th></tr></thead>
                    <tbody>
                        <?php foreach ($loginHistory as $lh): ?>
                        <tr>
                            <td><?= formatDateTime($lh['created_at']) ?></td>
                            <td><?= sanitize($lh['first_name'] . ' ' . $lh['last_name']) ?> (<?= sanitize($lh['username']) ?>)</td>
                            <td><?= $lh['status'] === 'success' ? '<span class="badge bg-success">Success</span>' : '<span class="badge bg-danger">Failed</span>' ?></td>
                            <td><small><?= sanitize($lh['ip_address'] ?? '-') ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
