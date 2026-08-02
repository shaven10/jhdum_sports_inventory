<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(getHomeUrl());
}

redirect(BASE_URL . '/login.php');
