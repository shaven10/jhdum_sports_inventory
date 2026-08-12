<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(getHomeUrl());
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
            redirect(getHomeUrl());
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
    <?= renderThemeStyles() ?>
</head>
<body>
<div class="login-page">
    <div class="login-card mx-auto">
        <div class="login-header">
            <img src="<?= sanitize(appLogoUrl()) ?>" alt="JHCSC Logo" class="login-brand-logo">
            <h2 class="mt-3"><?= sanitize(APP_NAME) ?></h2>
            <p class="mb-0"><?= sanitize(APP_CAMPUS) ?></p>
            <p class="login-tagline"><?= sanitize(APP_TAGLINE) ?></p>
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

       
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
