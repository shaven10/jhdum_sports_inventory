<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$id = (int) get('id');
if ($id) {
    redirect(BASE_URL . '/users/index.php?edit=' . $id);
}

redirect(BASE_URL . '/users/index.php');
