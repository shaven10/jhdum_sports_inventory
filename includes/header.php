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
    <?= renderThemeStyles() ?>
</head>
<body class="<?= (getActiveTheme()['preset'] ?? '') === 'dark_mode' ? 'theme-dark' : '' ?>">
<?php if (isLoggedIn()): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top shadow-sm">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand fw-bold" href="<?= getHomeUrl() ?>">
            <i class="bi bi-trophy"></i>
            <span class="brand-full"> JHCSC Sports</span>
            <span class="brand-short"> JHCSC</span>
        </a>
        <div class="d-flex align-items-center gap-1 d-lg-none">
            <?php if ($unreadCount > 0): ?>
            <a href="<?= BASE_URL ?>/notifications/index.php" class="btn btn-link nav-icon-btn text-white position-relative">
                <i class="bi bi-bell fs-5"></i>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notification-badge"><?= $unreadCount ?></span>
            </a>
            <?php endif; ?>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto pt-2 pt-lg-0">
                <?php if (!isIntramuralsOnlyRole()): ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                        <i class="bi bi-box-seam"></i> Inventory
                    </a>
                    <ul class="dropdown-menu">
                        <li><h6 class="dropdown-header">Equipment Inventory</h6></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/equipment/index.php"><i class="bi bi-box-seam"></i> Equipment</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/requests/index.php"><i class="bi bi-clipboard-check"></i> Requests</a></li>
                        <?php if (canManageInventory()): ?>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/transactions/index.php"><i class="bi bi-arrow-left-right"></i> Transactions</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/equipment/maintenance.php"><i class="bi bi-tools"></i> Maintenance</a></li>
                        <?php endif; ?>
                        <?php if (canViewReports() && canManageInventory()): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Inventory Reports</h6></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/reports/inventory.php">Inventory Report</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/reports/borrowings.php">Borrowing Report</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/reports/overdue.php">Overdue Report</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/reports/damage.php">Damage Report</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/reports/analytics.php">Analytics & Infographics</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (canViewIntramurals()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                        <i class="bi bi-trophy"></i> Intramurals
                    </a>
                    <ul class="dropdown-menu">
                        <li><h6 class="dropdown-header">Overview</h6></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/intramurals/index.php"><i class="bi bi-speedometer2"></i> <?= isIntramuralsOnlyRole() ? 'Dashboard' : 'Intramurals Dashboard' ?></a></li>
                        <?php if (canManageIntramurals()): ?>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/intramurals/seasons/index.php"><i class="bi bi-calendar3"></i> Seasons / Years</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Participants</h6></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/intramurals/athletes/index.php">Athletes</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/intramurals/teams/index.php">Teams</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/intramurals/sports/index.php">Sports / Events</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/intramurals/roster/index.php">Rosters</a></li>
                        <?php if (canManageTeamAthletes() || canManageTeamRoster()): ?>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/intramurals/roster/import.php">Import Roster</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Competition</h6></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/intramurals/matches/index.php">Matches</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/intramurals/matches/calendar.php">Calendar</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/intramurals/standings/index.php">Standings</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/intramurals/standings/overall.php">Overall Standing</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/intramurals/points/index.php">Point System</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Reports</h6></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/intramurals/reports/index.php">Intramurals Reports</a></li>
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
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/settings/theme.php"><i class="bi bi-palette"></i> Theme Manager</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/settings/categories.php">Equipment Categories</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/audit/index.php">Audit Logs</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav ms-lg-auto pt-2 pt-lg-0 border-top border-lg-0 mt-2 mt-lg-0">
                <li class="nav-item dropdown d-none d-lg-block">
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
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
<?php endif; ?>

<main class="<?= isLoggedIn() ? 'app-main container-fluid px-3 px-sm-4 py-3 py-md-4' : '' ?>">
<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?> alert-dismissible fade show mx-0" role="alert">
    <?= sanitize($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
