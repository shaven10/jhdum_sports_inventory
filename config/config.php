<?php
/**
 * JHCSC Dumingag Campus - Sports Equipment Inventory System
 * Main Configuration
 */

define('APP_NAME', 'JHCSC Sports Inventory');
define('APP_CAMPUS', 'JHCSC Dumingag Campus');
define('APP_VERSION', '1.0.0');
define('BASE_URL', '/sports_inventory');
define('UPLOAD_PATH', __DIR__ . '/../uploads/equipment/');
define('UPLOAD_URL', BASE_URL . '/uploads/equipment/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
define('LOW_STOCK_THRESHOLD', 3);
define('DEFAULT_BORROW_DAYS', 7);
define('MAX_BORROW_DAYS', 14);
define('MAX_BORROW_ITEMS', 5);

date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';
