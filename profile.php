<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = getCurrentUser();
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $action = post('action', 'profile');

    if ($action === 'profile') {
        $firstName = post('first_name');
        $lastName = post('last_name');
        $email = post('email');
        $phone = post('phone');
        $department = post('department');
        $studentId = post('student_id');

        $stmt = getDB()->prepare('UPDATE users SET first_name=?, last_name=?, email=?, phone=?, department=?, student_id=? WHERE id=?');
        $stmt->execute([$firstName, $lastName, $email, $phone, $department, $studentId, $user['id']]);
        $_SESSION['user_name'] = $firstName . ' ' . $lastName;
        flash('success', 'Profile updated successfully.');
        redirect(BASE_URL . '/profile.php');
    }

    if ($action === 'password') {
        $current = post('current_password');
        $newPass = post('new_password');
        $confirm = post('confirm_password');

        if (!password_verify($current, $user['password'])) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($newPass) < 6) {
            $errors[] = 'New password must be at least 6 characters.';
        } elseif ($newPass !== $confirm) {
            $errors[] = 'Passwords do not match.';
        } else {
            getDB()->prepare('UPDATE users SET password = ?, password_plain = ? WHERE id = ?')->execute([password_hash($newPass, PASSWORD_DEFAULT), $newPass, $user['id']]);
            flash('success', 'Password changed successfully.');
            redirect(BASE_URL . '/profile.php');
        }
    }
}

$user = getCurrentUser();
$pageTitle = 'My Profile';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header"><h1><i class="bi bi-person"></i> My Profile</h1></div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Profile Information</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="profile">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">First Name</label><input type="text" name="first_name" class="form-control" value="<?= sanitize($user['first_name']) ?>" required></div>
                        <div class="col-md-6"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" value="<?= sanitize($user['last_name']) ?>" required></div>
                        <div class="col-md-6"><label class="form-label">Username</label><input type="text" class="form-control" value="<?= sanitize($user['username']) ?>" disabled></div>
                        <div class="col-md-6"><label class="form-label">Role</label><input type="text" class="form-control" value="<?= sanitize(roleLabel($user['role'])) ?>" disabled></div>
                        <?php if (!empty($user['team_name'])): ?>
                        <div class="col-md-6"><label class="form-label">Assigned Team</label><input type="text" class="form-control" value="<?= sanitize($user['team_name']) ?>" disabled></div>
                        <?php endif; ?>
                        <div class="col-12"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= sanitize($user['email']) ?>" required></div>
                        <div class="col-md-6"><label class="form-label">Student ID</label><input type="text" name="student_id" class="form-control" value="<?= sanitize($user['student_id'] ?? '') ?>"></div>
                        <div class="col-md-6"><label class="form-label">Department</label><input type="text" name="department" class="form-control" value="<?= sanitize($user['department'] ?? '') ?>"></div>
                        <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= sanitize($user['phone'] ?? '') ?>"></div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-3">Save Profile</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Change Password</div>
            <div class="card-body">
                <?php if ($errors): ?><div class="alert alert-danger"><?= sanitize($errors[0]) ?></div><?php endif; ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="password">
                    <div class="mb-3"><label class="form-label">Current Password</label><input type="password" name="current_password" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control" required minlength="6"></div>
                    <div class="mb-3"><label class="form-label">Confirm Password</label><input type="password" name="confirm_password" class="form-control" required></div>
                    <button type="submit" class="btn btn-warning">Change Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
