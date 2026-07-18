<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$db = getDB();
$errors = [];

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

    if (empty($username) || empty($email) || empty($password) || empty($firstName) || empty($lastName)) {
        $errors[] = 'All required fields must be filled.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }

    $check = $db->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $check->execute([$username, $email]);
    if ($check->fetch()) {
        $errors[] = 'Username or email already exists.';
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare('INSERT INTO users (username, email, password, first_name, last_name, student_id, department, phone, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$username, $email, $hash, $firstName, $lastName, $studentId, $department, $phone, $role]);
        auditLog($_SESSION['user_id'], 'create_user', 'user', (int) $db->lastInsertId());
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
<form method="POST">
<?= csrfField() ?>
<div class="row g-3">
    <div class="col-md-6"><label class="form-label">First Name *</label><input type="text" name="first_name" class="form-control" required></div>
    <div class="col-md-6"><label class="form-label">Last Name *</label><input type="text" name="last_name" class="form-control" required></div>
    <div class="col-md-6"><label class="form-label">Username *</label><input type="text" name="username" class="form-control" required></div>
    <div class="col-md-6"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" required></div>
    <div class="col-md-6"><label class="form-label">Password *</label><input type="password" name="password" class="form-control" required minlength="6"></div>
    <div class="col-md-6"><label class="form-label">Role *</label>
        <select name="role" class="form-select">
            <option value="student">Student</option>
            <option value="staff">Staff</option>
            <option value="coordinator">Sports Coordinator</option>
            <option value="admin">Administrator</option>
        </select>
    </div>
    <div class="col-md-6"><label class="form-label">Student ID</label><input type="text" name="student_id" class="form-control"></div>
    <div class="col-md-6"><label class="form-label">Department</label><input type="text" name="department" class="form-control"></div>
    <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
</div>
<div class="mt-4"><button type="submit" class="btn btn-primary">Create User</button> <a href="<?= BASE_URL ?>/users/index.php" class="btn btn-outline-secondary">Cancel</a></div>
</form>
</div></div></div></div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
