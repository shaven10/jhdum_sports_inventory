<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

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

// Quick live score / status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canRecordScores() && verifyCsrf(post('csrf_token'))) {
    requireWritableSeason();
    $action = post('action');
    if ($action === 'live_score') {
        $scoreA = (int) post('score_a');
        $scoreB = (int) post('score_b');
        $status = post('status', 'ongoing');
        $winner = null;
        if ($status === 'completed') {
            $winner = determineMatchWinner($scoreA, $scoreB, (int) $match['team_a_id'], (int) $match['team_b_id'], 'completed');
        }
        $db->prepare('UPDATE intramural_matches SET score_a=?, score_b=?, status=?, winner_team_id=? WHERE id=?')
            ->execute([$scoreA, $scoreB, $status, $winner, $id]);
        auditLog($_SESSION['user_id'], 'score_update', 'intramural_match', $id, null, ['score_a' => $scoreA, 'score_b' => $scoreB, 'status' => $status]);
        flash('success', 'Score updated.');
        redirect(BASE_URL . '/intramurals/matches/view.php?id=' . $id);
    }
}

$pageTitle = 'Match Details';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-trophy"></i> <?= sanitize($match['sport_name']) ?> <small class="text-muted">(<?= ucfirst($match['sport_category']) ?>)</small></h1>
        <p class="text-muted mb-0"><?= formatDateTime($match['scheduled_at']) ?> · <?= sanitize($match['venue'] ?: 'TBA') ?></p>
    </div>
    <div class="d-flex gap-2">
        <?php if (canManageMatches()): ?>
        <a href="<?= BASE_URL ?>/intramurals/matches/schedule.php?id=<?= $id ?>" class="btn btn-<?= empty($match['scheduled_at']) ? 'warning' : 'outline-primary' ?>">
            <?= empty($match['scheduled_at']) ? 'Set Date/Time' : 'Reschedule' ?>
        </a>
        <?php if (canManageIntramurals()): ?>
        <a href="<?= BASE_URL ?>/intramurals/matches/edit.php?id=<?= $id ?>" class="btn btn-primary">Edit / Record Score</a>
        <?php elseif (canRecordScores()): ?>
        <a href="<?= BASE_URL ?>/intramurals/matches/edit.php?id=<?= $id ?>" class="btn btn-primary">Record Score</a>
        <?php endif; ?>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/intramurals/matches/index.php" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-body text-center py-4">
                <div class="row align-items-center">
                    <div class="col-5">
                        <div class="fs-4 fw-bold" style="color:<?= sanitize($match['team_a_color'] ?: '#666') ?>"><?= sanitize($match['team_a_name'] ?: 'TBD') ?></div>
                    </div>
                    <div class="col-2">
                        <?php if ($match['score_a'] !== null && $match['score_b'] !== null): ?>
                        <div class="display-6 fw-bold"><?= (int) $match['score_a'] ?>–<?= (int) $match['score_b'] ?></div>
                        <?php else: ?>
                        <div class="fs-3 text-muted">VS</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-5">
                        <div class="fs-4 fw-bold" style="color:<?= sanitize($match['team_b_color'] ?: '#666') ?>"><?= sanitize($match['team_b_name'] ?: 'TBD') ?></div>
                    </div>
                </div>
                <div class="mt-3"><?= statusBadge($match['status']) ?></div>
                <?php if ($match['winner_name']): ?>
                <div class="mt-2 text-success"><i class="bi bi-award"></i> Winner: <strong><?= sanitize($match['winner_name']) ?></strong></div>
                <?php elseif ($match['status'] === 'completed' && $match['score_a'] === $match['score_b']): ?>
                <div class="mt-2 text-muted">Draw</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (canRecordScores() && in_array($match['status'], ['scheduled', 'ongoing'], true)): ?>
        <div class="card mt-3">
            <div class="card-header">Live Score Update</div>
            <div class="card-body">
                <form method="POST" class="row g-2 align-items-end">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="live_score">
                    <div class="col-md-3">
                        <label class="form-label"><?= sanitize($match['team_a_name']) ?></label>
                        <input type="number" name="score_a" class="form-control" min="0" value="<?= (int) ($match['score_a'] ?? 0) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= sanitize($match['team_b_name']) ?></label>
                        <input type="number" name="score_b" class="form-control" min="0" value="<?= (int) ($match['score_b'] ?? 0) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="ongoing" <?= $match['status'] === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                    <div class="col-md-3"><button class="btn btn-primary w-100">Update Score</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">Details</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">Tournament style</dt>
                    <dd class="col-7">
                        <span class="badge bg-info text-dark"><?= sanitize(tournamentFormatLabel($match['tournament_format'] ?? 'round_robin')) ?></span>
                        <?php if (!empty($match['format_notes'])): ?>
                        <div class="small text-muted mt-1"><?= sanitize($match['format_notes']) ?></div>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-5">Scoring</dt><dd class="col-7"><?= ucfirst($match['scoring_method']) ?></dd>
                    <dt class="col-5">Referee</dt><dd class="col-7"><?= sanitize($match['referee_name'] ?: '-') ?></dd>
                    <dt class="col-5">Venue</dt><dd class="col-7"><?= sanitize($match['venue'] ?: '-') ?></dd>
                    <dt class="col-5">Notes</dt><dd class="col-7"><?= nl2br(sanitize($match['notes'] ?: '-')) ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
