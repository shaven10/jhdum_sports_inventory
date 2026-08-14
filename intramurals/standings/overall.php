<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
requireStandingsAccess();

$export = get('export');
$data = computeOverallStandings();
$labels = $data['sport_labels'];
$standings = $data['standings'];

$medalTally = $standings;
usort($medalTally, function ($a, $b) {
    if ($a['gold'] !== $b['gold']) {
        return $b['gold'] <=> $a['gold'];
    }
    if ($a['silver'] !== $b['silver']) {
        return $b['silver'] <=> $a['silver'];
    }
    if ($a['bronze'] !== $b['bronze']) {
        return $b['bronze'] <=> $a['bronze'];
    }
    return $b['total'] <=> $a['total'];
});
$medalRank = 1;
foreach ($medalTally as &$medalRow) {
    $medalRow['medal_rank'] = $medalRank++;
    $medalRow['medal_total'] = $medalRow['gold'] + $medalRow['silver'] + $medalRow['bronze'];
}
unset($medalRow);

$medalTotals = ['gold' => 0, 'silver' => 0, 'bronze' => 0];
foreach ($standings as $r) {
    $medalTotals['gold'] += $r['gold'];
    $medalTotals['silver'] += $r['silver'];
    $medalTotals['bronze'] += $r['bronze'];
}
$medalTotals['all'] = $medalTotals['gold'] + $medalTotals['silver'] + $medalTotals['bronze'];

if ($export === 'excel') {
    $headers = array_merge(['Rank', 'Team'], $labels, ['Total Points', 'Gold', 'Silver', 'Bronze', 'Total Medals']);
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
        $row[] = $r['gold'] + $r['silver'] + $r['bronze'];
        $rows[] = $row;
    }
    exportCsv('overall-standings-' . date('Ymd') . '.csv', $headers, $rows);
}

if ($export === 'medals') {
    $headers = ['Rank', 'Team', 'Gold', 'Silver', 'Bronze', 'Total Medals', 'Total Points'];
    $rows = [];
    foreach ($medalTally as $r) {
        if ($r['medal_total'] === 0 && $r['total'] === 0) {
            continue;
        }
        $rows[] = [$r['medal_rank'], $r['team_name'], $r['gold'], $r['silver'], $r['bronze'], $r['medal_total'], $r['total']];
    }
    exportCsv('medal-tally-' . date('Ymd') . '.csv', $headers, $rows);
}

$champion = null;
foreach ($standings as $r) {
    if ($r['total'] > 0) {
        $champion = $r;
        break;
    }
}

$medalLeader = null;
foreach ($medalTally as $r) {
    if ($r['medal_total'] > 0) {
        $medalLeader = $r;
        break;
    }
}

$eventHeaders = [];
foreach ($labels as $label) {
    if (preg_match('/^(.+?)\s*\(([^)]+)\)$/', $label, $m)) {
        $eventHeaders[] = ['name' => $m[1], 'category' => $m[2]];
    } else {
        $eventHeaders[] = ['name' => $label, 'category' => ''];
    }
}

$pageTitle = 'Overall Intramurals Standing';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
    <div>
        <h1><i class="bi bi-award"></i> Overall Intramurals Standing</h1>
        <p class="text-muted mb-0">Placement points by event and medal tally (Champion → 5th Runner Up)</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (canManageIntramurals()): ?>
        <a href="<?= BASE_URL ?>/intramurals/points/index.php" class="btn btn-outline-secondary"><i class="bi bi-calculator"></i> Point System</a>
        <?php endif; ?>
        <?php if (canManageMatches()): ?>
        <a href="<?= BASE_URL ?>/intramurals/rankings/index.php" class="btn btn-outline-warning"><i class="bi bi-list-ol"></i> Event Rankings</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/intramurals/standings/index.php" class="btn btn-outline-primary">Per-Sport Standings</a>
        <a href="?export=medals" class="btn btn-outline-warning"><i class="bi bi-trophy"></i> Medal Excel</a>
        <a href="?export=excel" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Full Excel</a>
        <button class="btn btn-outline-secondary" onclick="printReport()"><i class="bi bi-printer"></i> Print / PDF</button>
    </div>
</div>

<?= renderReportHeader('Overall Intramurals Standing', [
    'meta' => (function_exists('getCurrentSeason') && getCurrentSeason())
        ? ('Season: ' . seasonLabel(getCurrentSeason()))
        : '',
]) ?>

