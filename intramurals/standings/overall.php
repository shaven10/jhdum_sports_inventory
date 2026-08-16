<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
requireStandingsAccess();

$export = get('export');
$data = computeOverallStandings();
$standings = $data['standings'];
$byDivision = $data['by_division'] ?? [];

if ($export === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="overall-standings-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    foreach ($byDivision as $group) {
        $labels = $group['sport_labels'] ?? [];
        fputcsv($out, [strtoupper((string) $group['division_name']) . ' (' . count($labels) . ' events)']);
        $headers = array_merge(['Rank', 'Team'], $labels, ['Total Points', 'Gold', 'Silver', 'Bronze', 'Total Medals']);
        fputcsv($out, $headers);
        foreach ($group['standings'] as $r) {
            $row = [$r['division_rank'] ?? $r['rank'], $r['team_name']];
            foreach ($labels as $label) {
                $row[] = $r['sports'][$label] ?? 0;
            }
            $row[] = $r['total'];
            $row[] = $r['gold'];
            $row[] = $r['silver'];
            $row[] = $r['bronze'];
            $row[] = $r['gold'] + $r['silver'] + $r['bronze'];
            fputcsv($out, $row);
        }
        fputcsv($out, []);
    }
    fclose($out);
    exit;
}

if ($export === 'medals') {
    $headers = ['Division', 'Rank', 'Team', 'Gold', 'Silver', 'Bronze', 'Total Medals', 'Total Points'];
    $rows = [];
    foreach ($byDivision as $group) {
        foreach ($group['medal_tally'] as $r) {
            $medalTotal = (int) ($r['medal_total'] ?? ($r['gold'] + $r['silver'] + $r['bronze']));
            if ($medalTotal === 0 && $r['total'] === 0) {
                continue;
            }
            $rows[] = [
                $group['division_name'],
                $r['medal_rank'],
                $r['team_name'],
                $r['gold'],
                $r['silver'],
                $r['bronze'],
                $medalTotal,
                $r['total'],
            ];
        }
    }
    exportCsv('medal-tally-' . date('Ymd') . '.csv', $headers, $rows);
}

$pageTitle = 'Overall Intramurals Standing';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
    <div>
        <h1><i class="bi bi-award"></i> Overall Intramurals Standing</h1>
        <p class="text-muted mb-0">Medal tally and placement points by division — event columns match each division’s activated events</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (canManageIntramurals()): ?>
        <a href="<?= BASE_URL ?>/intramurals/points/index.php" class="btn btn-outline-secondary"><i class="bi bi-calculator"></i> Point System</a>
        <a href="<?= BASE_URL ?>/admin/divisions/index.php" class="btn btn-outline-secondary"><i class="bi bi-diagram-3"></i> Divisions</a>
        <?php endif; ?>
        <?php if (canManageEventRankings()): ?>
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

<?php if (empty($byDivision)): ?>
<div class="alert alert-info">No teams yet.</div>
<?php endif; ?>

<?php foreach ($byDivision as $group): ?>
<?php
$champion = $group['champion'] ?? null;
$medalLeader = $group['medal_leader'] ?? null;
$medalTotals = $group['medal_totals'] ?? ['gold' => 0, 'silver' => 0, 'bronze' => 0, 'all' => 0];
$medalTally = $group['medal_tally'] ?? [];
$divStandings = $group['standings'] ?? [];
$divLabels = $group['sport_labels'] ?? [];
$eventHeaders = $group['event_headers'] ?? [];
$activatedCount = (int) ($group['activated_event_count'] ?? count($divLabels));
?>
<section class="mb-5 overall-division-block">
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="bi bi-diagram-3"></i> <?= sanitize($group['division_name']) ?></h2>
            <p class="text-muted mb-0 small">
                <?= $activatedCount ?> activated event<?= $activatedCount === 1 ? '' : 's' ?>
                <?php if ($champion): ?>
                · Points leader:
                <strong style="color:<?= sanitize($champion['color']) ?>"><?= sanitize($champion['team_name']) ?></strong>
                (<?= (int) $champion['total'] ?> pts)
                <?php endif; ?>
                <?php if ($medalLeader): ?>
                · Medal leader:
                <strong style="color:<?= sanitize($medalLeader['color']) ?>"><?= sanitize($medalLeader['team_name']) ?></strong>
                <?php endif; ?>
            </p>
        </div>
        <small class="text-muted">
            <?= (int) $medalTotals['gold'] ?>G / <?= (int) $medalTotals['silver'] ?>S / <?= (int) $medalTotals['bronze'] ?>B
        </small>
    </div>

    <?php if ($activatedCount === 0 && (int) ($group['division_key'] ?? 0) > 0): ?>
    <div class="alert alert-warning">
        No events are activated for this division yet. Assign events under
        <a href="<?= BASE_URL ?>/admin/divisions/edit.php?id=<?= (int) ($group['division_id'] ?? 0) ?>" class="alert-link">Admin → Divisions</a>.
    </div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header">
            <i class="bi bi-trophy"></i> Medal Tally — <?= sanitize($group['division_name']) ?>
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
                            <td class="sticky-col"><?= (int) $r['medal_rank'] ?></td>
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
                        <?php if (empty($medalTally)): ?>
                        <tr><td colspan="7" class="text-muted p-3">No teams in this division.</td></tr>
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
            <span>
                <i class="bi bi-table"></i> Points Breakdown by Event — <?= sanitize($group['division_name']) ?>
                <span class="badge bg-secondary ms-1"><?= $activatedCount ?> event<?= $activatedCount === 1 ? '' : 's' ?></span>
            </span>
            <small class="text-muted no-print">Only events activated for this division</small>
        </div>
        <div class="card-body p-0">
            <?php if ($activatedCount === 0): ?>
            <div class="p-4 text-muted">No activated events to show for this division.</div>
            <?php else: ?>
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
                        <?php foreach ($divStandings as $r): ?>
                        <tr class="<?= $champion && $r['team_id'] === $champion['team_id'] ? 'table-success' : '' ?>">
                            <td class="sticky-col"><?= (int) ($r['division_rank'] ?? $r['rank']) ?></td>
                            <td class="sticky-col sticky-col-2">
                                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= sanitize($r['color']) ?>"></span>
                                <?= sanitize($r['team_name']) ?>
                            </td>
                            <?php foreach ($divLabels as $label): ?>
                            <td><?= $r['sports'][$label] ?? 0 ?></td>
                            <?php endforeach; ?>
                            <td><strong><?= (int) $r['total'] ?></strong></td>
                            <td class="medal-tally-col gold"><?= (int) $r['gold'] ?></td>
                            <td class="medal-tally-col silver"><?= (int) $r['silver'] ?></td>
                            <td class="medal-tally-col bronze"><?= (int) $r['bronze'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($divStandings)): ?>
                        <tr><td colspan="<?= 4 + count($divLabels) ?>" class="text-muted p-3">No teams in this division.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endforeach; ?>

<?= renderReportFooter('Overall Standing') ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
