<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(BASE_URL . '/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = post('username');
    $password = post('password');

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $result = login($username, $password);
        if ($result['success']) {
            redirect(BASE_URL . '/dashboard.php');
        } else {
            $error = $result['message'];
        }
    }
}

$pageTitle = 'Login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="login-page">
    <div class="login-card">
        <div class="login-header">
            <i class="bi bi-trophy" style="font-size: 3rem;"></i>
            <h2 class="mt-2"><?= APP_NAME ?></h2>
            <p><?= APP_CAMPUS ?></p>
        </div>
        <div class="p-4">
            <?php if ($error): ?>
            <div class="alert alert-danger"><?= sanitize($error) ?></div>
            <?php endif; ?>

            <?php $flash = getFlash(); if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?>"><?= sanitize($flash['message']) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="mb-3">
                    <label for="username" class="form-label">Username or Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" class="form-control" id="username" name="username" required autofocus value="<?= sanitize(post('username')) ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2">
                    <i class="bi bi-box-arrow-in-right"></i> Login
                </button>
            </form>

            <div class="mt-4 p-3 bg-light rounded">
                <small class="text-muted d-block mb-2"><strong>Demo Accounts:</strong></small>
                <small class="text-muted">admin / admin123</small><br>
                <small class="text-muted">coordinator / admin123</small><br>
                <small class="text-muted">staff / admin123</small><br>
                <small class="text-muted">student / admin123</small>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
