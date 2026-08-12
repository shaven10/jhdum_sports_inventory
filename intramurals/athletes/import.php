<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
redirect(BASE_URL . '/intramurals/roster/import.php');
