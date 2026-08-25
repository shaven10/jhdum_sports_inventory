<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
if (!canManageMatches()) {
    flash('error', 'You do not have permission to schedule matches.');
    redirect(BASE_URL . '/intramurals/matches/index.php');
}
requireWritableSeason();

$db = getDB();
$id = (int) get('id');

$stmt = $db->prepare("SELECT m.*, s.name as sport_name, s.category as sport_category, s.tournament_format, s.format_notes,
    ta.name as team_a_name, ta.color as team_a_color,
    tb.name as team_b_name, tb.color as team_b_color
    FROM intramural_matches m
    JOIN intramural_sports s ON m.sport_id = s.id
    LEFT JOIN intramural_teams ta ON m.team_a_id = ta.id
    LEFT JOIN intramural_teams tb ON m.team_b_id = tb.id
    WHERE m.id = ?");
$stmt->execute([$id]);
$match = $stmt->fetch();

if (!$match) {
    flash('error', 'Match not found.');
    redirect(BASE_URL . '/intramurals/matches/index.php');
}

requireEventMatchAccess((int) $match['sport_id']);

$fromDashboard = get('from') === 'dashboard';
$fromQuery = $fromDashboard ? '&from=dashboard' : '';
$viewUrl = BASE_URL . '/intramurals/matches/view.php?id=' . $id . $fromQuery;
$backUrl = $fromDashboard ? (BASE_URL . '/dashboard.php') : $viewUrl;

$teams = $db->query('SELECT * FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
$errors = [];
$isTabulator = isTournamentManager() && !canManageIntramurals() && !isSecretariat();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/intramurals/matches/schedule.php?id=' . $id . $fromQuery);
    }

    $scheduledAt = post('scheduled_at');
    $venue = post('venue');
    $referee = post('referee_name');
    $notes = post('notes', $match['notes'] ?? '');
    $teamA = isset($_POST['team_a_id']) ? ((int) post('team_a_id') ?: null) : ((int) ($match['team_a_id'] ?? 0) ?: null);
    $teamB = isset($_POST['team_b_id']) ? ((int) post('team_b_id') ?: null) : ((int) ($match['team_b_id'] ?? 0) ?: null);
    $clearSchedule = post('clear_schedule') === '1';

    if (!$clearSchedule && $scheduledAt === '') {
        $errors[] = 'Date & time is required (or check “Clear schedule”).';
    } elseif (!$clearSchedule && strtotime($scheduledAt) === false) {
        $errors[] = 'Invalid date & time.';
    }
    if ($teamA && $teamB && $teamA === $teamB) {
        $errors[] = 'Teams must be different.';
    }

    // Reschedule only posts team fields when a side is still TBD. Admin (and
    // anyone else) must not wipe houses that are already assigned.
    if (!empty($match['team_a_id'])) {
        $teamA = (int) $match['team_a_id'];
    }
    if (!empty($match['team_b_id'])) {
        $teamB = (int) $match['team_b_id'];
    }

    if (empty($errors)) {
        $scheduledValue = $clearSchedule ? null : date('Y-m-d H:i:s', strtotime($scheduledAt));
        $db->prepare('UPDATE intramural_matches SET scheduled_at=?, venue=?, referee_name=?, notes=?, team_a_id=?, team_b_id=? WHERE id=?')
            ->execute([
                $scheduledValue,
                $venue ?: null,
                $referee ?: null,
                $notes ?: null,
                $teamA,
                $teamB,
                $id,
            ]);
        auditLog($_SESSION['user_id'], 'schedule_match', 'intramural_match', $id, null, [
            'scheduled_at' => $scheduledValue,
            'venue' => $venue,
        ]);
        if (!empty($match['season_id'])) {
            recalculateVenueGameNumbers((int) $match['season_id']);
        }
        flash('success', $clearSchedule ? 'Match schedule cleared.' : 'Match date & time saved.');
        redirect($viewUrl);
    }
}

