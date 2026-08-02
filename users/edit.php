<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$id = (int) get('id');
$db = getDB();
$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    flash('error', 'User not found.');
    redirect(BASE_URL . '/users/index.php');
}

$teams = [];
try {
    $teams = $db->query('SELECT id, name FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    $teams = [];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $firstName = post('first_name');
    $lastName = post('last_name');
    $email = post('email');
    $role = post('role');
    $studentId = post('student_id');
    $department = post('department');
    $phone = post('phone');
    $isActive = post('is_active') ? 1 : 0;
    $password = post('password');
    $teamId = (int) post('team_id') ?: null;

    if (!array_key_exists($role, getAllRoles())) {
        $errors[] = 'Invalid role selected.';
    }
    if ($role === 'unit_manager' && !$teamId) {
        $errors[] = 'Unit managers must be assigned to a team.';
    }
    if (!in_array($role, ['unit_manager', 'coach'], true)) {
        $teamId = null;
    }

    if (empty($errors)) {
        $stmt = $db->prepare('UPDATE users SET first_name=?, last_name=?, email=?, role=?, student_id=?, department=?, phone=?, is_active=?, team_id=? WHERE id=?');
        $stmt->execute([$firstName, $lastName, $email, $role, $studentId, $department, $phone, $isActive, $teamId, $id]);

        if (!empty($password)) {
            if (strlen($password) >= 6) {
                $db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            } else {
                $errors[] = 'Password must be at least 6 characters.';
            }
        }
    }

    if (empty($errors)) {
        syncUserTeamAssignment($id, $role, $teamId);
        auditLog($_SESSION['user_id'], 'update_user', 'user', $id);
        flash('success', 'User updated successfully.');
        redirect(BASE_URL . '/users/index.php');
    }

    $user = array_merge($user, [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'role' => $role,
        'student_id' => $studentId,
        'department' => $department,
        'phone' => $phone,
        'is_active' => $isActive,
        'team_id' => $teamId,
    ]);
}

$pageTitle = 'Edit User';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header"><h1><i class="bi bi-pencil"></i> Edit User</h1></div>

<div class="row"><div class="col-lg-8">
<div class="card"><div class="card-body">
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="POST">
<?= csrfField() ?>
<div class="row g-3">
    <div class="col-md-6"><label class="form-label">First Name</label><input type="text" name="first_name" class="form-control" value="<?= sanitize($user['first_name']) ?>" required></div>
    <div class="col-md-6"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" value="<?= sanitize($user['last_name']) ?>" required></div>
    <div class="col-md-6"><label class="form-label">Username</label><input type="text" class="form-control" value="<?= sanitize($user['username']) ?>" disabled></div>
    <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= sanitize($user['email']) ?>" required></div>
    <div class="col-md-6"><label class="form-label">New Password (leave blank to keep)</label><input type="password" name="password" class="form-control" minlength="6"></div>
    <div class="col-md-6"><label class="form-label">Role</label>
        <select name="role" id="roleSelect" class="form-select">
            <?php foreach (getAllRoles() as $value => $label): ?>
            <option value="<?= $value ?>" <?= $user['role'] === $value ? 'selected' : '' ?>><?= sanitize($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-6" id="teamField">
        <label class="form-label">Home / Assigned Team</label>
        <select name="team_id" class="form-select">
            <option value="">Select team</option>
            <?php foreach ($teams as $t): ?>
            <option value="<?= $t['id'] ?>" <?= (int) ($user['team_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="form-text">Required for Unit Manager. For Coaches, assign events under Teams → Event Coaches.</div>
    </div>
    <div class="col-md-6"><label class="form-label">Student ID</label><input type="text" name="student_id" class="form-control" value="<?= sanitize($user['student_id'] ?? '') ?>"></div>
    <div class="col-md-6"><label class="form-label">Department</label><input type="text" name="department" class="form-control" value="<?= sanitize($user['department'] ?? '') ?>"></div>
    <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= sanitize($user['phone'] ?? '') ?>"></div>
    <div class="col-md-6"><div class="form-check mt-4"><input type="checkbox" name="is_active" class="form-check-input" id="active" <?= $user['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="active">Active</label></div></div>
</div>
<div class="mt-4"><button type="submit" class="btn btn-primary">Update</button> <a href="<?= BASE_URL ?>/users/index.php" class="btn btn-outline-secondary">Cancel</a></div>
</form>
</div></div></div></div>

<script>
(function () {
    const role = document.getElementById('roleSelect');
    const teamField = document.getElementById('teamField');
    function toggleTeam() {
        teamField.style.display = (role.value === 'unit_manager' || role.value === 'coach') ? '' : 'none';
    }
    role.addEventListener('change', toggleTeam);
    toggleTeam();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
