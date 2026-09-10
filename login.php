<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(getHomeUrl());
}

$error = '';
$loginMode = post('login_mode', get('mode', 'staff'));
if (!in_array($loginMode, ['staff', 'student'], true)) {
    $loginMode = 'staff';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($loginMode === 'student') {
        $studentId = trim(post('student_id'));
        if ($studentId === '') {
            $error = 'Please enter your student ID.';
        } else {
            $result = loginStudentById($studentId);
            if ($result['success']) {
                redirect(getHomeUrl());
            } else {
                $error = $result['message'];
            }
        }
    } else {
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
}

$studentLoginReady = isRegistrarStudentLoginConfigured();
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
            <img src="<?= sanitize(appLogoUrl()) ?>" alt="Sports Development Logo" class="login-brand-logo">
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

            <ul class="nav nav-pills nav-fill login-mode-tabs mb-4" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?= $loginMode === 'staff' ? 'active' : '' ?>" href="?mode=staff" aria-selected="<?= $loginMode === 'staff' ? 'true' : 'false' ?>">
                        <i class="bi bi-person-badge"></i> Staff
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?= $loginMode === 'student' ? 'active' : '' ?>" href="?mode=student" aria-selected="<?= $loginMode === 'student' ? 'true' : 'false' ?>">
                        <i class="bi bi-mortarboard"></i> Student
                    </a>
                </li>
            </ul>

            <?php if ($loginMode === 'student'): ?>
            <form method="POST" action="?mode=student">
                <input type="hidden" name="login_mode" value="student">
                <div class="mb-3">
                    <label for="student_id" class="form-label">Student ID</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-card-text"></i></span>
                        <input type="text" class="form-control" id="student_id" name="student_id" required autofocus
                               placeholder="e.g. 2024-00123" value="<?= sanitize(post('student_id')) ?>"
                               autocomplete="username">
                    </div>
                    <div class="form-text">Active enrolled students can sign in to browse and request sports equipment.</div>
                </div>
                <?php if (!$studentLoginReady): ?>
                <div class="alert alert-warning py-2 small mb-3">
                    Student login is not configured yet. Please contact the Sports Development Office.
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary w-100 py-2 login-submit-btn" <?= $studentLoginReady ? '' : 'disabled' ?>>
                    <i class="bi bi-box-arrow-in-right"></i> Sign in with Student ID
                </button>
            </form>
            <?php else: ?>
            <form method="POST" action="?mode=staff">
                <input type="hidden" name="login_mode" value="staff">
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
                <button type="submit" class="btn btn-primary w-100 py-2 login-submit-btn">
                    <i class="bi bi-box-arrow-in-right"></i> Login
                </button>
            </form>
            <?php endif; ?>

            <p class="text-center mt-3 mb-0">
                <a href="<?= sanitize(getPublicStandingsUrl()) ?>" class="text-decoration-none">
                    <i class="bi bi-<?= isLiveBoardEnabled() ? 'broadcast' : 'house' ?>"></i> <?= isLiveBoardEnabled() ? 'View live rankings' : 'View intramurals home' ?>
                </a>
            </p>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
