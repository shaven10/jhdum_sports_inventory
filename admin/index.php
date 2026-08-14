<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$db = getDB();

$userCount = (int) $db->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
$equipmentCount = 0;
$teamCount = 0;
$sportCount = 0;
$seasonLabel = '—';
$rosterLocked = false;

try {
    $equipmentCount = (int) $db->query('SELECT COUNT(*) FROM equipment WHERE is_active = 1')->fetchColumn();
} catch (Throwable $e) {
}

try {
    $teamCount = (int) $db->query('SELECT COUNT(*) FROM intramural_teams WHERE is_active = 1')->fetchColumn();
    $sportCount = (int) $db->query('SELECT COUNT(*) FROM intramural_sports')->fetchColumn();
    if (function_exists('getCurrentSeason') && getCurrentSeason()) {
        $seasonLabel = seasonLabel(getCurrentSeason());
        $rosterLocked = function_exists('isRosterLocked') ? isRosterLocked() : false;
    }
} catch (Throwable $e) {
}

$adminSections = [
    [
        'title' => 'People & Access',
        'description' => 'Manage accounts and roles across the system.',
        'cards' => [
            ['title' => 'Users', 'desc' => 'Create and manage user accounts', 'href' => BASE_URL . '/users/index.php', 'icon' => 'bi-people-fill', 'color' => 'primary', 'meta' => $userCount . ' active'],
        ],
    ],
    [
        'title' => 'System',
        'description' => 'Configure policies, appearance, and data tools.',
        'cards' => [
            ['title' => 'System Settings', 'desc' => 'Borrowing rules and campus info', 'href' => BASE_URL . '/settings/index.php', 'icon' => 'bi-gear-fill', 'color' => 'secondary'],
            ['title' => 'Theme Manager', 'desc' => 'Colors and visual presets', 'href' => BASE_URL . '/settings/theme.php', 'icon' => 'bi-palette-fill', 'color' => 'info'],
            ['title' => 'Equipment Categories', 'desc' => 'Inventory category list', 'href' => BASE_URL . '/settings/categories.php', 'icon' => 'bi-tags-fill', 'color' => 'success'],
            ['title' => 'Database Tools', 'desc' => 'Backup and restore utilities', 'href' => BASE_URL . '/settings/database.php', 'icon' => 'bi-database-gear', 'color' => 'warning'],
            ['title' => 'Audit Logs', 'desc' => 'Login history and activity trail', 'href' => BASE_URL . '/audit/index.php', 'icon' => 'bi-journal-check', 'color' => 'danger'],
        ],
    ],
    [
        'title' => 'Competition Admin',
        'description' => 'Season controls and roster lock for intramurals.',
        'cards' => [
            ['title' => 'Seasons / Years', 'desc' => 'Active season: ' . $seasonLabel, 'href' => BASE_URL . '/intramurals/seasons/index.php', 'icon' => 'bi-calendar3', 'color' => 'primary', 'meta' => $sportCount . ' sports · ' . $teamCount . ' teams'],
            ['title' => 'Roster Lock', 'desc' => $rosterLocked ? 'Rosters are currently locked' : 'Rosters are currently open', 'href' => BASE_URL . '/intramurals/roster/lock.php', 'icon' => $rosterLocked ? 'bi-lock-fill' : 'bi-unlock-fill', 'color' => $rosterLocked ? 'danger' : 'success'],
            ['title' => 'Intramurals Dashboard', 'desc' => 'Overview of competition modules', 'href' => BASE_URL . '/intramurals/index.php', 'icon' => 'bi-trophy-fill', 'color' => 'warning'],
            ['title' => 'Point System', 'desc' => 'Placement points configuration', 'href' => BASE_URL . '/intramurals/points/index.php', 'icon' => 'bi-calculator', 'color' => 'info'],
        ],
    ],
    [
        'title' => 'Inventory Admin',
        'description' => 'Equipment catalog and operations.',
        'cards' => [
            ['title' => 'Equipment', 'desc' => $equipmentCount . ' active equipment types', 'href' => BASE_URL . '/equipment/index.php', 'icon' => 'bi-box-seam-fill', 'color' => 'primary'],
            ['title' => 'Requests', 'desc' => 'Approve and track borrowings', 'href' => BASE_URL . '/requests/index.php', 'icon' => 'bi-clipboard-check', 'color' => 'success'],
            ['title' => 'Maintenance', 'desc' => 'Items under repair', 'href' => BASE_URL . '/equipment/maintenance.php', 'icon' => 'bi-wrench-adjustable', 'color' => 'secondary'],
            ['title' => 'Analytics', 'desc' => 'Inventory infographics', 'href' => BASE_URL . '/reports/analytics.php', 'icon' => 'bi-pie-chart-fill', 'color' => 'info'],
        ],
    ],
];

$pageTitle = 'Admin Panel';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1><i class="bi bi-shield-lock"></i> Admin Panel</h1>
        <p class="text-muted mb-0">System administration for <?= sanitize(APP_SHORT_NAME) ?></p>
    </div>
    <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Dashboard</a>
</div>

<div class="row g-3 mb-4 admin-stat-row">
    <div class="col-6 col-md-3">
        <div class="card admin-stat-card h-100">
            <div class="card-body">
                <div class="admin-stat-label">Active Users</div>
                <div class="admin-stat-value"><?= $userCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card admin-stat-card h-100">
            <div class="card-body">
                <div class="admin-stat-label">Equipment Types</div>
                <div class="admin-stat-value"><?= $equipmentCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card admin-stat-card h-100">
            <div class="card-body">
                <div class="admin-stat-label">Teams</div>
                <div class="admin-stat-value"><?= $teamCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card admin-stat-card h-100">
            <div class="card-body">
                <div class="admin-stat-label">Season</div>
                <div class="admin-stat-value text-truncate" style="font-size:1rem"><?= sanitize($seasonLabel) ?></div>
            </div>
        </div>
    </div>
</div>

<?php foreach ($adminSections as $section): ?>
<section class="admin-section mb-4">
    <div class="admin-section-header">
        <h2 class="h5 mb-1"><?= sanitize($section['title']) ?></h2>
        <p class="text-muted small mb-3"><?= sanitize($section['description']) ?></p>
    </div>
    <div class="row g-3">
        <?php foreach ($section['cards'] as $card): ?>
        <div class="col-6 col-md-4 col-xl-3">
            <a href="<?= sanitize($card['href']) ?>" class="card admin-link-card h-100 text-decoration-none">
                <div class="card-body">
                    <div class="admin-link-icon text-<?= sanitize($card['color']) ?>">
                        <i class="bi <?= sanitize($card['icon']) ?>"></i>
                    </div>
                    <div class="admin-link-title"><?= sanitize($card['title']) ?></div>
                    <div class="admin-link-desc"><?= sanitize($card['desc']) ?></div>
                    <?php if (!empty($card['meta'])): ?>
                    <div class="admin-link-meta"><?= sanitize($card['meta']) ?></div>
                    <?php endif; ?>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
