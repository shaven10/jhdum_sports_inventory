<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);
ensureLiveBoardColumns();

$activeSeason = getActiveSeason();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    if (!$activeSeason) {
        flash('error', 'No active intramurals season found. Create or activate a season first.');
        redirect(BASE_URL . '/admin/live_board.php');
    }

    $action = post('action');
    $seasonId = (int) $activeSeason['id'];

    if ($action === 'enable') {
        if (setLiveBoardEnabled($seasonId, true, (int) $_SESSION['user_id'])) {
            auditLog((int) $_SESSION['user_id'], 'enable_live_board', 'intramural_season', $seasonId, null, [
                'year_label' => $activeSeason['year_label'],
            ]);
            flash('success', 'Live overall and medal standings are now public for ' . $activeSeason['year_label'] . '.');
        } else {
            flash('error', 'Could not enable the live board.');
        }
        redirect(BASE_URL . '/admin/live_board.php');
    }

    if ($action === 'disable') {
        if (setLiveBoardEnabled($seasonId, false, (int) $_SESSION['user_id'])) {
            auditLog((int) $_SESSION['user_id'], 'disable_live_board', 'intramural_season', $seasonId, null, [
                'year_label' => $activeSeason['year_label'],
            ]);
            flash('success', 'Live standings hidden from the public. Visitors will see the landing page instead.');
        } else {
            flash('error', 'Could not disable the live board.');
        }
        redirect(BASE_URL . '/admin/live_board.php');
    }
}

$liveEnabled = $activeSeason ? isLiveBoardEnabled((int) $activeSeason['id']) : false;
$updatedByName = null;
if ($activeSeason && !empty($activeSeason['live_board_updated_by'])) {
    $db = getDB();
    $stmt = $db->prepare('SELECT first_name, last_name FROM users WHERE id = ?');
    $stmt->execute([(int) $activeSeason['live_board_updated_by']]);
    $user = $stmt->fetch();
    if ($user) {
        $updatedByName = trim($user['first_name'] . ' ' . $user['last_name']);
    }
}

$pageTitle = 'Live Standings Control';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-broadcast"></i> Live Standings Control</h1>
        <p class="text-muted mb-0">Enable or disable the public live overall rankings and medal tally page for the active season.</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-outline-secondary">Back to Admin</a>
</div>

<?php if (!$activeSeason): ?>
<div class="alert alert-warning">
    No active intramurals season. <a href="<?= BASE_URL ?>/intramurals/seasons/index.php">Create or activate a season</a> first.
</div>
<?php else: ?>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">Active Season</div>
            <div class="card-body">
                <h2 class="h5 mb-1"><?= sanitize($activeSeason['name']) ?></h2>
                <p class="text-muted mb-3"><?= sanitize($activeSeason['year_label']) ?></p>
                <?php if ($activeSeason['start_date'] || $activeSeason['end_date']): ?>
                <p class="small mb-3">
                    <?= $activeSeason['start_date'] ? formatDate($activeSeason['start_date']) : '—' ?>
                    →
                    <?= $activeSeason['end_date'] ? formatDate($activeSeason['end_date']) : '—' ?>
                </p>
                <?php endif; ?>
                <div class="mb-3">
                    <?php if ($liveEnabled): ?>
                    <span class="badge bg-success fs-6"><i class="bi bi-broadcast"></i> Live board is public</span>
                    <?php else: ?>
                    <span class="badge bg-secondary fs-6"><i class="bi bi-eye-slash"></i> Live board is hidden</span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($activeSeason['live_board_updated_at'])): ?>
                <p class="small text-muted mb-0">
                    Last changed <?= sanitize(formatDateTime($activeSeason['live_board_updated_at'])) ?>
                    <?php if ($updatedByName): ?>
                    by <?= sanitize($updatedByName) ?>
                    <?php endif; ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">Public Access</div>
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
                </div>
                <?php endif; ?>

                <?php if ($liveEnabled): ?>
                <p>Visitors can currently view <strong>live overall rankings</strong>, <strong>medal tally</strong>, and <strong>per-event standings</strong> on the public live board.</p>
                <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="disable">
                    <button class="btn btn-warning" data-confirm="Hide live standings from the public? Visitors will see the landing page instead.">
                        <i class="bi bi-eye-slash"></i> Disable live standings
                    </button>
                </form>
                <?php else: ?>
                <p>The public live board is currently <strong>hidden</strong>. Visitors see the landing page with season info and links to working committees and staff login.</p>
                <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="enable">
                    <button class="btn btn-success" data-confirm="Publish live overall and medal standings for the public?">
                        <i class="bi bi-broadcast"></i> Enable live standings
                    </button>
                </form>
                <?php endif; ?>

                <div class="mt-4 pt-3 border-top">
                    <p class="small text-muted mb-2">Preview (admin only — works even when disabled for the public):</p>
                    <a href="<?= BASE_URL ?>/live.php" class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener">
                        <i class="bi bi-box-arrow-up-right"></i> Open live board
                    </a>
                    <a href="<?= BASE_URL ?>/landing.php" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">
                        <i class="bi bi-box-arrow-up-right"></i> Open landing page
                    </a>
                </div>
            </div>
        </div>
        <div class="alert alert-info mt-3 mb-0">
            <strong>Note:</strong> This setting applies only to the <em>active</em> season. When you activate a new season, live standings start enabled by default until you disable them.
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
