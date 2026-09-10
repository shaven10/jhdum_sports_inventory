<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(getHomeUrl());
}

ensureLiveBoardColumns();
if (isLiveBoardEnabled()) {
    redirect(BASE_URL . '/live.php');
}

redirect(BASE_URL . '/landing.php');
