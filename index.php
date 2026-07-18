<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(BASE_URL . '/dashboard.php');
}

redirect(BASE_URL . '/login.php');
