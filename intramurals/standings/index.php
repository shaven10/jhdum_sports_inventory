<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
requireStandingsAccess();

$db = getDB();
$seasonId = getCurrentSeasonId();
$sportParam = get('sport');
$sportId = ($sportParam !== '' && $sportParam !== null) ? (int) $sportParam : 0;
$showAllSports = $sportId === 0;
$export = get('export');
$sports = filterSportsForUser($db->query('SELECT * FROM intramural_sports ORDER BY ' . intramuralSportsOrderBy())->fetchAll());

if ($sportId && !canViewEvent($sportId)) {
    flash('error', 'You do not have permission to view standings for this event.');
    redirect(BASE_URL . '/intramurals/standings/index.php');
}

$normalizeDivisionBlocks = static function (array $block): array {
    $divisionBlocks = $block['divisions'] ?? [];
    if ($divisionBlocks === [] && !empty($block['standings'])) {
        return [[
            'division_id' => null,
            'division_key' => 0,
            'division_name' => 'All teams',
            'standings' => $block['standings'],
            'manual_ranks' => !empty($block['manual_ranks']),
        ]];
    }
    return $divisionBlocks;
};

$sportBlocks = [];
if ($showAllSports) {
    $computed = computeSportStandings(null);
    $allowedIds = array_flip(array_map('intval', array_column($sports, 'id')));
    $computed = array_intersect_key($computed, $allowedIds);
    foreach ($sports as $s) {
        $sid = (int) $s['id'];
        if (!isset($computed[$sid])) {
            continue;
        }
        $block = $computed[$sid];
        $block['divisions'] = $normalizeDivisionBlocks($block);
        $sportBlocks[$sid] = $block;
    }
} elseif ($sportId) {
    $computed = computeSportStandings($sportId);
    $block = $computed[$sportId] ?? null;
    if ($block) {
        $block['divisions'] = $normalizeDivisionBlocks($block);
        $sportBlocks[$sportId] = $block;
    }
}

