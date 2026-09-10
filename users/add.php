<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);
redirect(BASE_URL . '/users/index.php');
