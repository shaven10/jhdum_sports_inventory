<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();

$db = getDB();
$id = (int) get('id');

$stmt = $db->prepare("SELECT m.*, s.name as sport_name, s.category as sport_category, s.scoring_method,
    s.tournament_format, s.format_notes,
    ta.name as team_a_name, ta.color as team_a_color,
    tb.name as team_b_name, tb.color as team_b_color,
    tw.name as winner_name
    FROM intramural_matches m
    JOIN intramural_sports s ON m.sport_id = s.id
    LEFT JOIN intramural_teams ta ON m.team_a_id = ta.id
    LEFT JOIN intramural_teams tb ON m.team_b_id = tb.id
    LEFT JOIN intramural_teams tw ON m.winner_team_id = tw.id
    WHERE m.id = ?");
$stmt->execute([$id]);
$match = $stmt->fetch();

if (!$match) {
    flash('error', 'Match not found.');
    redirect(BASE_URL . '/intramurals/matches/index.php');
}

requireMatchViewAccess($match);

maybeCancelUnneededDecidingRubber($db, $id);
$stmt->execute([$id]);
$match = $stmt->fetch();
$decidingRubberDisabled = isDecidingRubberDisabled($db, $match);
$decidingRubberDisabledAlert = decidingRubberDisabledAlert($db, $match);

$seasonId = getCurrentSeasonId();
$sportId = (int) $match['sport_id'];
$teamAId = (int) ($match['team_a_id'] ?? 0);
$teamBId = (int) ($match['team_b_id'] ?? 0);
$teamsReady = $teamAId > 0 && $teamBId > 0;
$fromDashboard = get('from') === 'dashboard';
$backUrl = $fromDashboard ? (BASE_URL . '/dashboard.php') : (BASE_URL . '/intramurals/matches/index.php');
$fromQuery = $fromDashboard ? '&from=dashboard' : '';

// Quick live score / status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    if (canRecordScores($sportId) && canManageEventMatches($sportId)) {
        requireWritableSeason();
        requireUnlockedResults(null, $sportId);
        $action = post('action');
        if ($action === 'live_score') {
            $result = recordIntramuralMatchScore(
                $id,
                (int) post('score_a'),
                (int) post('score_b'),
                post('status', 'ongoing')
            );
            if (!empty($result['ok'])) {
                flash('success', $result['message'] ?: 'Score updated.');
            } else {
                flash('error', $result['error'] ?: 'Could not update score.');
            }
            redirect(BASE_URL . '/intramurals/matches/view.php?id=' . $id . ($fromDashboard ? '&from=dashboard' : ''));
        }
    }
}

$pageTitle = 'Match Details';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
echo renderResultsLockAlerts();
?>

