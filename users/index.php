<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$db = getDB();
$search = get('search');
$role = get('role');

$where = ['1=1'];
$params = [];
if ($search) {
    $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}
if ($role) {
    $where[] = 'u.role = ?';
    $params[] = $role;
}

$whereClause = implode(' AND ', $where);
$stmt = $db->prepare("SELECT u.* FROM users u WHERE $whereClause ORDER BY u.created_at DESC");
$stmt->execute($params);
$users = $stmt->fetchAll();

$pageTitle = 'User Management';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <h1><i class="bi bi-people"></i> User Management</h1>
    <a href="<?= BASE_URL ?>/users/add.php" class="btn btn-primary"><i class="bi bi-person-plus"></i> Add User</a>
</div>

<div class="filter-bar mb-4">
    <form method="GET" class="row g-2">
        <div class="col-md-4"><input type="text" name="search" class="form-control" placeholder="Search users..." value="<?= sanitize($search) ?>"></div>
        <div class="col-md-3">
            <select name="role" class="form-select">
                <option value="">All Roles</option>
                <?php foreach (['admin', 'coordinator', 'staff', 'student'] as $r): ?>
                <option value="<?= $r ?>" <?= $role === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary">Search</button></div>
    </form>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= sanitize($user['first_name'] . ' ' . $user['last_name']) ?></td>
                        <td><?= sanitize($user['username']) ?></td>
                        <td><?= sanitize($user['email']) ?></td>
                        <td><?= roleBadge($user['role']) ?></td>
                        <td><?= $user['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                        <td>
                            <a href="<?= BASE_URL ?>/users/edit.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
