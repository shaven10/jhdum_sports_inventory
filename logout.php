<?php
require_once __DIR__ . '/includes/auth.php';
logout();
flash('success', 'You have been logged out successfully.');
redirect(BASE_URL . '/login.php');