<div class="match-details-page">
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-trophy"></i> <?= sanitize($match['sport_name']) ?> <small class="text-muted">(<?= ucfirst($match['sport_category']) ?>)</small></h1>
        <p class="match-details-meta text-muted mb-0">
            <?php if (!empty($match['game_number'])): ?>
            <span class="badge bg-dark"><?= formatVenueGameNumber((int) $match['game_number']) ?></span> ·
            <?php endif; ?>
            <?= formatDateTime($match['scheduled_at']) ?> · <?= sanitize($match['venue'] ?: 'TBA') ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if (canManageEventMatches((int) $match['sport_id'])): ?>
        <a href="<?= BASE_URL ?>/intramurals/matches/schedule.php?id=<?= $id ?><?= $fromQuery ?>" class="btn btn-<?= empty($match['scheduled_at']) ? 'warning' : 'outline-primary' ?>">
            <?= empty($match['scheduled_at']) ? 'Set Date/Time' : 'Reschedule' ?>
        </a>
        <?php
        if (isBracketTournamentFormat($match['tournament_format'] ?? '') && !isResultsLocked(null, $sportId)):
        ?>
        <form method="POST" action="<?= BASE_URL ?>/intramurals/matches/advance.php" class="d-inline">
            <?= csrfField() ?>
            <input type="hidden" name="sport_id" value="<?= (int) $match['sport_id'] ?>">
            <input type="hidden" name="return_to" value="<?= BASE_URL ?>/intramurals/matches/view.php?id=<?= $id ?><?= $fromQuery ?>">
            <button type="submit" class="btn btn-outline-success" title="Fill TBD teams from previous results">
                <i class="bi bi-diagram-3"></i> Update Bracket
            </button>
        </form>
        <?php endif; ?>
        <?php if (canRecordScores($sportId)): ?>
        <a href="<?= BASE_URL ?>/intramurals/matches/edit.php?id=<?= $id ?>" class="btn btn-primary"><?= canManageIntramurals() ? 'Edit / Record Score' : 'Record Score' ?></a>
        <?php endif; ?>
        <?php if (canUseScoringDesk()): ?>
        <a href="<?= BASE_URL ?>/intramurals/scoring/event.php?sport=<?= (int) $match['sport_id'] ?>&tab=scores" class="btn btn-outline-warning"><i class="bi bi-pencil-square"></i> Scores & Rankings</a>
        <?php endif; ?>
        <?php endif; ?>
        <?php if (canDeleteAllMatches()): ?>
        <form method="POST" action="<?= BASE_URL ?>/intramurals/matches/delete_generated.php" class="d-inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="single">
            <input type="hidden" name="match_id" value="<?= (int) $id ?>">
            <input type="hidden" name="return" value="<?= sanitize($backUrl) ?>">
            <button type="submit" class="btn btn-outline-danger" data-confirm="Delete this match permanently?">
                <i class="bi bi-trash"></i> Delete Match
            </button>
        </form>
        <?php endif; ?>
        <?php if ($teamAId || $teamBId): ?>
        <span class="text-muted small align-self-center d-none d-md-inline">Click a team name for official roster</span>
        <?php endif; ?>
        <a href="<?= sanitize($backUrl) ?>" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card match-scoreboard-card">
            <div class="card-body text-center py-4">
                <div class="row align-items-center g-2">
                    <div class="col-5">
                        <div class="match-team-name fw-bold"><?= matchTeamRosterTrigger($match, 'a') ?></div>
                    </div>
                    <div class="col-2">
                        <?php if ($match['score_a'] !== null && $match['score_b'] !== null): ?>
                        <div class="match-score fw-bold"><?= (int) $match['score_a'] ?>–<?= (int) $match['score_b'] ?></div>
                        <?php else: ?>
                        <div class="match-score-vs text-muted">VS</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-5">
                        <div class="match-team-name fw-bold"><?= matchTeamRosterTrigger($match, 'b') ?></div>
                    </div>
                </div>
                <div class="mt-3 match-status-line"><?= statusBadge($match['status']) ?></div>
                <?php if ($match['winner_name']): ?>
                <div class="mt-2 match-result-line text-success"><i class="bi bi-award"></i> Winner: <strong><?= sanitize($match['winner_name']) ?></strong></div>
                <?php elseif ($match['status'] === 'completed' && $match['score_a'] === $match['score_b']): ?>
                <div class="mt-2 match-result-line text-muted">Draw</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($decidingRubberDisabled && $decidingRubberDisabledAlert): ?>
        <div class="alert alert-secondary mt-3 mb-0">
            <i class="bi bi-slash-circle"></i>
            <strong>Deciding rubber disabled.</strong> <?= sanitize($decidingRubberDisabledAlert) ?>
        </div>
        <?php endif; ?>

        <?php if ($teamsReady && canRecordScores($sportId) && in_array($match['status'], ['scheduled', 'ongoing'], true) && !$decidingRubberDisabled): ?>
        <div class="card mt-3 match-live-score-card">
            <div class="card-header">Live Score Update</div>
            <div class="card-body">
                <form method="POST" class="row g-2 align-items-end">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="live_score">
                    <div class="col-md-3">
                        <label class="form-label"><?= sanitize($match['team_a_name'] ?? 'Team A') ?></label>
                        <input type="number" name="score_a" class="form-control form-control-lg" min="0" value="<?= (int) ($match['score_a'] ?? 0) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= sanitize($match['team_b_name'] ?? 'Team B') ?></label>
                        <input type="number" name="score_b" class="form-control form-control-lg" min="0" value="<?= (int) ($match['score_b'] ?? 0) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select form-select-lg">
                            <option value="ongoing" <?= $match['status'] === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                    <div class="col-md-3"><button class="btn btn-primary btn-lg w-100">Update Score</button></div>
                </form>
            </div>
        </div>
        <?php elseif (canRecordScores($sportId) && in_array($match['status'], ['scheduled', 'ongoing'], true) && !$teamsReady): ?>
        <div class="alert alert-info mt-3 mb-0">
            <i class="bi bi-info-circle"></i> Assign both teams (via bracket update or schedule edit) before recording scores.
        </div>
        <?php endif; ?>
    </div>
    <div class="col-lg-5">
        <div class="card match-info-card">
            <div class="card-header">Details</div>
            <div class="card-body">
                <dl class="row mb-0 match-info-list">
                    <dt class="col-5">Tournament style</dt>
                    <dd class="col-7">
                        <span class="badge bg-info text-dark"><?= sanitize(tournamentFormatLabel($match['tournament_format'] ?? 'round_robin')) ?></span>
                        <?php if (!empty($match['format_notes'])): ?>
                        <div class="match-info-note text-muted mt-1"><?= sanitize($match['format_notes']) ?></div>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-5">Scoring</dt><dd class="col-7"><?= ucfirst($match['scoring_method']) ?></dd>
                    <dt class="col-5">Referee</dt><dd class="col-7"><?= sanitize($match['referee_name'] ?: '-') ?></dd>
                    <dt class="col-5">Venue game #</dt>
                    <dd class="col-7"><?= !empty($match['game_number']) ? sanitize(formatVenueGameNumber((int) $match['game_number'])) : '—' ?></dd>
                    <dt class="col-5">Venue</dt><dd class="col-7"><?= sanitize($match['venue'] ?: '-') ?></dd>
                    <dt class="col-5">Notes</dt><dd class="col-7"><?= nl2br(sanitize($match['notes'] ?: '-')) ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>
</div>

<?php require __DIR__ . '/_roster_dialog.php'; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
