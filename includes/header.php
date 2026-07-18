<?php
if (!isset($pageTitle)) {
    $pageTitle = APP_NAME;
}

$currentUser = getCurrentUser();
$unreadCount = $currentUser ? getUnreadNotificationCount($currentUser['id']) : 0;
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle) ?> - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php if (isLoggedIn()): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="<?= BASE_URL ?>/dashboard.php">
            <i class="bi bi-trophy"></i> JHCSC Sports
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/equipment/index.php"><i class="bi bi-box-seam"></i> Equipment</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/requests/index.php"><i class="bi bi-clipboard-check"></i> Requests</a>
                </li>
                <?php if (canManageInventory()): ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/transactions/index.php"><i class="bi bi-arrow-left-right"></i> Transactions</a>
                </li>
                <?php endif; ?>
                <?php if (canViewReports()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown"><i class="bi bi-bar-chart"></i> Reports</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/reports/inventory.php">Inventory Report</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/reports/borrowings.php">Borrowing Report</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/reports/overdue.php">Overdue Report</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/reports/damage.php">Damage Report</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/reports/analytics.php">Analytics</a></li>
                    </ul>
                </li>
                <?php endif; ?>
                <?php if (canManageUsers()): ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/users/index.php"><i class="bi bi-people"></i> Users</a>
                </li>
                <?php endif; ?>
                <?php if (canManageSettings()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown"><i class="bi bi-gear"></i> Settings</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/settings/index.php">System Settings</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/settings/categories.php">Categories</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/audit/index.php">Audit Logs</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle position-relative" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-bell"></i>
                        <?php if ($unreadCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $unreadCount ?></span>
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
                        <i class="bi bi-person-circle"></i> <?= sanitize($_SESSION['user_name']) ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text"><?= roleBadge($_SESSION['user_role']) ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/profile.php"><i class="bi bi-person"></i> Profile</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/history/index.php"><i class="bi bi-clock-history"></i> Borrowing History</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
<?php endif; ?>

<main class="<?= isLoggedIn() ? 'container-fluid py-4' : '' ?>">
<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?> alert-dismissible fade show" role="alert">
    <?= sanitize($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
