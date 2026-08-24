<?php
/**
 * Match schedule grouped by sport or by play date + venue.
 *
 * @var array<int, array<string,mixed>> $scheduleGroups
 * @var bool $showActions
 * @var bool $plainTeamLabels
 * @var string $scheduleGroupMode sport|venue_day
 */
$showActions = $showActions ?? false;
$plainTeamLabels = $plainTeamLabels ?? false;
$scheduleGroupMode = $scheduleGroupMode ?? 'sport';
$byVenueDay = $scheduleGroupMode === 'venue_day';
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
            <?php if ($byVenueDay): ?>
            <?php if (!empty($sportGroup['day'])): ?>
            <strong><i class="bi bi-geo-alt"></i> <?= sanitize($sportGroup['venue_label'] ?? 'Venue TBD') ?></strong>
            <span class="badge bg-secondary ms-1"><?= formatDate($sportGroup['day']) ?></span>
            <?php else: ?>
            <strong>Unscheduled</strong>
            <?php endif; ?>
            <?php else: ?>
            <strong><?= sanitize($sportGroup['sport_name']) ?></strong>
            <span class="badge bg-secondary ms-1"><?= ucfirst($sportGroup['sport_category']) ?></span>
            <span class="badge bg-info text-dark ms-1"><?= sanitize(tournamentFormatLabel($sportGroup['tournament_format'])) ?></span>
            <?php endif; ?>
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
                    <th>Game #</th>
                    <th>Date/Time</th>
                    <?php if ($byVenueDay): ?><th>Event</th><?php endif; ?>
                    <th>Round</th>
                    <th>Match</th>
                    <th>Score</th>
                    <?php if (!$byVenueDay): ?><th>Venue</th><?php endif; ?>
                    <th>Status</th>
                    <?php if ($showActions): ?><th class="text-end"></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sportGroup['matches'] as $m): ?>
                <tr class="<?= empty($m['scheduled_at']) ? 'table-warning' : '' ?>">
                    <td>
                        <?php if (!empty($m['game_number'])): ?>
                        <span class="badge bg-dark"><?= (int) $m['game_number'] ?></span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($m['scheduled_at'])): ?>
                        <?= formatDateTime($m['scheduled_at']) ?>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark">Unscheduled</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($byVenueDay): ?>
                    <td>
                        <?= sanitize($m['sport_name'] ?? '') ?>
                        <?php if (!empty($m['sport_category'])): ?>
                        <span class="badge bg-secondary ms-1"><?= ucfirst($m['sport_category']) ?></span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
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
                    <?php if (!$byVenueDay): ?>
                    <td><?= sanitize($m['venue'] ?: '-') ?></td>
                    <?php endif; ?>
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
