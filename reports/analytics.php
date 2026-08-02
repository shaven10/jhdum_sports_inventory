<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'coordinator', 'staff']);

$db = getDB();
$year = (int) get('year', date('Y'));

$summary = $db->prepare("
    SELECT
        COUNT(*) as total_requests,
        SUM(quantity) as total_items_borrowed,
        SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned_count,
        SUM(CASE WHEN status IN ('checked_out', 'overdue') THEN 1 ELSE 0 END) as active_count,
        COUNT(DISTINCT user_id) as unique_borrowers,
        COUNT(DISTINCT equipment_id) as equipment_used
    FROM borrowing_requests WHERE YEAR(created_at) = ?
");
$summary->execute([$year]);
$summaryStats = $summary->fetch();

$mostBorrowed = $db->prepare("
    SELECT e.name, c.name as category, COUNT(br.id) as borrow_count, SUM(br.quantity) as total_qty
    FROM borrowing_requests br
    JOIN equipment e ON br.equipment_id = e.id
    JOIN equipment_categories c ON e.category_id = c.id
    WHERE YEAR(br.created_at) = ?
    GROUP BY e.id ORDER BY borrow_count DESC LIMIT 10
");
$mostBorrowed->execute([$year]);
$mostBorrowedData = $mostBorrowed->fetchAll();

$monthlyStats = $db->prepare("
    SELECT MONTH(created_at) as month, COUNT(*) as total,
           SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned,
           SUM(CASE WHEN status IN ('checked_out', 'overdue') THEN 1 ELSE 0 END) as active
    FROM borrowing_requests WHERE YEAR(created_at) = ?
    GROUP BY MONTH(created_at) ORDER BY month
");
$monthlyStats->execute([$year]);
$monthlyData = $monthlyStats->fetchAll();

$monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$monthlyLabels = [];
$monthlyTotals = [];
$monthlyReturned = [];
$monthlyActive = [];
for ($m = 1; $m <= 12; $m++) {
    $monthlyLabels[] = $monthNames[$m - 1];
    $found = null;
    foreach ($monthlyData as $row) {
        if ((int) $row['month'] === $m) {
            $found = $row;
            break;
        }
    }
    $monthlyTotals[] = $found ? (int) $found['total'] : 0;
    $monthlyReturned[] = $found ? (int) $found['returned'] : 0;
    $monthlyActive[] = $found ? (int) $found['active'] : 0;
}

$categoryStats = $db->prepare("
    SELECT c.name, COUNT(br.id) as count
    FROM borrowing_requests br
    JOIN equipment e ON br.equipment_id = e.id
    JOIN equipment_categories c ON e.category_id = c.id
    WHERE YEAR(br.created_at) = ?
    GROUP BY c.id ORDER BY count DESC
");
$categoryStats->execute([$year]);
$categoryData = $categoryStats->fetchAll();

$utilization = $db->query("
    SELECT e.name, e.quantity_total, e.quantity_borrowed, e.quantity_reserved, e.quantity_available,
           ROUND((e.quantity_borrowed + e.quantity_reserved) / NULLIF(e.quantity_total, 0) * 100, 1) as utilization_rate
    FROM equipment e WHERE e.is_active = 1 AND e.quantity_total > 0
    ORDER BY utilization_rate DESC LIMIT 10
")->fetchAll();

$purposeStats = $db->prepare("
    SELECT purpose, COUNT(*) as count FROM borrowing_requests
    WHERE YEAR(created_at) = ? GROUP BY purpose ORDER BY count DESC
");
$purposeStats->execute([$year]);
$purposeData = $purposeStats->fetchAll();

$dayOfWeek = $db->prepare("
    SELECT DAYNAME(created_at) as day_name, DAYOFWEEK(created_at) as dow, COUNT(*) as count
    FROM borrowing_requests WHERE YEAR(created_at) = ?
    GROUP BY DAYOFWEEK(created_at), DAYNAME(created_at) ORDER BY dow
");
$dayOfWeek->execute([$year]);
$dowData = $dayOfWeek->fetchAll();

$dowLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$dowCounts = array_fill(0, 7, 0);
foreach ($dowData as $d) {
    $dowCounts[(int) $d['dow'] - 1] = (int) $d['count'];
}

$statusBreakdown = $db->prepare("
    SELECT status, COUNT(*) as count FROM borrowing_requests
    WHERE YEAR(created_at) = ? GROUP BY status
");
$statusBreakdown->execute([$year]);
$statusData = $statusBreakdown->fetchAll();

$theme = getActiveTheme();
$chartColors = [
    $theme['primary'],
    $theme['secondary'],
    $theme['accent'],
    '#27ae60',
    '#e74c3c',
    '#9b59b6',
    '#1abc9c',
    '#e67e22',
    '#3498db',
    '#95a5a6',
];

$chartData = [
    'mostBorrowed' => [
        'labels' => array_column($mostBorrowedData, 'name'),
        'counts' => array_map('intval', array_column($mostBorrowedData, 'borrow_count')),
    ],
    'monthly' => [
        'labels' => $monthlyLabels,
        'totals' => $monthlyTotals,
        'returned' => $monthlyReturned,
        'active' => $monthlyActive,
    ],
    'categories' => [
        'labels' => array_column($categoryData, 'name'),
        'counts' => array_map('intval', array_column($categoryData, 'count')),
    ],
    'utilization' => [
        'labels' => array_column($utilization, 'name'),
        'rates' => array_map('floatval', array_column($utilization, 'utilization_rate')),
    ],
    'purposes' => [
        'labels' => array_map(fn($p) => purposeLabel($p['purpose']), $purposeData),
        'counts' => array_map('intval', array_column($purposeData, 'count')),
    ],
    'dayOfWeek' => [
        'labels' => $dowLabels,
        'counts' => $dowCounts,
    ],
    'status' => [
        'labels' => array_map(fn($s) => ucfirst(str_replace('_', ' ', $s['status'])), $statusData),
        'counts' => array_map('intval', array_column($statusData, 'count')),
    ],
    'colors' => $chartColors,
];

$pageTitle = 'Analytics & Infographics';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
    <div>
        <h1><i class="bi bi-graph-up-arrow"></i> Analytics & Infographics</h1>
        <p class="text-muted mb-0">Equipment utilization and borrowing frequency insights</p>
    </div>
    <div class="d-flex gap-2">
        <form method="GET" class="d-flex gap-2">
            <select name="year" class="form-select" style="width:auto">
                <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="btn btn-primary">View</button>
        </form>
        <button onclick="printReport()" class="btn btn-outline-secondary"><i class="bi bi-printer"></i> Print</button>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card analytics-stat"><div class="value"><?= (int) ($summaryStats['total_requests'] ?? 0) ?></div><div class="label">Total Requests</div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card analytics-stat"><div class="value"><?= (int) ($summaryStats['total_items_borrowed'] ?? 0) ?></div><div class="label">Items Borrowed</div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card analytics-stat"><div class="value"><?= (int) ($summaryStats['unique_borrowers'] ?? 0) ?></div><div class="label">Unique Borrowers</div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card analytics-stat"><div class="value"><?= (int) ($summaryStats['equipment_used'] ?? 0) ?></div><div class="label">Equipment Used</div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card analytics-stat"><div class="value"><?= (int) ($summaryStats['returned_count'] ?? 0) ?></div><div class="label">Returned</div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card analytics-stat"><div class="value"><?= (int) ($summaryStats['active_count'] ?? 0) ?></div><div class="label">Currently Active</div></div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-bar-chart"></i> Monthly Borrowing Frequency (<?= $year ?>)</div>
            <div class="card-body"><div class="chart-container"><canvas id="monthlyChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-pie-chart"></i> Borrowing by Category</div>
            <div class="card-body"><div class="chart-container"><canvas id="categoryChart"></canvas></div></div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-trophy"></i> Most Borrowed Equipment (Frequency)</div>
            <div class="card-body"><div class="chart-container"><canvas id="mostBorrowedChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-speedometer2"></i> Equipment Utilization Rate (%)</div>
            <div class="card-body"><div class="chart-container"><canvas id="utilizationChart"></canvas></div></div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-4">
        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-calendar-week"></i> Borrowing by Day of Week</div>
            <div class="card-body"><div class="chart-container chart-container-sm"><canvas id="dowChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-bookmark"></i> Borrowing by Purpose</div>
            <div class="card-body"><div class="chart-container chart-container-sm"><canvas id="purposeChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-clipboard-data"></i> Request Status Breakdown</div>
            <div class="card-body"><div class="chart-container chart-container-sm"><canvas id="statusChart"></canvas></div></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-table"></i> Equipment Utilization Details</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Equipment</th><th>Total</th><th>Borrowed</th><th>Reserved</th><th>Available</th><th>Utilization</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($utilization as $u): ?>
                    <tr>
                        <td><?= sanitize($u['name']) ?></td>
                        <td><?= $u['quantity_total'] ?></td>
                        <td><?= $u['quantity_borrowed'] ?></td>
                        <td><?= $u['quantity_reserved'] ?></td>
                        <td><?= $u['quantity_available'] ?></td>
                        <td style="min-width:150px">
                            <div class="d-flex align-items-center gap-2">
                                <div class="utilization-bar flex-grow-1">
                                    <div class="utilization-bar-fill" style="width:<?= min(100, $u['utilization_rate']) ?>%"></div>
                                </div>
                                <strong><?= $u['utilization_rate'] ?>%</strong>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<p class="text-muted mt-3 small">Generated on <?= date('F d, Y h:i A') ?> | <?= APP_CAMPUS ?> | Year: <?= $year ?></p>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
window.analyticsData = <?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= BASE_URL ?>/assets/js/analytics-charts.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
