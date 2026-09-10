<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
requireScoringDeskAccess();
ensureEventRanksTable();
ensureSportEventGroupColumn();

$db = getDB();
$seasonId = getCurrentSeasonId();
$season = getCurrentSeason();
$sports = filterSportsForUser($db->query('SELECT * FROM intramural_sports ORDER BY ' . intramuralSportsOrderBy())->fetchAll());

$matchStats = [];
$rankedSports = [];
if ($seasonId) {
    $stmt = $db->prepare("SELECT sport_id,
            COUNT(*) AS total,
            SUM(CASE WHEN status IN ('scheduled', 'ongoing') THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status IN ('completed', 'forfeit') THEN 1 ELSE 0 END) AS finished
        FROM intramural_matches
        WHERE season_id = ? AND status <> 'cancelled'
        GROUP BY sport_id");
    $stmt->execute([$seasonId]);
    foreach ($stmt->fetchAll() as $row) {
        $matchStats[(int) $row['sport_id']] = [
            'total' => (int) $row['total'],
            'pending' => (int) $row['pending'],
            'finished' => (int) $row['finished'],
        ];
    }

    $rankStmt = $db->prepare('SELECT DISTINCT sport_id FROM intramural_event_ranks WHERE season_id = ?');
    $rankStmt->execute([$seasonId]);
    foreach ($rankStmt->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $rankedSports[(int) $sid] = true;
    }
}

$pageTitle = 'Scores & Rankings';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
echo renderResultsLockAlerts();
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-pencil-square"></i> Scores & Rankings</h1>
        <p class="text-muted mb-0">
            Enter match scores and official ranks per event
            <?= $season ? ' · ' . sanitize(seasonLabel($season)) : '' ?>
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/intramurals/rubrics/index.php" class="btn btn-outline-warning"><i class="bi bi-clipboard-check"></i> Event Rubrics</a>
        <a href="<?= BASE_URL ?>/intramurals/standings/overall.php" class="btn btn-outline-warning"><i class="bi bi-trophy"></i> Medal Tally</a>
        <a href="<?= BASE_URL ?>/intramurals/matches/index.php" class="btn btn-outline-secondary">All Matches</a>
    </div>
</div>

<?php if (!$seasonId): ?>
<div class="alert alert-warning">Set an active season before entering scores or ranks.</div>
<?php elseif (empty($sports)): ?>
<div class="alert alert-info">
    <?php if (isTournamentManager() && !canManageIntramurals()): ?>
    No events are assigned to your tournament manager account for this season.
    <?php else: ?>
    No events are available yet.
    <?php endif; ?>
</div>
<?php else: ?>

<div class="alert alert-secondary py-2">
    <i class="bi bi-info-circle"></i>
    Use <strong>Scores</strong> when the event has matches. Use <strong>Rankings</strong> for events without a match schedule.
    Socio-cultural events use <a href="<?= BASE_URL ?>/intramurals/rubrics/index.php" class="alert-link">Event Rubrics</a> so panel totals determine official ranks.
    Both feed overall standing and the medal tally.
</div>

<?php foreach (groupSportsByEventGroup($sports) as $groupKey => $groupSports): ?>
<?php if ($groupSports === []) continue; ?>
<div class="card mb-4">
    <div class="card-header fw-semibold">
        <?php if ($groupKey === 'socio_cultural'): ?>
        <i class="bi bi-palette"></i>
        <?php else: ?>
        <i class="bi bi-trophy"></i>
        <?php endif; ?>
        <?= sanitize(sportEventGroupLabel($groupKey)) ?>
        <span class="badge bg-secondary ms-1"><?= count($groupSports) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Event</th>
                    <th>Format</th>
                    <th>Matches</th>
                    <th>Rankings</th>
                    <th class="text-end" style="width:14rem;">Enter</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groupSports as $s): ?>
                <?php
                $sid = (int) $s['id'];
                $stats = $matchStats[$sid] ?? ['total' => 0, 'pending' => 0, 'finished' => 0];
                $isRankFormat = (($s['tournament_format'] ?? '') === 'rank_first_to_last');
                $isSocio = isSocioCulturalSport($s);
                $hasRubric = $isSocio && eventHasRubricCriteria($sid);
                $defaultTab = ($stats['total'] > 0 && !$isRankFormat) ? 'scores' : 'rankings';
                $openUrl = $hasRubric
                    ? BASE_URL . '/intramurals/rubrics/score.php?sport=' . $sid
                    : BASE_URL . '/intramurals/scoring/event.php?sport=' . $sid . '&tab=' . $defaultTab;
                $locked = isResultsLocked(null, $sid);
                ?>
                <tr>
                    <td>
                        <strong><?= sanitize($s['name']) ?></strong>
                        <span class="badge bg-secondary ms-1"><?= sanitize(ucfirst((string) $s['category'])) ?></span>
                        <?php if ($locked): ?>
                        <span class="badge bg-danger ms-1">Locked</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-info text-dark"><?= sanitize(tournamentFormatLabel($s['tournament_format'] ?? 'round_robin')) ?></span>
                    </td>
                    <td>
                        <?php if ($stats['total'] === 0): ?>
                        <span class="text-muted">No matches</span>
                        <?php else: ?>
                        <?= (int) $stats['finished'] ?>/<?= (int) $stats['total'] ?> finished
                        <?php if ($stats['pending'] > 0): ?>
                        <span class="badge bg-warning text-dark ms-1"><?= (int) $stats['pending'] ?> open</span>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($rankedSports[$sid])): ?>
                        <span class="badge bg-success">Ranks saved</span>
                        <?php elseif ($hasRubric): ?>
                        <span class="badge bg-light text-dark border">From rubric</span>
                        <?php elseif ($stats['total'] > 0 && !$isRankFormat): ?>
                        <span class="text-muted">From match results</span>
                        <?php else: ?>
                        <span class="badge bg-light text-dark border">Not ranked</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="<?= sanitize($openUrl) ?>" class="btn btn-sm btn-primary">
                            <i class="bi bi-box-arrow-in-right"></i> <?= $hasRubric ? 'Judge' : 'Open' ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
