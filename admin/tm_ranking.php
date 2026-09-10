<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);
ensureTmRankingAccessTable();

$season = getCurrentSeason();
$seasonId = $season ? (int) $season['id'] : 0;
$db = getDB();
$errors = [];
$sports = [];
$accessMap = [];
$managerNames = [];

if ($seasonId) {
    $sports = $db->query('SELECT id, name, category, event_group FROM intramural_sports ORDER BY ' . intramuralSportsOrderBy())->fetchAll() ?: [];
    $accessMap = getTmRankingAccessMap($seasonId);

    $mgrStmt = $db->prepare(
        'SELECT em.sport_id, u.first_name, u.last_name, u.username
         FROM intramural_event_managers em
         JOIN users u ON u.id = em.manager_user_id
         WHERE em.season_id = ?'
    );
    $mgrStmt->execute([$seasonId]);
    foreach ($mgrStmt->fetchAll() ?: [] as $row) {
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        if ($name === '') {
            $name = (string) ($row['username'] ?? 'Manager');
        }
        $managerNames[(int) $row['sport_id']] = $name;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    if (!$season) {
        flash('error', 'No active intramurals season found. Create or activate a season first.');
        redirect(BASE_URL . '/admin/tm_ranking.php');
    }

    $action = post('action');
    $sportId = (int) post('sport_id');

    if ($action === 'enable' && $sportId > 0) {
        if (enableTmRanking($sportId, $seasonId, (int) $_SESSION['user_id'])) {
            auditLog((int) $_SESSION['user_id'], 'enable_tm_ranking', 'intramural_sport', $sportId, null, [
                'season_id' => $seasonId,
            ]);
            flash('success', 'Tournament manager ranking activated for this event.');
        } else {
            flash('error', 'Could not activate ranking for that event.');
        }
        redirect(BASE_URL . '/admin/tm_ranking.php');
    }

    if ($action === 'disable' && $sportId > 0) {
        if (disableTmRanking($sportId, $seasonId)) {
            auditLog((int) $_SESSION['user_id'], 'disable_tm_ranking', 'intramural_sport', $sportId, null, [
                'season_id' => $seasonId,
            ]);
            flash('success', 'Tournament manager ranking deactivated for this event.');
        } else {
            flash('error', 'Could not deactivate ranking for that event.');
        }
        redirect(BASE_URL . '/admin/tm_ranking.php');
    }
}

$enabledCount = count($accessMap);

$pageTitle = 'TM Ranking Access';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-list-ol"></i> TM Ranking Access</h1>
        <p class="text-muted mb-0">
            Activate Manual Entry of Ranks for tournament managers on specific events<?= $season ? ' · ' . sanitize(seasonLabel($season)) : '' ?>.
            Tournament managers already enter scores and ranks for assigned events under <a href="<?= BASE_URL ?>/intramurals/scoring/index.php">Scores & Rankings</a>.
        </p>
    </div>
    <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-outline-secondary">Back to Admin</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if (!$season): ?>
<div class="alert alert-warning">No active season. Create or activate a season before configuring ranking access.</div>
<?php else: ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">Events with TM ranking on</div>
                <div class="fs-4 fw-semibold"><?= $enabledCount ?> / <?= count($sports) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card h-100 border-info">
            <div class="card-body">
                <p class="mb-1"><strong>How it works</strong></p>
                <ul class="mb-0 small text-muted">
                    <li>Assign a tournament manager to the event under Sports → Tournament Managers.</li>
                    <li>That manager can open <strong>Scores & Rankings</strong> for assigned events (match scores and official ranks).</li>
                    <li>Manual ranking still requires no scheduled matches and unlocked results.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-person-gear"></i> Per-event ranking access</span>
        <a href="<?= BASE_URL ?>/intramurals/sports/managers.php" class="btn btn-sm btn-outline-primary">Manage tournament managers</a>
    </div>
    <div class="card-body p-0">
        <?php if (!$sports): ?>
        <div class="p-4 text-muted">No events found for this season.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Event</th>
                        <th>Tournament manager</th>
                        <th>Ranking access</th>
                        <th>Activated</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sports as $s): ?>
                    <?php
                    $sid = (int) $s['id'];
                    $enabled = isset($accessMap[$sid]);
                    $info = $accessMap[$sid] ?? null;
                    $tmName = $managerNames[$sid] ?? null;
                    ?>
                    <tr>
                        <td>
                            <strong><?= sanitize($s['name']) ?></strong>
                            <div class="small text-muted"><?= sanitize(ucfirst((string) $s['category'])) ?></div>
                        </td>
                        <td>
                            <?php if ($tmName): ?>
                            <?= sanitize($tmName) ?>
                            <?php else: ?>
                            <span class="text-muted">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($enabled): ?>
                            <span class="badge bg-success"><i class="bi bi-check-circle"></i> Active</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">Off</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted">
                            <?php if ($enabled && $info): ?>
                            <?= !empty($info['enabled_at']) ? sanitize(formatDateTime($info['enabled_at'])) : '—' ?>
                            <?= !empty($info['enabled_by_name']) ? ' · ' . sanitize($info['enabled_by_name']) : '' ?>
                            <?php else: ?>
                            —
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($enabled): ?>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="disable">
                                <input type="hidden" name="sport_id" value="<?= $sid ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" data-confirm="Deactivate Manual Entry of Ranks for tournament managers on <?= sanitize(sportLabel($s)) ?>?">
                                    Deactivate
                                </button>
                            </form>
                            <?php else: ?>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="enable">
                                <input type="hidden" name="sport_id" value="<?= $sid ?>">
                                <button type="submit" class="btn btn-sm btn-primary" <?= !$tmName ? 'title="No tournament manager assigned yet"' : '' ?>>
                                    Activate
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
