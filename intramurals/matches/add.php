<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
if (!canManageMatches()) {
    flash('error', 'You do not have permission to schedule matches.');
    redirect(BASE_URL . '/intramurals/matches/index.php');
}
// Tournament managers generate fixtures then assign date/time — not free-form single matches
if ((hasRole('tabulator') || isSecretariat()) && !canManageIntramurals()) {
    redirect(BASE_URL . '/intramurals/matches/generate.php');
}
requireWritableSeason();

$db = getDB();
$seasonId = getCurrentSeasonId();
$sports = $db->query('SELECT * FROM intramural_sports ORDER BY name, category')->fetchAll();
$teams = $db->query('SELECT * FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/intramurals/matches/add.php');
    }

    $sportId = (int) post('sport_id');
    $teamA = (int) post('team_a_id');
    $teamB = (int) post('team_b_id');
    $scheduledAt = post('scheduled_at');
    $venue = post('venue');
    $referee = post('referee_name');
    $notes = post('notes');

    if (!$seasonId) $errors[] = 'No active intramurals season configured.';
    if (!$sportId) $errors[] = 'Sport is required.';
    if (!$teamA || !$teamB) $errors[] = 'Both teams are required.';
    if ($teamA && $teamB && $teamA === $teamB) $errors[] = 'Teams must be different.';
    if ($scheduledAt === '') $errors[] = 'Schedule date/time is required.';

    if (empty($errors)) {
        $stmt = $db->prepare('INSERT INTO intramural_matches (season_id, sport_id, team_a_id, team_b_id, scheduled_at, venue, referee_name, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$seasonId, $sportId, $teamA, $teamB, date('Y-m-d H:i:s', strtotime($scheduledAt)), $venue, $referee, $notes, $_SESSION['user_id']]);
        $id = (int) $db->lastInsertId();
        auditLog($_SESSION['user_id'], 'create', 'intramural_match', $id, null, ['season_id' => $seasonId]);
        flash('success', 'Match scheduled.');
        redirect(BASE_URL . '/intramurals/matches/view.php?id=' . $id);
    }
}

$pageTitle = 'Schedule Match';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';

$sportsMetaJson = [];
foreach ($sports as $s) {
    $sportsMetaJson[(int) $s['id']] = [
        'format' => tournamentFormatLabel($s['tournament_format'] ?? 'round_robin'),
        'notes' => trim((string) ($s['format_notes'] ?? '')),
    ];
}
?>

<div class="page-header"><h1><i class="bi bi-calendar-plus"></i> Schedule Match</h1></div>

<div class="row"><div class="col-lg-8"><div class="card"><div class="card-body">
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="POST">
        <?= csrfField() ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Sport *</label>
                <select name="sport_id" id="matchSportId" class="form-select" required>
                    <option value="">Select sport</option>
                    <?php foreach ($sports as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= post('sport_id') == $s['id'] ? 'selected' : '' ?>><?= sanitize(sportLabel($s)) ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="sportFormatHint" class="form-text mt-2"></div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Date & Time *</label>
                <input type="datetime-local" name="scheduled_at" class="form-control" required value="<?= sanitize(post('scheduled_at')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Team A *</label>
                <select name="team_a_id" class="form-select" required>
                    <option value="">Select team</option>
                    <?php foreach ($teams as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= post('team_a_id') == $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Team B *</label>
                <select name="team_b_id" class="form-select" required>
                    <option value="">Select team</option>
                    <?php foreach ($teams as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= post('team_b_id') == $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Venue</label>
                <input type="text" name="venue" class="form-control" value="<?= sanitize(post('venue')) ?>" placeholder="e.g. Gymnasium Court A">
            </div>
            <div class="col-md-6">
                <label class="form-label">Referee</label>
                <input type="text" name="referee_name" class="form-control" value="<?= sanitize(post('referee_name')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= sanitize(post('notes')) ?></textarea>
            </div>
        </div>
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">Save Match</button>
            <a href="<?= BASE_URL ?>/intramurals/matches/index.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div></div></div></div>

<script>
(function () {
    const meta = <?= json_encode($sportsMetaJson, JSON_UNESCAPED_UNICODE) ?>;
    const select = document.getElementById('matchSportId');
    const hint = document.getElementById('sportFormatHint');
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
    refresh();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
