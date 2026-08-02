<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$export = get('export');
$data = computeOverallStandings();
$labels = $data['sport_labels'];
$standings = $data['standings'];

if ($export === 'excel') {
    $headers = array_merge(['Rank', 'Team'], $labels, ['Total', 'Gold', 'Silver', 'Bronze']);
    $rows = [];
    foreach ($standings as $r) {
        $row = [$r['rank'], $r['team_name']];
        foreach ($labels as $label) {
            $row[] = $r['sports'][$label] ?? 0;
        }
        $row[] = $r['total'];
        $row[] = $r['gold'];
        $row[] = $r['silver'];
        $row[] = $r['bronze'];
        $rows[] = $row;
    }
    exportCsv('overall-standings-' . date('Ymd') . '.csv', $headers, $rows);
}

$champion = null;
foreach ($standings as $r) {
    if ($r['total'] > 0) {
        $champion = $r;
        break;
    }
}

$pageTitle = 'Overall Intramurals Standing';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
    <div>
        <h1><i class="bi bi-award"></i> Overall Intramurals Standing</h1>
        <p class="text-muted mb-0">Sum of event placement points (Champion → 5th Runner Up)</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/intramurals/points/index.php" class="btn btn-outline-secondary"><i class="bi bi-calculator"></i> Point System</a>
        <a href="<?= BASE_URL ?>/intramurals/standings/index.php" class="btn btn-outline-primary">Per-Sport Standings</a>
        <a href="?export=excel" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        <button class="btn btn-outline-secondary" onclick="printReport()"><i class="bi bi-printer"></i> Print / PDF</button>
    </div>
</div>

<?php if ($champion): ?>
<div class="alert alert-success">
    <i class="bi bi-trophy-fill"></i> Current Overall Champion:
    <strong style="color:<?= sanitize($champion['color']) ?>"><?= sanitize($champion['team_name']) ?></strong>
    with <strong><?= $champion['total'] ?></strong> points
    (Gold <?= $champion['gold'] ?>, Silver <?= $champion['silver'] ?>, Bronze <?= $champion['bronze'] ?>).
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Team</th>
                        <?php foreach ($labels as $label): ?>
                        <th><?= sanitize($label) ?></th>
                        <?php endforeach; ?>
                        <th>Total</th>
                        <th>Medals</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($standings as $r): ?>
                    <tr class="<?= $champion && $r['team_id'] === $champion['team_id'] ? 'table-success' : '' ?>">
                        <td><?= $r['rank'] ?></td>
                        <td>
                            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= sanitize($r['color']) ?>"></span>
                            <?= sanitize($r['team_name']) ?>
                        </td>
                        <?php foreach ($labels as $label): ?>
                        <td><?= $r['sports'][$label] ?? 0 ?></td>
                        <?php endforeach; ?>
                        <td><strong><?= $r['total'] ?></strong></td>
                        <td><span class="badge bg-warning text-dark"><?= $r['gold'] ?>G</span> <span class="badge bg-secondary"><?= $r['silver'] ?>S</span> <span class="badge bg-danger"><?= $r['bronze'] ?>B</span></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($standings)): ?>
                    <tr><td colspan="<?= 4 + count($labels) ?>" class="text-muted p-3">No teams yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
