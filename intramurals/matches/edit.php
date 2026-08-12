<?php
require_once __DIR__ . '/../../includes/auth.php';
if (!canManageMatches()) {
    flash('error', 'You do not have permission to edit matches.');
    redirect(BASE_URL . '/intramurals/matches/index.php');
}
requireWritableSeason();

$db = getDB();
$id = (int) get('id');
$stmt = $db->prepare('SELECT * FROM intramural_matches WHERE id = ?');
$stmt->execute([$id]);
$match = $stmt->fetch();

if (!$match) {
    flash('error', 'Match not found.');
    redirect(BASE_URL . '/intramurals/matches/index.php');
}

$sports = $db->query('SELECT * FROM intramural_sports ORDER BY name, category')->fetchAll();
$teams = $db->query('SELECT * FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
$errors = [];

$currentSport = null;
foreach ($sports as $s) {
    if ((int) $s['id'] === (int) $match['sport_id']) {
        $currentSport = $s;
        break;
    }
}

$sportsMetaJson = [];
foreach ($sports as $s) {
    $sportsMetaJson[(int) $s['id']] = [
        'format' => tournamentFormatLabel($s['tournament_format'] ?? 'round_robin'),
        'notes' => trim((string) ($s['format_notes'] ?? '')),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/intramurals/matches/edit.php?id=' . $id);
    }

    $sportId = (int) post('sport_id');
    $teamA = (int) post('team_a_id');
    $teamB = (int) post('team_b_id');
    $scheduledAt = post('scheduled_at');
    $venue = post('venue');
    $referee = post('referee_name');
    $status = post('status', 'scheduled');
    $notes = post('notes');
    $scoreA = post('score_a') === '' ? null : (int) post('score_a');
    $scoreB = post('score_b') === '' ? null : (int) post('score_b');
    $forfeitTeam = (int) post('forfeit_team_id') ?: null;

    if (!$sportId || !$teamA || !$teamB) $errors[] = 'Sport and both teams are required.';
    if ($teamA === $teamB) $errors[] = 'Teams must be different.';
    if ($scheduledAt !== '' && strtotime($scheduledAt) === false) {
        $errors[] = 'Invalid schedule date/time.';
    }
    if (!in_array($status, ['scheduled', 'ongoing', 'completed', 'cancelled', 'forfeit'], true)) {
        $errors[] = 'Invalid status.';
    }
    if ($status === 'forfeit' && !$forfeitTeam) {
        $errors[] = 'Select which team forfeited.';
    }
    if (in_array($status, ['completed', 'ongoing'], true) && ($scoreA === null || $scoreB === null)) {
        $errors[] = 'Enter scores for ongoing/completed matches.';
    }

    if (empty($errors)) {
        $winner = determineMatchWinner($scoreA, $scoreB, $teamA, $teamB, $status, $forfeitTeam);
        if ($status === 'forfeit' && $scoreA === null && $scoreB === null) {
            // default forfeit score
            if ($forfeitTeam === $teamA) {
                $scoreA = 0;
                $scoreB = 1;
            } else {
                $scoreA = 1;
                $scoreB = 0;
            }
            $winner = determineMatchWinner($scoreA, $scoreB, $teamA, $teamB, $status, $forfeitTeam);
        }

        $scheduledValue = $scheduledAt !== '' ? date('Y-m-d H:i:s', strtotime($scheduledAt)) : null;
        $stmt = $db->prepare('UPDATE intramural_matches SET sport_id=?, team_a_id=?, team_b_id=?, scheduled_at=?, venue=?, referee_name=?, status=?, score_a=?, score_b=?, winner_team_id=?, forfeit_team_id=?, notes=? WHERE id=?');
        $stmt->execute([
            $sportId, $teamA, $teamB, $scheduledValue,
            $venue, $referee, $status, $scoreA, $scoreB, $winner, $forfeitTeam, $notes, $id
        ]);
        auditLog($_SESSION['user_id'], 'update', 'intramural_match', $id, null, ['status' => $status, 'score_a' => $scoreA, 'score_b' => $scoreB]);
        flash('success', 'Match updated.');
        redirect(BASE_URL . '/intramurals/matches/view.php?id=' . $id);
    }
}

$pageTitle = 'Edit Match';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
$dtLocal = $match['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($match['scheduled_at'])) : '';
?>

<div class="page-header"><h1><i class="bi bi-pencil"></i> Edit Match / Record Score</h1></div>

<div class="row"><div class="col-lg-8"><div class="card"><div class="card-body">
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="POST">
        <?= csrfField() ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Sport *</label>
                <select name="sport_id" id="matchSportId" class="form-select" required>
                    <?php foreach ($sports as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= (int) $match['sport_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= sanitize(sportLabel($s)) ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="sportFormatHint" class="form-text mt-2">
                    <?php if ($currentSport): ?>
                    <span class="badge bg-info text-dark">Agreed style: <?= sanitize(tournamentFormatLabel($currentSport['tournament_format'] ?? 'round_robin')) ?></span>
                    <?php if (!empty($currentSport['format_notes'])): ?>
                    <div class="small text-muted mt-1"><?= sanitize($currentSport['format_notes']) ?></div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Date & Time</label>
                <input type="datetime-local" name="scheduled_at" class="form-control" value="<?= sanitize(post('scheduled_at', $dtLocal)) ?>">
                <div class="form-text">Any date/time is allowed — not limited to season dates. Leave blank to keep unscheduled.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Team A *</label>
                <select name="team_a_id" class="form-select" required>
                    <?php foreach ($teams as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= (int) $match['team_a_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Team B *</label>
                <select name="team_b_id" class="form-select" required>
                    <?php foreach ($teams as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= (int) $match['team_b_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Venue</label>
                <input type="text" name="venue" class="form-control" value="<?= sanitize(post('venue', $match['venue'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Referee</label>
                <input type="text" name="referee_name" class="form-control" value="<?= sanitize(post('referee_name', $match['referee_name'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <?php foreach (['scheduled', 'ongoing', 'completed', 'forfeit', 'cancelled'] as $st): ?>
                    <option value="<?= $st ?>" <?= (post('status', $match['status']) === $st) ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Score A</label>
                <input type="number" name="score_a" class="form-control" min="0" value="<?= sanitize(post('score_a', $match['score_a'] !== null ? (string) $match['score_a'] : '')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Score B</label>
                <input type="number" name="score_b" class="form-control" min="0" value="<?= sanitize(post('score_b', $match['score_b'] !== null ? (string) $match['score_b'] : '')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Forfeit Team (if applicable)</label>
                <select name="forfeit_team_id" class="form-select">
                    <option value="">None</option>
                    <?php foreach ($teams as $t): ?>
                    <?php if ((int) $t['id'] === (int) $match['team_a_id'] || (int) $t['id'] === (int) $match['team_b_id']): ?>
                    <option value="<?= $t['id'] ?>" <?= (int) ($match['forfeit_team_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= sanitize(post('notes', $match['notes'] ?? '')) ?></textarea>
            </div>
        </div>
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">Save</button>
            <a href="<?= BASE_URL ?>/intramurals/matches/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div></div></div></div>

<script>
(function () {
    const meta = <?= json_encode($sportsMetaJson, JSON_UNESCAPED_UNICODE) ?>;
    const select = document.getElementById('matchSportId');
    const hint = document.getElementById('sportFormatHint');
    if (!select || !hint) return;
    function refresh() {
        const info = meta[select.value];
        if (!info) {
            hint.innerHTML = '';
            return;
        }
        let html = '<span class="badge bg-info text-dark">Agreed style: ' + info.format + '</span>';
        if (info.notes) {
            html += '<div class="small text-muted mt-1">' + info.notes.replace(/</g, '&lt;') + '</div>';
        }
        hint.innerHTML = html;
    }
    select.addEventListener('change', refresh);
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
