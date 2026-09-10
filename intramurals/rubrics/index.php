<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
requireRubricsAccess();
ensureEventRubricTables();
ensureSportEventGroupColumn();

$db = getDB();
$seasonId = getCurrentSeasonId();
$season = getCurrentSeason();
$allSports = filterSportsForUser($db->query('SELECT * FROM intramural_sports ORDER BY ' . intramuralSportsOrderBy())->fetchAll());
$sports = array_values(array_filter($allSports, static fn($s) => isSocioCulturalSport($s)));

$criteriaCounts = [];
$criteriaMax = [];
if ($sports) {
    $ids = array_map(static fn($s) => (int) $s['id'], $sports);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT sport_id, COUNT(*) AS criteria_count, COALESCE(SUM(max_points), 0) AS max_total
        FROM intramural_event_rubric_criteria
        WHERE sport_id IN ($placeholders)
        GROUP BY sport_id");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $criteriaCounts[(int) $row['sport_id']] = (int) $row['criteria_count'];
        $criteriaMax[(int) $row['sport_id']] = (float) $row['max_total'];
    }
}

$scoredTeams = [];
if ($seasonId && $sports) {
    $ids = array_map(static fn($s) => (int) $s['id'], $sports);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT sport_id, COUNT(DISTINCT team_id) AS scored
        FROM intramural_event_rubric_scores
        WHERE season_id = ? AND sport_id IN ($placeholders)
        GROUP BY sport_id");
    $stmt->execute(array_merge([$seasonId], $ids));
    foreach ($stmt->fetchAll() as $row) {
        $scoredTeams[(int) $row['sport_id']] = (int) $row['scored'];
    }
}

$pageTitle = 'Event Rubrics';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
echo renderResultsLockAlerts();
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-clipboard-check"></i> Event Rubrics</h1>
        <p class="text-muted mb-0">
            Criteria and judging sheets for socio-cultural events. Panel totals determine official ranks.
            <?= $season ? ' · ' . sanitize(seasonLabel($season)) : '' ?>
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/intramurals/scoring/index.php" class="btn btn-outline-warning"><i class="bi bi-pencil-square"></i> Scores & Rankings</a>
        <a href="<?= BASE_URL ?>/intramurals/standings/overall.php" class="btn btn-outline-secondary"><i class="bi bi-trophy"></i> Medal Tally</a>
    </div>
</div>

<div class="alert alert-secondary py-2">
    <i class="bi bi-info-circle"></i>
    Define criteria (and their maximum points) per event, then enter panel scores on the judging sheet.
    Highest total is 1st place. Applied ranks still count in overall standing and the medal tally.
</div>

<?php if (empty($sports)): ?>
<div class="alert alert-info">
    <?php if (isTournamentManager() && !canManageIntramurals()): ?>
    No socio-cultural events are assigned to your tournament manager account.
    <?php else: ?>
    No socio-cultural events yet. Add them under <a href="<?= BASE_URL ?>/intramurals/sports/index.php" class="alert-link">Sports / Events</a>
    and set Event Group to Socio-Cultural.
    <?php endif; ?>
</div>
<?php else: ?>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Event</th>
                    <th>Criteria</th>
                    <th>Max pts</th>
                    <th>Scored this season</th>
                    <th class="text-end" style="width:16rem;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sports as $s): ?>
                <?php
                $sid = (int) $s['id'];
                $count = $criteriaCounts[$sid] ?? 0;
                $max = $criteriaMax[$sid] ?? 0;
                $scored = $scoredTeams[$sid] ?? 0;
                $canEdit = canManageEventRubricCriteria($sid);
                $canScore = canScoreEventRubrics($sid);
                ?>
                <tr>
                    <td>
                        <strong><?= sanitize($s['name']) ?></strong>
                        <span class="badge bg-secondary ms-1"><?= sanitize(ucfirst((string) $s['category'])) ?></span>
                    </td>
                    <td>
                        <?php if ($count > 0): ?>
                        <span class="badge bg-success"><?= $count ?> criterion<?= $count === 1 ? '' : 's' ?></span>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark">No rubric</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $count ? formatRubricPoints($max) : '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <?php if ($scored > 0): ?>
                        <?= $scored ?> team<?= $scored === 1 ? '' : 's' ?>
                        <?php else: ?>
                        <span class="text-muted">Not scored</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <?php if ($canEdit): ?>
                        <a href="<?= BASE_URL ?>/intramurals/rubrics/edit.php?sport=<?= $sid ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-sliders"></i> Criteria
                        </a>
                        <?php endif; ?>
                        <?php if ($canScore && $count > 0): ?>
                        <a href="<?= BASE_URL ?>/intramurals/rubrics/score.php?sport=<?= $sid ?>" class="btn btn-sm btn-primary">
                            <i class="bi bi-pencil-square"></i> Judge
                        </a>
                        <?php elseif ($count === 0 && $canEdit): ?>
                        <span class="text-muted small">Add criteria first</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
