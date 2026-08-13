<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
requireStandingsAccess();

$db = getDB();
$sportId = (int) get('sport');
$export = get('export');
$sports = filterSportsForUser($db->query('SELECT * FROM intramural_sports ORDER BY name, category')->fetchAll());

if (!$sportId && $sports) {
    $sportId = (int) $sports[0]['id'];
} elseif ($sportId && !canViewEvent($sportId)) {
    flash('error', 'You do not have permission to view standings for this event.');
    redirect(BASE_URL . '/intramurals/standings/index.php');
}

$blocks = $sportId ? computeSportStandings($sportId) : [];
$block = $blocks[$sportId] ?? null;

if ($export === 'excel' && $block) {
    $headers = ['Rank', 'Placement', 'Team', 'Played', 'Wins', 'Losses', 'Draws', 'Match Pts', 'Event Pts', 'Diff', 'Medal'];
    $rows = [];
    foreach ($block['standings'] as $r) {
        if ($r['played'] === 0 && empty($r['manual_rank'])) continue;
        $rows[] = [$r['rank'], $r['placement_label'] ?: '', $r['team_name'], $r['played'], $r['wins'], $r['losses'], $r['draws'], $r['points'], $r['placement_points'], $r['diff'], $r['medal'] ?: ''];
    }
    exportCsv('standings-' . date('Ymd') . '.csv', $headers, $rows);
}

$pageTitle = 'Result Tabulation';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
    <div>
        <h1><i class="bi bi-bar-chart-steps"></i> Result Tabulation</h1>
        <p class="text-muted mb-0">Automatic rankings, points, and medals per sport</p>
    </div>
    <div class="d-flex gap-2">
        <?php if (canManageMatches()): ?>
        <a href="<?= BASE_URL ?>/intramurals/rankings/index.php<?= $sportId ? '?sport=' . (int) $sportId : '' ?>" class="btn btn-outline-warning"><i class="bi bi-list-ol"></i> Enter Event Ranks</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/intramurals/standings/overall.php" class="btn btn-outline-primary">Overall Standing</a>
        <?php if ($block): ?>
        <a href="?sport=<?= $sportId ?>&export=excel" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        <button class="btn btn-outline-secondary" onclick="printReport()"><i class="bi bi-printer"></i> Print / PDF</button>
        <?php endif; ?>
    </div>
</div>

<?= renderReportHeader('Result Tabulation', [
    'meta' => $block ? ('Event: ' . sportLabel($block['sport'])) : '',
]) ?>

<div class="filter-bar no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-6">
            <label class="form-label">Sport</label>
            <select name="sport" class="form-select" onchange="this.form.submit()">
                <?php foreach ($sports as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $sportId === (int) $s['id'] ? 'selected' : '' ?>><?= sanitize(sportLabel($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if (!$block): ?>
<div class="alert alert-info">No sports available.</div>
<?php else: ?>
<div class="card mb-3">
    <?php $scheme = $block['scheme'] ?? getPointSchemeForSport($block['sport']); ?>
    <div class="card-header">
        <?= sanitize(sportLabel($block['sport'])) ?>
        <?php if (!empty($block['manual_ranks'])): ?>
        <span class="badge bg-info text-dark ms-1">Manual ranks</span>
        <?php endif; ?>
        <small class="text-muted ms-2">
            Scheme: <?= sanitize($scheme['name'] ?? 'Default') ?> (<?= sanitize(formatSchemePoints($scheme)) ?>)
            · Match W/D/L <?= (int) $block['sport']['win_points'] ?>/<?= (int) $block['sport']['draw_points'] ?>/<?= (int) $block['sport']['loss_points'] ?>
        </small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Placement</th>
                        <th>Team</th>
                        <th>Played</th>
                        <th>Wins</th>
                        <th>Losses</th>
                        <th>Draws</th>
                        <th>Match Pts</th>
                        <th>Event Pts</th>
                        <th>Diff</th>
                        <th>Medal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($block['standings'] as $r): ?>
                    <?php if ($r['played'] === 0 && empty($r['manual_rank'])) continue; ?>
                    <tr>
                        <td><?= $r['rank'] >= 1000 ? '—' : $r['rank'] ?></td>
                        <td>
                            <?= sanitize($r['placement_label'] ?: '-') ?>
                            <?php if (!empty($r['manual_rank'])): ?>
                            <span class="badge bg-info text-dark ms-1">Manual</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= sanitize($r['color']) ?>"></span>
                            <?= sanitize($r['team_name']) ?>
                        </td>
                        <td><?= $r['played'] ?></td>
                        <td><?= $r['wins'] ?></td>
                        <td><?= $r['losses'] ?></td>
                        <td><?= $r['draws'] ?></td>
                        <td><?= $r['points'] ?></td>
                        <td><strong><?= (int) $r['placement_points'] ?></strong></td>
                        <td><?= $r['diff'] ?></td>
                        <td>
                            <?php if ($r['medal'] === 'gold'): ?><span class="badge bg-warning text-dark">Gold</span>
                            <?php elseif ($r['medal'] === 'silver'): ?><span class="badge bg-secondary">Silver</span>
                            <?php elseif ($r['medal'] === 'bronze'): ?><span class="badge bg-danger">Bronze</span>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<p class="text-muted small no-print">Event Pts (Champion → 5th Runner Up) feed the <a href="<?= BASE_URL ?>/intramurals/standings/overall.php">Overall Standing</a>.
Use <a href="<?= BASE_URL ?>/intramurals/rankings/index.php<?= $sportId ? '?sport=' . (int) $sportId : '' ?>">Event Rankings</a> to enter places directly for medal tally.
<?php if (canManageIntramurals()): ?> Manage point values in <a href="<?= BASE_URL ?>/intramurals/points/index.php">Point System</a>.<?php endif; ?></p>
<?= renderReportFooter($block ? sportLabel($block['sport']) : 'Result Tabulation') ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