<?php if ($champion): ?>
<div class="alert alert-success no-print">
    <i class="bi bi-trophy-fill"></i> Current Overall Champion:
    <strong style="color:<?= sanitize($champion['color']) ?>"><?= sanitize($champion['team_name']) ?></strong>
    with <strong><?= $champion['total'] ?></strong> points
    (Gold <?= $champion['gold'] ?>, Silver <?= $champion['silver'] ?>, Bronze <?= $champion['bronze'] ?>).
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-trophy"></i> Medal Tally</span>
        <small class="text-muted">
            <?php if ($medalLeader): ?>
            Leader: <strong style="color:<?= sanitize($medalLeader['color']) ?>"><?= sanitize($medalLeader['team_name']) ?></strong>
            · <?= (int) $medalTotals['gold'] ?>G / <?= (int) $medalTotals['silver'] ?>S / <?= (int) $medalTotals['bronze'] ?>B awarded
            <?php else: ?>
            No medals recorded yet
            <?php endif; ?>
        </small>
    </div>
    <div class="card-body p-0">
        <div class="standings-scroll-wrap">
            <table class="table table-bordered table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="sticky-col">#</th>
                        <th class="sticky-col sticky-col-2">Team</th>
                        <th class="medal-tally-col gold"><i class="bi bi-trophy-fill"></i> Gold</th>
                        <th class="medal-tally-col silver"><i class="bi bi-trophy"></i> Silver</th>
                        <th class="medal-tally-col bronze"><i class="bi bi-trophy"></i> Bronze</th>
                        <th>Total Medals</th>
                        <th>Total Points</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($medalTally as $r): ?>
                    <tr class="<?= $medalLeader && $r['team_id'] === $medalLeader['team_id'] ? 'table-warning' : '' ?>">
                        <td class="sticky-col"><?= $r['medal_rank'] ?></td>
                        <td class="sticky-col sticky-col-2">
                            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= sanitize($r['color']) ?>"></span>
                            <?= sanitize($r['team_name']) ?>
                        </td>
                        <td class="medal-tally-col gold"><?= (int) $r['gold'] ?></td>
                        <td class="medal-tally-col silver"><?= (int) $r['silver'] ?></td>
                        <td class="medal-tally-col bronze"><?= (int) $r['bronze'] ?></td>
                        <td><strong><?= (int) $r['medal_total'] ?></strong></td>
                        <td><?= (int) $r['total'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($standings)): ?>
                    <tr><td colspan="7" class="text-muted p-3">No teams yet.</td></tr>
                    <?php else: ?>
                    <tr class="table-light fw-semibold">
                        <td class="sticky-col"></td>
                        <td class="sticky-col sticky-col-2">Total</td>
                        <td class="medal-tally-col gold"><?= (int) $medalTotals['gold'] ?></td>
                        <td class="medal-tally-col silver"><?= (int) $medalTotals['silver'] ?></td>
                        <td class="medal-tally-col bronze"><?= (int) $medalTotals['bronze'] ?></td>
                        <td><?= (int) $medalTotals['all'] ?></td>
                        <td></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-table"></i> Points Breakdown by Event</span>
        <small class="text-muted no-print">Scroll horizontally for more events · vertically for all teams</small>
    </div>
    <div class="card-body p-0">
        <div class="standings-scroll-wrap">
            <table class="table table-bordered table-hover mb-0">
                <thead class="table-light">
                    <tr class="event-header-sport">
                        <th rowspan="2" class="sticky-col">#</th>
                        <th rowspan="2" class="sticky-col sticky-col-2">Team</th>
                        <?php foreach ($eventHeaders as $eh): ?>
                        <th class="event-col-header event-col-name"><?= sanitize($eh['name']) ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2">Total</th>
                        <th rowspan="2" class="medal-tally-col gold">G</th>
                        <th rowspan="2" class="medal-tally-col silver">S</th>
                        <th rowspan="2" class="medal-tally-col bronze">B</th>
                    </tr>
                    <tr class="event-header-category">
                        <?php foreach ($eventHeaders as $eh): ?>
                        <th class="event-col-header event-col-cat"><?= sanitize($eh['category']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($standings as $r): ?>
                    <tr class="<?= $champion && $r['team_id'] === $champion['team_id'] ? 'table-success' : '' ?>">
                        <td class="sticky-col"><?= $r['rank'] ?></td>
                        <td class="sticky-col sticky-col-2">
                            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= sanitize($r['color']) ?>"></span>
                            <?= sanitize($r['team_name']) ?>
                        </td>
                        <?php foreach ($labels as $label): ?>
                        <td><?= $r['sports'][$label] ?? 0 ?></td>
                        <?php endforeach; ?>
                        <td><strong><?= $r['total'] ?></strong></td>
                        <td class="medal-tally-col gold"><?= (int) $r['gold'] ?></td>
                        <td class="medal-tally-col silver"><?= (int) $r['silver'] ?></td>
                        <td class="medal-tally-col bronze"><?= (int) $r['bronze'] ?></td>
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

<?= renderReportFooter('Overall Standing') ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
