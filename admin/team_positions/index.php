<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole(['admin']);
ensureIntramuralDivisionsSchema();
ensureEventTeamPositionsTable();

$db = getDB();
$divisions = array_values(array_filter(getDivisions(true), static function ($d) {
    return divisionHasPositioning((int) $d['id']);
}));

$season = getCurrentSeason();
$seasonMeta = $season
    ? ('Season: ' . ($season['year_label'] ?? ('#' . (int) ($season['id'] ?? 0))))
    : 'No active season';

/** @var list<array{division:array,team_count:int,sport_count:int,teams:list<array>,sports:list<array>,positions:array<int,array<int,int>>}> $reportBlocks */
$reportBlocks = [];
foreach ($divisions as $d) {
    $divId = (int) $d['id'];
    $teamRows = $db->prepare('SELECT id, name, short_name FROM intramural_teams WHERE division_id = ? AND is_active = 1 ORDER BY name');
    $teamRows->execute([$divId]);
    $teams = $teamRows->fetchAll() ?: [];

    $sportIds = getDivisionSportIds($divId);
    $sports = [];
    if ($sportIds) {
        $placeholders = implode(',', array_fill(0, count($sportIds), '?'));
        $stmt = $db->prepare("SELECT * FROM intramural_sports WHERE id IN ($placeholders) ORDER BY " . intramuralSportsOrderBy());
        $stmt->execute($sportIds);
        $sports = $stmt->fetchAll() ?: [];
    }

    $positions = [];
    foreach ($sports as $s) {
        $positions[(int) $s['id']] = getEventTeamPositions($divId, (int) $s['id']);
    }

    $reportBlocks[] = [
        'division' => $d,
        'team_count' => count($teams),
        'sport_count' => count($sports),
        'teams' => $teams,
        'sports' => $sports,
        'positions' => $positions,
        'slots' => getDivisionPositionSlots($divId),
    ];
}

$pageTitle = 'Team Positions';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
    <div>
        <h1><i class="bi bi-list-ol"></i> Team Positions</h1>
        <p class="text-muted mb-0">Set Team 1…N for each event (N = number of teams in the division). Used when generating match fixtures. Only divisions with more than 2 teams are listed.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (!empty($reportBlocks)): ?>
        <button type="button" class="btn btn-outline-secondary" onclick="printReport()">
            <i class="bi bi-printer"></i> Print / PDF
        </button>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/admin/divisions/index.php" class="btn btn-outline-primary">Divisions</a>
        <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-outline-secondary">Back to Admin</a>
    </div>
</div>

<?= renderReportHeader('Team Positions', [
    'subtitle' => 'Event seeding order by division (Team 1 vs Team 2, Team 3 vs Team 4, …)',
    'meta' => $seasonMeta,
]) ?>

<?php if (empty($divisions)): ?>
<div class="alert alert-info no-print">
    No eligible divisions yet. Assign <strong>more than 2 teams</strong> to a division under
    <a href="<?= BASE_URL ?>/admin/divisions/index.php">Divisions</a>, then return here to set team positions per event.
</div>
<?php else: ?>
<div class="row g-3 no-print">
    <?php foreach ($reportBlocks as $block): ?>
    <?php $divId = (int) $block['division']['id']; ?>
    <div class="col-md-6 col-xl-4">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="mb-1"><?= sanitize($block['division']['name']) ?></h5>
                <div class="text-muted small mb-3">
                    <?= (int) $block['team_count'] ?> teams · <?= (int) $block['sport_count'] ?> event<?= (int) $block['sport_count'] === 1 ? '' : 's' ?>
                </div>
                <a href="<?= BASE_URL ?>/admin/team_positions/edit.php?division_id=<?= $divId ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-list-ol"></i> Set positions
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="team-positions-print-report d-none d-print-block">
    <?php foreach ($reportBlocks as $block): ?>
    <?php
        $teamNameById = [];
        foreach ($block['teams'] as $t) {
            $label = (string) $t['name'];
            if (!empty($t['short_name'])) {
                $label .= ' (' . $t['short_name'] . ')';
            }
            $teamNameById[(int) $t['id']] = $label;
        }
        $slots = $block['slots'];
    ?>
    <section class="team-positions-print-division mb-4">
        <h2 class="h4 mb-1"><?= sanitize($block['division']['name']) ?></h2>
        <p class="text-muted small mb-3">
            <?= (int) $block['team_count'] ?> teams · <?= (int) $block['sport_count'] ?> event<?= (int) $block['sport_count'] === 1 ? '' : 's' ?>
        </p>
        <?php if (empty($block['sports'])): ?>
        <p class="text-muted">No events assigned to this division.</p>
        <?php else: ?>
        <?php foreach ($block['sports'] as $s): ?>
        <?php
            $sid = (int) $s['id'];
            $pos = $block['positions'][$sid] ?? [];
        ?>
        <h3 class="h6 mb-2"><?= sanitize(sportLabel($s)) ?>
            <span class="text-muted fw-normal">· <?= sanitize(tournamentFormatLabel($s['tournament_format'] ?? 'round_robin')) ?></span>
        </h3>
        <table class="table table-sm table-bordered mb-3">
            <thead>
                <tr>
                    <th style="width: 7rem;">Position</th>
                    <th>Team</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($slots as $slot): ?>
                <?php
                    $tid = (int) ($pos[$slot] ?? 0);
                    $teamLabel = $tid > 0 ? ($teamNameById[$tid] ?? ('Team #' . $tid)) : '— Not set —';
                ?>
                <tr>
                    <td>Team <?= (int) $slot ?></td>
                    <td><?= sanitize($teamLabel) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endforeach; ?>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>
</div>

<p class="text-muted small mt-3 no-print">For PDF: click Print / PDF and choose “Save as PDF”.</p>
<?php endif; ?>

<?= renderReportFooter('Team Positions') ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
