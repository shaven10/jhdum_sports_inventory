<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$db = getDB();
$errors = [];
$teams = [];
try {
    $teams = $db->query('SELECT id, name FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    $teams = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $username = post('username');
    $email = post('email');
    $password = post('password');
    $firstName = post('first_name');
    $lastName = post('last_name');
    $role = post('role', 'student');
    $studentId = post('student_id');
    $department = post('department');
    $phone = post('phone');
    $teamId = (int) post('team_id') ?: null;

    if (empty($username) || empty($email) || empty($password) || empty($firstName) || empty($lastName)) {
        $errors[] = 'All required fields must be filled.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if (!array_key_exists($role, getAllRoles())) {
        $errors[] = 'Invalid role selected.';
    }
    if ($role === 'unit_manager' && !$teamId) {
        $errors[] = 'Unit managers must be assigned to a team.';
    }
    if (!in_array($role, ['unit_manager', 'coach'], true)) {
        $teamId = null;
    }

    $check = $db->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $check->execute([$username, $email]);
    if ($check->fetch()) {
        $errors[] = 'Username or email already exists.';
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare('INSERT INTO users (username, email, password, first_name, last_name, student_id, department, phone, role, team_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$username, $email, $hash, $firstName, $lastName, $studentId, $department, $phone, $role, $teamId]);
        $id = (int) $db->lastInsertId();
        syncUserTeamAssignment($id, $role, $teamId);
        auditLog($_SESSION['user_id'], 'create_user', 'user', $id);
        flash('success', 'User created successfully.');
        redirect(BASE_URL . '/users/index.php');
    }
}

$pageTitle = 'Add User';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header"><h1><i class="bi bi-person-plus"></i> Add User</h1></div>

<div class="row"><div class="col-lg-8">
<div class="card"><div class="card-body">
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="POST" id="userForm">
<?= csrfField() ?>
<div class="row g-3">
    <div class="col-md-6"><label class="form-label">First Name *</label><input type="text" name="first_name" class="form-control" required value="<?= sanitize(post('first_name')) ?>"></div>
    <div class="col-md-6"><label class="form-label">Last Name *</label><input type="text" name="last_name" class="form-control" required value="<?= sanitize(post('last_name')) ?>"></div>
    <div class="col-md-6"><label class="form-label">Username *</label><input type="text" name="username" class="form-control" required value="<?= sanitize(post('username')) ?>"></div>
    <div class="col-md-6"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" required value="<?= sanitize(post('email')) ?>"></div>
    <div class="col-md-6"><label class="form-label">Password *</label><input type="password" name="password" class="form-control" required minlength="6"></div>
    <div class="col-md-6"><label class="form-label">Role *</label>
        <select name="role" id="roleSelect" class="form-select">
            <?php foreach (getAllRoles() as $value => $label): ?>
            <option value="<?= $value ?>" <?= post('role', 'student') === $value ? 'selected' : '' ?>><?= sanitize($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-6" id="teamField">
        <label class="form-label" id="teamLabel">Assigned Team</label>
        <select name="team_id" class="form-select">
            <option value="">Select team</option>
            <?php foreach ($teams as $t): ?>
            <option value="<?= $t['id'] ?>" <?= post('team_id') == $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="form-text" id="teamHelp">Unit managers require a team. Coaches: set a home team, then assign them per event under Teams → Event Coaches.</div>
    </div>
    <div class="col-md-6"><label class="form-label">Student ID</label><input type="text" name="student_id" class="form-control" value="<?= sanitize(post('student_id')) ?>"></div>
    <div class="col-md-6"><label class="form-label">Department</label><input type="text" name="department" class="form-control" value="<?= sanitize(post('department')) ?>"></div>
    <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= sanitize(post('phone')) ?>"></div>
</div>
<div class="mt-4"><button type="submit" class="btn btn-primary">Create User</button> <a href="<?= BASE_URL ?>/users/index.php" class="btn btn-outline-secondary">Cancel</a></div>
</form>
</div></div></div></div>

<script>
(function () {
    const role = document.getElementById('roleSelect');
    const teamField = document.getElementById('teamField');
    function toggleTeam() {
        const needsTeam = role.value === 'unit_manager' || role.value === 'coach';
        teamField.style.display = needsTeam ? '' : 'none';
    }
    role.addEventListener('change', toggleTeam);
    toggleTeam();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
