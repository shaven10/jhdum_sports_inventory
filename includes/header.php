<?php
if (!isset($pageTitle)) {
    $pageTitle = APP_NAME;
}

require_once __DIR__ . '/navigation.php';

$currentUser = getCurrentUser();
$unreadCount = $currentUser ? getUnreadNotificationCount($currentUser['id']) : 0;
$flash = getFlash();
$appNav = isLoggedIn() ? buildAppNavigation() : [];
$styleFile = __DIR__ . '/../assets/css/style.css';
$styleVersion = is_file($styleFile) ? (string) filemtime($styleFile) : APP_VERSION;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="<?= sanitize(getActiveTheme()['primary'] ?? '#1b5e20') ?>">
    <title><?= sanitize($pageTitle) ?> - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css?v=<?= $styleVersion ?>" rel="stylesheet">
    <?= renderThemeStyles() ?>
</head>
<body class="<?= (getActiveTheme()['preset'] ?? '') === 'dark_mode' ? 'theme-dark' : '' ?>">
<?php if (isLoggedIn()): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top shadow-sm app-navbar">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="<?= getHomeUrl() ?>">
            <img src="<?= sanitize(appLogoUrl()) ?>" alt="Sports Development" class="app-brand-logo">
            <span class="brand-full"><?= sanitize(APP_SHORT_NAME) ?></span>
            <span class="brand-short">SDMIS</span>
        </a>

        <div class="d-flex align-items-center gap-1 d-lg-none">
            <a href="<?= BASE_URL ?>/notifications/index.php" class="btn btn-link nav-icon-btn text-white position-relative" aria-label="Notifications">
                <i class="bi bi-bell fs-5"></i>
                <?php if ($unreadCount > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notification-badge"><?= $unreadCount ?></span>
                <?php endif; ?>
            </a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#appNavOffcanvas" aria-controls="appNavOffcanvas" aria-label="Open menu">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>

        <div class="collapse navbar-collapse d-none d-lg-flex" id="navbarNavDesktop">
            <ul class="navbar-nav me-auto">
                <?php renderDesktopNav($appNav); ?>
            </ul>
            <ul class="navbar-nav ms-lg-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle position-relative" href="#" data-bs-toggle="dropdown" aria-label="Notifications">
                        <i class="bi bi-bell"></i>
                        <?php if ($unreadCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notification-badge"><?= $unreadCount ?></span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end notification-dropdown">
                        <li><h6 class="dropdown-header">Notifications</h6></li>
                        <?php
                        $notifications = $currentUser ? getNotifications($currentUser['id'], 5) : [];
                        if (empty($notifications)):
                        ?>
                        <li><span class="dropdown-item-text text-muted">No notifications</span></li>
                        <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                        <li>
                            <a class="dropdown-item <?= $notif['is_read'] ? '' : 'fw-bold' ?>" href="<?= BASE_URL ?>/notifications/read.php?id=<?= $notif['id'] ?>">
                                <small class="text-muted"><?= formatDateTime($notif['created_at']) ?></small><br>
                                <?= sanitize($notif['title']) ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-center" href="<?= BASE_URL ?>/notifications/index.php">View All</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <span class="user-name-text"><?= sanitize($_SESSION['user_name']) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text"><?= roleBadge($_SESSION['user_role']) ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/profile.php"><i class="bi bi-person"></i> Profile</a></li>
                        <?php if (!isIntramuralsOnlyRole()): ?>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/history/index.php"><i class="bi bi-clock-history"></i> Borrowing History</a></li>
                        <?php endif; ?>
                        <?php if (isAdmin()): ?>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/admin/index.php"><i class="bi bi-shield-lock"></i> Admin Panel</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="offcanvas offcanvas-end app-nav-offcanvas text-bg-dark" tabindex="-1" id="appNavOffcanvas" aria-labelledby="appNavOffcanvasLabel">
    <div class="offcanvas-header border-bottom border-secondary">
        <div class="d-flex align-items-center gap-2" id="appNavOffcanvasLabel">
            <img src="<?= sanitize(appLogoUrl()) ?>" alt="" class="app-brand-logo">
            <div>
                <div class="fw-semibold"><?= sanitize(APP_SHORT_NAME) ?></div>
                <div class="small text-white-50"><?= sanitize($_SESSION['user_name'] ?? '') ?></div>
            </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-0 d-flex flex-column">
        <div class="mobile-nav-scroll flex-grow-1">
            <?php renderMobileNav($appNav); ?>
        </div>
        <div class="mobile-nav-footer border-top border-secondary">
            <a class="mobile-nav-link" href="<?= BASE_URL ?>/profile.php"><i class="bi bi-person"></i><span>Profile</span></a>
            <?php if (!isIntramuralsOnlyRole()): ?>
            <a class="mobile-nav-link" href="<?= BASE_URL ?>/history/index.php"><i class="bi bi-clock-history"></i><span>Borrowing History</span></a>
            <?php endif; ?>
            <a class="mobile-nav-link" href="<?= BASE_URL ?>/notifications/index.php"><i class="bi bi-bell"></i><span>Notifications<?= $unreadCount > 0 ? ' (' . (int) $unreadCount . ')' : '' ?></span></a>
            <a class="mobile-nav-link text-danger" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
        </div>
    </div>
</div>
<?php endif; ?>

<main class="<?= isLoggedIn() ? 'app-main container-fluid px-3 px-sm-4 py-3 py-md-4' : '' ?>">
<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?> alert-dismissible fade show mx-0" role="alert">
    <?= sanitize($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
