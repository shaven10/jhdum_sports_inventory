<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$db = getDB();
$seasonId = getCurrentSeasonId();
$month = get('month', date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$start = $month . '-01';
$end = date('Y-m-t', strtotime($start));
$prev = date('Y-m', strtotime($start . ' -1 month'));
$next = date('Y-m', strtotime($start . ' +1 month'));

$sql = "SELECT m.*, s.name as sport_name,
    ta.name as team_a_name, tb.name as team_b_name
    FROM intramural_matches m
    JOIN intramural_sports s ON m.sport_id = s.id
    JOIN intramural_teams ta ON m.team_a_id = ta.id
    JOIN intramural_teams tb ON m.team_b_id = tb.id
    WHERE DATE(m.scheduled_at) BETWEEN ? AND ?";
$params = [$start, $end];
if ($seasonId) {
    $sql .= ' AND m.season_id = ?';
    $params[] = $seasonId;
}
$sql .= ' ORDER BY m.scheduled_at';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$matches = $stmt->fetchAll();

$byDate = [];
foreach ($matches as $m) {
    $d = date('Y-m-d', strtotime($m['scheduled_at']));
    $byDate[$d][] = $m;
}

$firstDow = (int) date('N', strtotime($start)); // 1=Mon
$daysInMonth = (int) date('t', strtotime($start));

$pageTitle = 'Match Calendar';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-calendar-week"></i> Match Calendar</h1>
        <p class="text-muted mb-0"><?= date('F Y', strtotime($start)) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="?month=<?= $prev ?>" class="btn btn-outline-secondary">&laquo; Prev</a>
        <a href="?month=<?= date('Y-m') ?>" class="btn btn-outline-primary">Today</a>
        <a href="?month=<?= $next ?>" class="btn btn-outline-secondary">Next &raquo;</a>
        <a href="<?= BASE_URL ?>/intramurals/matches/index.php" class="btn btn-outline-secondary">List View</a>
    </div>
</div>

<div class="card">
    <div class="card-body p-2 p-md-3">
        <div class="table-responsive">
            <table class="table table-bordered mb-0 calendar-table" style="table-layout:fixed">
                <thead class="table-light">
                    <tr>
                        <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d): ?>
                        <th class="text-center"><?= $d ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                    <?php
                    $cells = $firstDow - 1;
                    for ($i = 0; $i < $cells; $i++) {
                        echo '<td class="bg-light"></td>';
                    }
                    for ($day = 1; $day <= $daysInMonth; $day++) {
                        if ($cells > 0 && $cells % 7 === 0) {
                            echo '</tr><tr>';
                        }
                        $date = sprintf('%s-%02d', $month, $day);
                        $isToday = $date === date('Y-m-d');
                        echo '<td class="align-top' . ($isToday ? ' table-primary' : '') . '" style="min-height:90px;height:110px">';
                        echo '<div class="fw-semibold small mb-1">' . $day . '</div>';
                        if (!empty($byDate[$date])) {
                            foreach ($byDate[$date] as $m) {
                                $label = date('H:i', strtotime($m['scheduled_at'])) . ' ' . $m['sport_name'];
                                echo '<a class="d-block small text-decoration-none mb-1 p-1 rounded bg-primary bg-opacity-10" href="' . BASE_URL . '/intramurals/matches/view.php?id=' . $m['id'] . '">';
                                echo sanitize($label) . '<br><span class="text-muted">' . sanitize($m['team_a_name']) . ' vs ' . sanitize($m['team_b_name']) . '</span>';
                                echo '</a>';
                            }
                        }
                        echo '</td>';
                        $cells++;
                    }
                    while ($cells % 7 !== 0) {
                        echo '<td class="bg-light"></td>';
                        $cells++;
                    }
                    ?>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
