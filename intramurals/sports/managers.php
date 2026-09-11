<?php
require_once __DIR__ . '/../../includes/auth.php';
if (!canManageIntramurals()) {
    flash('error', 'You do not have permission to assign tournament managers.');
    redirect(BASE_URL . '/intramurals/sports/index.php');
}

$db = getDB();
$seasonId = getCurrentSeasonId();
$season = getCurrentSeason();
$sports = $db->query('SELECT * FROM intramural_sports WHERE is_active = 1 ORDER BY name, category')->fetchAll();
$managers = $db->query("SELECT id, first_name, last_name, username FROM users WHERE role = 'tournament_manager' AND is_active = 1 ORDER BY first_name, last_name")->fetchAll();

$current = [];
try {
    if ($seasonId) {
        $rows = $db->prepare('SELECT sport_id, user_id FROM intramural_event_managers WHERE season_id = ?');
        $rows->execute([$seasonId]);
        foreach ($rows->fetchAll() as $r) {
            $current[(int) $r['sport_id']] = (int) $r['user_id'];
        }
    }
} catch (Throwable $e) {
    $current = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    requireWritableSeason();
    if (!$seasonId) {
        flash('error', 'No active intramurals season configured.');
        redirect(BASE_URL . '/intramurals/sports/managers.php');
    }

    $assignments = $_POST['manager'] ?? [];
    if (!is_array($assignments)) {
        $assignments = [];
    }

    foreach ($sports as $sport) {
        $sportId = (int) $sport['id'];
        $userId = isset($assignments[$sportId]) ? ((int) $assignments[$sportId] ?: null) : null;
        assignEventTournamentManager($sportId, $userId, $seasonId);
    }

    auditLog($_SESSION['user_id'], 'assign_event_managers', 'intramural_sport', null, null, ['season_id' => $seasonId]);
    flash('success', 'Tournament managers updated for ' . ($season ? seasonLabel($season) : 'this season') . '.');
    redirect(BASE_URL . '/intramurals/sports/managers.php');
}

$pageTitle = 'Tournament Managers';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-person-gear"></i> Tournament Managers</h1>
        <p class="text-muted mb-0">
            Assign one tournament manager per event
            <?= $season ? ' · ' . sanitize(seasonLabel($season)) : '' ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if (canManageUsers()): ?>
        <a href="<?= BASE_URL ?>/users/add.php" class="btn btn-outline-primary">Add TM Account</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/intramurals/sports/index.php" class="btn btn-outline-secondary">Back to Sports</a>
    </div>
</div>

<div class="alert alert-info">
    Tournament managers can <strong>update scores</strong> and view <strong>standings</strong> only for the events assigned to them.
    They cannot generate fixtures, change schedules, or manage other sports.
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
                            <th>Tournament Style</th>
                            <th>Tournament Manager</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sports as $sport): ?>
                        <tr>
                            <td><strong><?= sanitize($sport['name']) ?></strong></td>
                            <td><?= ucfirst($sport['category']) ?></td>
                            <td><span class="badge bg-info text-dark"><?= sanitize(tournamentFormatLabel($sport['tournament_format'] ?? 'round_robin')) ?></span></td>
                            <td style="min-width:280px">
                                <select name="manager[<?= $sport['id'] ?>]" class="form-select">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($managers as $m): ?>
                                    <option value="<?= $m['id'] ?>" <?= ($current[(int) $sport['id']] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>>
                                        <?= sanitize($m['first_name'] . ' ' . $m['last_name'] . ' (' . $m['username'] . ')') ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($sports)): ?>
                        <tr><td colspan="4" class="text-muted">No active sports/events.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (empty($managers)): ?>
            <div class="alert alert-warning">No tournament manager accounts found. Create users with the Tournament Manager role first.</div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary" <?= empty($sports) || !$seasonId ? 'disabled' : '' ?>>Save Assignments</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
