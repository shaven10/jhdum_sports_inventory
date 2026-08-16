<?php
/**
 * J.H. Cerilles State College - Sports Development IMIS
 * Main Configuration
 */

define('APP_NAME', 'JHCSC Sports Development IMIS');
define('APP_SHORT_NAME', 'JHCSC SDIMIS');
define('APP_CAMPUS', 'J.H. Cerilles State College');
define('APP_TAGLINE', 'Sports Development Management Information System');
define('APP_VERSION', '1.0.0');
define('BASE_URL', '/sports_inventory');
define('APP_LOGO', BASE_URL . '/assets/img/sports-development-logo.png');
define('APP_LOGO_PATH', __DIR__ . '/../assets/img/sports-development-logo.png');
define('UPLOAD_PATH', __DIR__ . '/../uploads/equipment/');
define('UPLOAD_URL', BASE_URL . '/uploads/equipment/');
define('UPLOAD_PATH_ATHLETES', __DIR__ . '/../uploads/athletes/');
define('UPLOAD_URL_ATHLETES', BASE_URL . '/uploads/athletes/');
define('UPLOAD_PATH_TEAMS', __DIR__ . '/../uploads/teams/');
define('UPLOAD_URL_TEAMS', BASE_URL . '/uploads/teams/');
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
require_once __DIR__ . '/../includes/theme.php';