$dtLocal = $match['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($match['scheduled_at'])) : '';
$season = getCurrentSeason();

$pageTitle = 'Assign Match Schedule';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header">
    <h1><i class="bi bi-clock"></i> Edit Date &amp; Time</h1>
    <p class="text-muted mb-0">
        <?= sanitize($match['sport_name']) ?>
        <span class="badge bg-secondary"><?= ucfirst($match['sport_category']) ?></span>
        · <?= sanitize($match['round_label'] ?: ('Round ' . (int) $match['round_number'])) ?>
        · <span class="badge bg-info text-dark"><?= sanitize(tournamentFormatLabel($match['tournament_format'] ?? null)) ?></span>
    </p>
</div>

<div class="row"><div class="col-lg-7"><div class="card"><div class="card-body">
    <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="alert alert-light border mb-3">
        <?= matchTeamRosterTrigger($match, 'a') ?>
        vs
        <?= matchTeamRosterTrigger($match, 'b') ?>
        <div class="small text-muted mt-1">Click a team name to view the official player list for this match.</div>
        <?php if (!empty($match['format_notes'])): ?>
        <div class="small text-muted mt-1"><?= sanitize($match['format_notes']) ?></div>
        <?php endif; ?>
    </div>

    <div class="alert alert-info py-2 small">
        Auto-generated times are only a starting point. You may set <strong>any date and time</strong>
        <?php if ($season && !empty($season['start_date']) && !empty($season['end_date'])): ?>
        — including dates outside the season period
        (<?= formatDate($season['start_date']) ?> – <?= formatDate($season['end_date']) ?>).
        <?php else: ?>
        — not limited to the active season dates.
        <?php endif; ?>
    </div>

    <form method="POST">
        <?= csrfField() ?>
        <?php if (!empty($match['team_a_id']) && !empty($match['team_b_id'])): ?>
        <input type="hidden" name="team_a_id" value="<?= (int) $match['team_a_id'] ?>">
        <input type="hidden" name="team_b_id" value="<?= (int) $match['team_b_id'] ?>">
        <?php endif; ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Date &amp; Time *</label>
                <input type="datetime-local" name="scheduled_at" class="form-control" value="<?= sanitize(post('scheduled_at', $dtLocal)) ?>">
                <div class="form-text">Pick any calendar date/time. Not restricted to season dates.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Venue</label>
                <input type="text" name="venue" class="form-control" value="<?= sanitize(post('venue', $match['venue'] ?? '')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Referee</label>
                <input type="text" name="referee_name" class="form-control" value="<?= sanitize(post('referee_name', $match['referee_name'] ?? '')) ?>">
            </div>

            <?php if (!$match['team_a_id'] || !$match['team_b_id']): ?>
            <div class="col-md-6">
                <label class="form-label">Team A <?= $match['team_a_id'] ? '' : '(TBD)' ?></label>
                <?php if ($match['team_a_id'] && $isTabulator): ?>
                <input type="hidden" name="team_a_id" value="<?= (int) $match['team_a_id'] ?>">
                <input type="text" class="form-control" value="<?= sanitize($match['team_a_name']) ?>" disabled>
                <?php else: ?>
                <select name="team_a_id" class="form-select">
                    <option value="">TBD</option>
                    <?php foreach ($teams as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= (int) ($match['team_a_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label">Team B <?= $match['team_b_id'] ? '' : '(TBD)' ?></label>
                <?php if ($match['team_b_id'] && $isTabulator): ?>
                <input type="hidden" name="team_b_id" value="<?= (int) $match['team_b_id'] ?>">
                <input type="text" class="form-control" value="<?= sanitize($match['team_b_name']) ?>" disabled>
                <?php else: ?>
                <select name="team_b_id" class="form-select">
                    <option value="">TBD</option>
                    <?php foreach ($teams as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= (int) ($match['team_b_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= sanitize(post('notes', $match['notes'] ?? '')) ?></textarea>
            </div>
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="clear_schedule" value="1" id="clearSchedule">
                    <label class="form-check-label" for="clearSchedule">Clear schedule (mark as unscheduled)</label>
                </div>
            </div>
        </div>
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">Save Schedule</button>
            <a href="<?= sanitize($fromDashboard ? $backUrl : $viewUrl) ?>" class="btn btn-outline-secondary"><?= $fromDashboard ? 'Back' : 'Cancel' ?></a>
        </div>
    </form>
</div></div></div></div>

<?php require __DIR__ . '/_roster_dialog.php'; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
