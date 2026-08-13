<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsModule();
ensureEventManagersTable();

if (!canManageIntramurals()) {
    flash('error', 'Only intramurals staff can assign tournament managers.');
    redirect(BASE_URL . '/intramurals/sports/index.php');
}

$db = getDB();
$seasonId = getCurrentSeasonId();
$sports = $db->query('SELECT * FROM intramural_sports ORDER BY name, category')->fetchAll();
$managers = $db->query("SELECT id, first_name, last_name, username FROM users WHERE role = 'tabulator' AND is_active = 1 ORDER BY first_name, last_name")->fetchAll();

$current = [];
try {
    if ($seasonId) {
        $rows = $db->prepare('SELECT em.sport_id, em.manager_user_id, u.first_name, u.last_name, u.username
            FROM intramural_event_managers em
            JOIN users u ON em.manager_user_id = u.id
            WHERE em.season_id = ?');
        $rows->execute([$seasonId]);
    } else {
        $rows = $db->query('SELECT em.sport_id, em.manager_user_id, u.first_name, u.last_name, u.username
            FROM intramural_event_managers em
            JOIN users u ON em.manager_user_id = u.id');
    }
    foreach ($rows->fetchAll() as $r) {
        $current[(int) $r['sport_id']] = [
            'user_id' => (int) $r['manager_user_id'],
            'name' => trim($r['first_name'] . ' ' . $r['last_name']),
            'username' => $r['username'],
        ];
    }
} catch (Throwable $e) {
    $current = [];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    requireWritableSeason();
    $assignments = $_POST['manager'] ?? [];
    if (!is_array($assignments)) {
        $assignments = [];
    }

    foreach ($sports as $sport) {
        $sportId = (int) $sport['id'];
        $managerId = isset($assignments[$sportId]) ? ((int) $assignments[$sportId] ?: null) : null;
        if (!$managerId) {
            $errors[] = sportLabel($sport) . ' requires a tournament manager.';
        }
    }

    if (empty($errors)) {
        foreach ($sports as $sport) {
            $sportId = (int) $sport['id'];
            $managerId = (int) ($assignments[$sportId] ?? 0) ?: null;
            assignEventManager($sportId, $managerId, $seasonId);
        }

        auditLog($_SESSION['user_id'], 'assign_event_managers', 'intramural_season', (int) $seasonId, null, ['sport_count' => count($sports)]);
        flash('success', 'Tournament managers updated for all events.');
        redirect(BASE_URL . '/intramurals/sports/managers.php');
    }
}

$pageTitle = 'Tournament Managers';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-person-workspace"></i> Tournament Managers</h1>
        <p class="text-muted mb-0">Assign one tournament manager per event for the current season</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/users/index.php" class="btn btn-outline-primary">Manage Users</a>
        <a href="<?= BASE_URL ?>/intramurals/sports/index.php" class="btn btn-outline-secondary">Sports / Events</a>
    </div>
</div>

<div class="alert alert-info">
    Each event must have a tournament manager who can generate fixtures, schedule matches, and record scores for that event only.
    Create user accounts with the <strong>Tournament Manager</strong> role first, then assign them here.
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?>
        <li><?= sanitize($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

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
                            <th>Tournament Manager *</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sports as $sport): ?>
                        <?php $sportId = (int) $sport['id']; ?>
                        <tr>
                            <td><strong><?= sanitize($sport['name']) ?></strong></td>
                            <td><?= ucfirst($sport['category']) ?></td>
                            <td style="min-width:280px">
                                <select name="manager[<?= $sportId ?>]" class="form-select" required>
                                    <option value="">— Select manager —</option>
                                    <?php foreach ($managers as $m): ?>
                                    <option value="<?= $m['id'] ?>" <?= ($current[$sportId]['user_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>>
                                        <?= sanitize($m['first_name'] . ' ' . $m['last_name'] . ' (' . $m['username'] . ')') ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($sports)): ?>
                        <tr><td colspan="3" class="text-muted">No events yet. Add sports under Sports Management first.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (empty($managers)): ?>
            <div class="alert alert-warning">No tournament manager accounts found. Create users with the Tournament Manager role first.</div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary" <?= empty($sports) || empty($managers) ? 'disabled' : '' ?>>Save Tournament Managers</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
