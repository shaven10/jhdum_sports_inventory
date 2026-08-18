<?php
/**
 * Match schedule grouped by sport.
 *
 * @var array<int, array{sport_id:int,sport_name:string,sport_category:string,tournament_format:?string,matches:list<array>}> $scheduleGroups
 * @var bool $showActions
 * @var bool $plainTeamLabels
 */
$showActions = $showActions ?? false;
$plainTeamLabels = $plainTeamLabels ?? false;
?>
<?php if (empty($scheduleGroups)): ?>
<div class="text-muted p-3">No matches found.</div>
<?php else: ?>
<?php foreach ($scheduleGroups as $sportGroup): ?>
<?php
$sportMatchCount = count($sportGroup['matches']);
$sportUnscheduled = 0;
foreach ($sportGroup['matches'] as $gm) {
    if (empty($gm['scheduled_at'])) {
        $sportUnscheduled++;
    }
}
?>
<section class="match-schedule-sport-group border-bottom">
    <div class="match-schedule-sport-header d-flex justify-content-between align-items-center flex-wrap gap-2 px-3 py-2 bg-light border-bottom">
        <div>
            <strong><?= sanitize($sportGroup['sport_name']) ?></strong>
            <span class="badge bg-secondary ms-1"><?= ucfirst($sportGroup['sport_category']) ?></span>
            <span class="badge bg-info text-dark ms-1"><?= sanitize(tournamentFormatLabel($sportGroup['tournament_format'])) ?></span>
        </div>
        <span class="text-muted small">
            <?= $sportMatchCount ?> match<?= $sportMatchCount === 1 ? '' : 'es' ?>
            <?php if ($sportUnscheduled > 0): ?>
            · <?= $sportUnscheduled ?> unscheduled
            <?php endif; ?>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 match-schedule-table">
            <thead class="table-light">
                <tr>
                    <th>Date/Time</th>
                    <th>Round</th>
                    <th>Match</th>
                    <th>Score</th>
                    <th>Venue</th>
                    <th>Status</th>
                    <?php if ($showActions): ?><th class="text-end"></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sportGroup['matches'] as $m): ?>
                <tr class="<?= empty($m['scheduled_at']) ? 'table-warning' : '' ?>">
                    <td>
                        <?php if (!empty($m['scheduled_at'])): ?>
                        <?= formatDateTime($m['scheduled_at']) ?>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark">Unscheduled</span>
                        <?php endif; ?>
                    </td>
                    <td><?= sanitize($m['round_label'] ?: ('R' . (int) ($m['round_number'] ?? 1))) ?></td>
                    <td>
                        <?php if ($plainTeamLabels): ?>
                        <?= sanitize(matchTeamLabelPlain($m, 'a')) ?> vs <?= sanitize(matchTeamLabelPlain($m, 'b')) ?>
                        <?php else: ?>
                        <?= matchTeamRosterTrigger($m, 'a') ?>
                        vs
                        <?= matchTeamRosterTrigger($m, 'b') ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($m['score_a'] !== null && $m['score_b'] !== null): ?>
                        <strong><?= (int) $m['score_a'] ?> - <?= (int) $m['score_b'] ?></strong>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td><?= sanitize($m['venue'] ?: '-') ?></td>
                    <td><?= statusBadge($m['status']) ?></td>
                    <?php if ($showActions): ?>
                    <td class="text-nowrap text-end">
                        <a href="<?= BASE_URL ?>/intramurals/matches/view.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                        <?php if (canManageMatches()): ?>
                        <a href="<?= BASE_URL ?>/intramurals/matches/schedule.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-<?= empty($m['scheduled_at']) ? 'warning' : 'outline-secondary' ?>">
                            <?= empty($m['scheduled_at']) ? 'Set Date/Time' : 'Reschedule' ?>
                        </a>
                        <?php if (canManageIntramurals()): ?>
                        <a href="<?= BASE_URL ?>/intramurals/matches/edit.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                        <?php endif; ?>
                        <?php if (canDeleteAllMatches()): ?>
                        <form method="POST" action="<?= BASE_URL ?>/intramurals/matches/delete_generated.php" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="single">
                            <input type="hidden" name="match_id" value="<?= (int) $m['id'] ?>">
                            <input type="hidden" name="return" value="<?= sanitize($queryBase . '&page=' . $page) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Delete this match permanently?">Delete</button>
                        </form>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endforeach; ?>
<?php endif; ?>