if ($export === 'excel' && $sportBlocks) {
    $headers = ['Event', 'Division', 'Rank', 'Placement', 'Team', 'Played', 'Wins', 'Losses', 'Draws', 'Match Pts', 'Event Pts', 'Diff', 'Medal'];
    $rows = [];
    foreach ($sportBlocks as $block) {
        foreach ($block['divisions'] as $divBlock) {
            foreach ($divBlock['standings'] as $r) {
                if ($r['played'] === 0 && empty($r['manual_rank'])) {
                    continue;
                }
                $rows[] = [
                    sportLabel($block['sport']),
                    $divBlock['division_name'],
                    $r['rank'] >= 1000 ? '' : $r['rank'],
                    $r['placement_label'] ?: '',
                    $r['team_name'],
                    $r['played'],
                    $r['wins'],
                    $r['losses'],
                    $r['draws'],
                    $r['points'],
                    $r['placement_points'],
                    $r['diff'],
                    $r['medal'] ?: '',
                ];
            }
        }
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
        <p class="text-muted mb-0">Rankings, points, and medals per sport — grouped by division</p>
    </div>
    <div class="d-flex gap-2">
        <?php if (canManageEventRankings() && $sportId && $seasonId): ?>
        <a href="<?= BASE_URL ?>/intramurals/scoring/event.php?sport=<?= (int) $sportId ?>&tab=rankings" class="btn btn-outline-warning"><i class="bi bi-pencil-square"></i> Scores & Rankings</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/intramurals/standings/overall.php" class="btn btn-outline-primary">Overall Standing</a>
        <?php if ($sportBlocks): ?>
        <a href="?<?= $showAllSports ? '' : 'sport=' . $sportId . '&' ?>export=excel" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        <button class="btn btn-outline-secondary" onclick="printReport()"><i class="bi bi-printer"></i> Print / PDF</button>
        <?php endif; ?>
    </div>
</div>

<?= renderReportHeader('Result Tabulation', [
    'meta' => $showAllSports
        ? 'All events'
        : ($sportBlocks ? ('Event: ' . sportLabel(reset($sportBlocks)['sport'])) : ''),
]) ?>

<div class="filter-bar no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-6">
            <label class="form-label">Sport</label>
            <select name="sport" class="form-select" onchange="this.form.submit()">
                <option value="" <?= $showAllSports ? 'selected' : '' ?>>All Events</option>
                <?= renderSportSelectOptions($sports, $showAllSports ? 0 : $sportId) ?>
            </select>
        </div>
    </form>
</div>

<?php if (!$sportBlocks): ?>
<div class="alert alert-info">No events available.</div>
<?php else: ?>
<?php
$prevEventGroup = null;
foreach ($sportBlocks as $block):
    $blockGroup = sportEventGroupOf($block['sport'] ?? []);
    if ($showAllSports && $blockGroup !== $prevEventGroup):
        $prevEventGroup = $blockGroup;
?>
<h2 class="h5 mt-4 mb-3">
    <?php if ($blockGroup === 'socio_cultural'): ?>
    <i class="bi bi-palette"></i>
    <?php else: ?>
    <i class="bi bi-trophy"></i>
    <?php endif; ?>
    <?= sanitize(sportEventGroupLabel($blockGroup)) ?>
</h2>
<?php endif; ?>
<?php $divisionBlocks = $block['divisions']; ?>
<?php $scheme = $block['scheme'] ?? getPointSchemeForSport($block['sport']); ?>
<div class="alert alert-secondary py-2 no-print">
    <strong><?= sanitize(sportLabel($block['sport'])) ?></strong>
    · Scheme: <?= sanitize($scheme['name'] ?? 'Default') ?> (<?= sanitize(formatSchemePoints($scheme)) ?>)
    · Match W/D/L <?= (int) $block['sport']['win_points'] ?>/<?= (int) $block['sport']['draw_points'] ?>/<?= (int) $block['sport']['loss_points'] ?>
    <?php
    $standingsFormat = (string) ($block['sport']['tournament_format'] ?? '');
    $useBracketNote = in_array($standingsFormat, ['team_play_sds_consolation', 'single_elimination_consolation', 'team_play_sds'], true);
    $isSepakStandings = isSepakTakrawSport((string) ($block['sport']['name'] ?? ''));
    ?>
    <?php if ($isSepakStandings || $useBracketNote): ?>
    · Ranking: Final winner = Champion, Final loser = 1st Runner Up; consolation winner = 3rd, consolation loser = 4th. 3-regu / SDS ties count as one team result.
    <?php endif; ?>
</div>

<?php foreach ($divisionBlocks as $divBlock): ?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>
            <?php if ($showAllSports): ?>
            <strong><?= sanitize(sportLabel($block['sport'])) ?></strong>
            <span class="text-muted mx-1">·</span>
            <?php endif; ?>
            <i class="bi bi-diagram-3"></i> <?= sanitize($divBlock['division_name']) ?>
            <?php if (!empty($divBlock['manual_ranks'])): ?>
            <span class="badge bg-info text-dark ms-1">Manual ranks</span>
            <?php endif; ?>
        </span>
        <small class="text-muted"><?= count($divBlock['standings']) ?> team<?= count($divBlock['standings']) === 1 ? '' : 's' ?></small>
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
                    <?php
                    $shown = 0;
                    foreach ($divBlock['standings'] as $r):
                        if ($r['played'] === 0 && empty($r['manual_rank'])) {
                            continue;
                        }
                        $shown++;
                    ?>
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
                    <?php if ($shown === 0): ?>
                    <tr><td colspan="11" class="text-muted p-3">No results yet for this division.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php endforeach; ?>

<p class="text-muted small no-print">Event Pts (Champion → 5th Runner Up) feed the <a href="<?= BASE_URL ?>/intramurals/standings/overall.php">Overall Standing</a> by division.
<?php if (canManageEventRankings()): ?>
Use <a href="<?= BASE_URL ?>/intramurals/scoring/<?= $sportId ? 'event.php?sport=' . (int) $sportId . '&tab=rankings' : 'index.php' ?>">Scores & Rankings</a> to enter places per division for events without scheduled matches.
<?php endif; ?>
<?php if (canManageIntramurals()): ?> Manage point values in <a href="<?= BASE_URL ?>/intramurals/points/index.php">Point System</a>.<?php endif; ?></p>
<?= renderReportFooter($showAllSports ? 'All Events' : ($sportBlocks ? sportLabel(reset($sportBlocks)['sport']) : 'Result Tabulation')) ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
