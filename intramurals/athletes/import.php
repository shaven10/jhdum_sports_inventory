<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
redirect(BASE_URL . '/intramurals/roster/import.php');
