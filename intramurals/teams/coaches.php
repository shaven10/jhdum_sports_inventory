<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$db = getDB();
$id = (int) get('id');

$stmt = $db->prepare('SELECT * FROM intramural_teams WHERE id = ?');
$stmt->execute([$id]);
$team = $stmt->fetch();

if (!$team) {
    flash('error', 'Team not found.');
    redirect(BASE_URL . '/intramurals/teams/index.php');
}

if (!canEditOwnTeam($id) && !canManageIntramurals()) {
    flash('error', 'Only unit managers or intramurals staff can assign event coaches.');
    redirect(BASE_URL . '/intramurals/teams/view.php?id=' . $id);
}

$seasonId = getCurrentSeasonId();
$sports = $db->query('SELECT * FROM intramural_sports WHERE is_active = 1 ORDER BY name, category')->fetchAll();
$coaches = $db->query("SELECT id, first_name, last_name, username, team_id FROM users WHERE role = 'coach' AND is_active = 1 ORDER BY first_name, last_name")->fetchAll();

$current = [];
try {
    if ($seasonId) {
        $rows = $db->prepare('SELECT sport_id, coach_user_id FROM intramural_event_coaches WHERE team_id = ? AND season_id = ?');
        $rows->execute([$id, $seasonId]);
    } else {
        $rows = $db->prepare('SELECT sport_id, coach_user_id FROM intramural_event_coaches WHERE team_id = ?');
        $rows->execute([$id]);
    }
    foreach ($rows->fetchAll() as $r) {
        $current[(int) $r['sport_id']] = (int) $r['coach_user_id'];
    }
} catch (Throwable $e) {
    $current = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    requireWritableSeason();
    $assignments = $_POST['coach'] ?? [];
    if (!is_array($assignments)) {
        $assignments = [];
    }

    foreach ($sports as $sport) {
        $sportId = (int) $sport['id'];
        $coachId = isset($assignments[$sportId]) ? ((int) $assignments[$sportId] ?: null) : null;
        assignEventCoach($id, $sportId, $coachId, $seasonId);
    }

    auditLog($_SESSION['user_id'], 'assign_event_coaches', 'intramural_team', $id, null, ['season_id' => $seasonId]);
    flash('success', 'Event coaches updated for ' . $team['name'] . '.');
    redirect(BASE_URL . '/intramurals/teams/coaches.php?id=' . $id);
}

$pageTitle = 'Event Coaches — ' . $team['name'];
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-person-badge"></i> Event Coaches</h1>
        <p class="text-muted mb-0">
            Assign one coach per event for
            <strong style="color:<?= sanitize($team['color']) ?>"><?= sanitize($team['name']) ?></strong>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/users/add.php" class="btn btn-outline-primary">Add Coach Account</a>
        <a href="<?= BASE_URL ?>/intramurals/teams/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Back to Team</a>
    </div>
</div>

<div class="alert alert-info">
    Each event on this team can have its own coach. Coaches can manage roster details (jersey, position, sport assignment) only for events they are assigned to.
</div>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Event / Sport</th>
                            <th>Category</th>
                            <th>Coach Account</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sports as $sport): ?>
                        <tr>
                            <td><strong><?= sanitize($sport['name']) ?></strong></td>
                            <td><?= ucfirst($sport['category']) ?></td>
                            <td style="min-width:260px">
                                <select name="coach[<?= $sport['id'] ?>]" class="form-select">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($coaches as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= ($current[(int) $sport['id']] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                                        <?= sanitize($c['first_name'] . ' ' . $c['last_name'] . ' (' . $c['username'] . ')') ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($sports)): ?>
                        <tr><td colspan="3" class="text-muted">No active sports/events. Add them under Sports Management first.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (empty($coaches)): ?>
            <div class="alert alert-warning">No coach accounts found. Create users with the Coach role first.</div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary" <?= empty($sports) ? 'disabled' : '' ?>>Save Event Coaches</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
