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
$resultsLocked = false;
$liveBoardEnabled = true;
$openIncidents = 0;

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
        $resultsLocked = function_exists('isResultsLocked') ? isResultsLocked() : false;
        $liveBoardEnabled = function_exists('isLiveBoardEnabled') ? isLiveBoardEnabled() : true;
    }
} catch (Throwable $e) {
}

try {
    $openIncidents = function_exists('countOpenIncidentReports') ? countOpenIncidentReports() : 0;
} catch (Throwable $e) {
    $openIncidents = 0;
}

$adminSections = [
    [
        'title' => 'People & Access',
        'description' => 'Manage accounts and roles across the system.',
        'cards' => [
            ['title' => 'Users', 'desc' => 'Create and manage user accounts', 'href' => BASE_URL . '/users/index.php', 'icon' => 'bi-people-fill', 'color' => 'primary', 'meta' => $userCount . ' active'],
            ['title' => 'Working Committees', 'desc' => 'Public committee list on the landing page', 'href' => BASE_URL . '/admin/committees/index.php', 'icon' => 'bi-person-badge-fill', 'color' => 'info'],
            ['title' => 'Incident Reports', 'desc' => 'Queries and reports from TM / unit managers', 'href' => BASE_URL . '/admin/incidents/index.php', 'icon' => 'bi-flag-fill', 'color' => $openIncidents > 0 ? 'danger' : 'secondary', 'meta' => $openIncidents . ' open'],
            ['title' => 'Announcements', 'desc' => 'Broadcast to all staff modules (not students)', 'href' => BASE_URL . '/admin/announcements/index.php', 'icon' => 'bi-megaphone-fill', 'color' => 'warning'],
        ],
    ],
    [
        'title' => 'System',
        'description' => 'Configure policies, appearance, and data tools.',
        'cards' => [
            ['title' => 'System Settings', 'desc' => 'Borrowing rules and campus info', 'href' => BASE_URL . '/settings/index.php', 'icon' => 'bi-gear-fill', 'color' => 'secondary'],
            ['title' => 'Borrowable Equipment', 'desc' => 'Choose which items students may request', 'href' => BASE_URL . '/settings/borrowable.php', 'icon' => 'bi-box-arrow-up', 'color' => 'warning'],
            ['title' => 'Theme Manager', 'desc' => 'Colors and visual presets', 'href' => BASE_URL . '/settings/theme.php', 'icon' => 'bi-palette-fill', 'color' => 'info'],
            ['title' => 'Equipment Categories', 'desc' => 'Inventory category list', 'href' => BASE_URL . '/settings/categories.php', 'icon' => 'bi-tags-fill', 'color' => 'success'],
            ['title' => 'Courses', 'desc' => 'Athlete course / program dropdown list', 'href' => BASE_URL . '/admin/courses/index.php', 'icon' => 'bi-mortarboard-fill', 'color' => 'primary'],
            ['title' => 'Database Tools', 'desc' => 'Backup, restore, and reset to default', 'href' => BASE_URL . '/settings/database.php', 'icon' => 'bi-database-gear', 'color' => 'warning'],
            ['title' => 'Audit Logs', 'desc' => 'Login history and activity trail', 'href' => BASE_URL . '/audit/index.php', 'icon' => 'bi-journal-check', 'color' => 'danger'],
        ],
    ],
    [
        'title' => 'Competition Admin',
        'description' => 'Season controls, roster lock, and results lock for intramurals.',
        'cards' => [
            ['title' => 'Seasons / Years', 'desc' => 'Active season: ' . $seasonLabel, 'href' => BASE_URL . '/intramurals/seasons/index.php', 'icon' => 'bi-calendar3', 'color' => 'primary', 'meta' => $sportCount . ' sports · ' . $teamCount . ' teams'],
            ['title' => 'Roster Lock', 'desc' => $rosterLocked ? 'Rosters are currently locked' : 'Rosters are currently open', 'href' => BASE_URL . '/intramurals/roster/lock.php', 'icon' => $rosterLocked ? 'bi-lock-fill' : 'bi-unlock-fill', 'color' => $rosterLocked ? 'danger' : 'success'],
            ['title' => 'Live Standings', 'desc' => $liveBoardEnabled ? 'Public live board is enabled' : 'Public live board is hidden', 'href' => BASE_URL . '/admin/live_board.php', 'icon' => $liveBoardEnabled ? 'bi-broadcast' : 'bi-eye-slash', 'color' => $liveBoardEnabled ? 'success' : 'secondary'],
            ['title' => 'Landing Page', 'desc' => 'SDO about text and activity gallery', 'href' => BASE_URL . '/admin/landing.php', 'icon' => 'bi-house-door', 'color' => 'info'],
            ['title' => 'Lock Results', 'desc' => $resultsLocked ? 'Match results are currently locked' : 'Match results are currently open', 'href' => BASE_URL . '/admin/results_lock.php', 'icon' => $resultsLocked ? 'bi-lock-fill' : 'bi-trophy-fill', 'color' => $resultsLocked ? 'danger' : 'success'],
            ['title' => 'TM Ranking Access', 'desc' => 'Activate Manual Entry of Ranks for tournament managers per event', 'href' => BASE_URL . '/admin/tm_ranking.php', 'icon' => 'bi-list-check', 'color' => 'warning'],
            ['title' => 'Divisions', 'desc' => 'Group teams (e.g. HS / College) and assign events', 'href' => BASE_URL . '/admin/divisions/index.php', 'icon' => 'bi-diagram-3', 'color' => 'info'],
            ['title' => 'Delete Athletes', 'desc' => 'Permanently remove athletes or wipe all records', 'href' => BASE_URL . '/admin/athletes/delete.php', 'icon' => 'bi-person-x-fill', 'color' => 'danger'],
            ['title' => 'Team Positions', 'desc' => 'Set Team 1…N per event (by division team count)', 'href' => BASE_URL . '/admin/team_positions/index.php', 'icon' => 'bi-list-ol', 'color' => 'primary'],
            ['title' => 'Certificates', 'desc' => 'Certificate of Recognition for finished events', 'href' => BASE_URL . '/intramurals/reports/certificates.php', 'icon' => 'bi-award', 'color' => 'success'],
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
