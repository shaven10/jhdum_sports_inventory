<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole(['admin']);
ensureIntramuralDivisionsSchema();
ensureEventTeamPositionsTable();

$divisions = array_values(array_filter(getDivisions(true), static function ($d) {
    return divisionHasPositioning((int) $d['id']);
}));

$pageTitle = 'Team Positions';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-list-ol"></i> Team Positions</h1>
        <p class="text-muted mb-0">Set Team 1…N for each event (N = number of teams in the division). Used when generating match fixtures. Only divisions with more than 2 teams are listed.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/admin/divisions/index.php" class="btn btn-outline-primary">Divisions</a>
        <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-outline-secondary">Back to Admin</a>
    </div>
</div>

<?php if (empty($divisions)): ?>
<div class="alert alert-info">
    No eligible divisions yet. Assign <strong>more than 2 teams</strong> to a division under
    <a href="<?= BASE_URL ?>/admin/divisions/index.php">Divisions</a>, then return here to set team positions per event.
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($divisions as $d): ?>
    <?php
        $divId = (int) $d['id'];
        $sportCount = count(getDivisionSportIds($divId));
        $teamCount = count(getTeamIdsInDivision($divId, true));
    ?>
    <div class="col-md-6 col-xl-4">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="mb-1"><?= sanitize($d['name']) ?></h5>
                <div class="text-muted small mb-3">
                    <?= $teamCount ?> teams · <?= $sportCount ?> event<?= $sportCount === 1 ? '' : 's' ?>
                </div>
                <a href="<?= BASE_URL ?>/admin/team_positions/edit.php?division_id=<?= $divId ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-list-ol"></i> Set positions
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
