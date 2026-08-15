<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);
ensureResultsLockColumns();
ensureEventResultsLockTable();

$season = getCurrentSeason();
$status = getResultsLockStatus();
$errors = [];
$seasonId = $season ? (int) $season['id'] : 0;
$sports = [];
$eventLocks = [];

if ($seasonId) {
    $db = getDB();
    $sports = $db->query('SELECT id, name, category FROM intramural_sports ORDER BY name, category')->fetchAll() ?: [];
    $eventLocks = getEventResultsLocksMap($seasonId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    if (!$season) {
        flash('error', 'No active intramurals season found. Create or activate a season first.');
        redirect(BASE_URL . '/admin/results_lock.php');
    }

    $action = post('action');
    $seasonId = (int) ($season['id'] ?? 0);

    if ($action === 'lock') {
        $lockDate = trim((string) post('lock_date', date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lockDate)) {
            $errors[] = 'Please choose a valid lock date.';
        } elseif (setSeasonResultsLockDate($seasonId, (int) $_SESSION['user_id'], $lockDate)) {
            auditLog((int) $_SESSION['user_id'], 'lock_results', 'intramural_season', $seasonId, null, [
                'year_label' => $season['year_label'],
                'lock_date' => $lockDate,
            ]);
            if ($lockDate > date('Y-m-d')) {
                flash('success', 'Results lock scheduled for ' . formatDate($lockDate) . ' (' . $season['year_label'] . ').');
            } else {
                flash('success', 'Match results locked for ' . $season['year_label'] . '. Score updates and event rankings are disabled.');
            }
            redirect(BASE_URL . '/admin/results_lock.php');
        } else {
            $errors[] = 'Could not set results lock date.';
        }
    } elseif ($action === 'unlock') {
        if (unlockSeasonResults($seasonId)) {
            auditLog((int) $_SESSION['user_id'], 'unlock_results', 'intramural_season', $seasonId, null, [
                'year_label' => $season['year_label'],
            ]);
            flash('success', 'Match results unlocked for ' . $season['year_label'] . '. Scores and rankings can be updated again.');
            redirect(BASE_URL . '/admin/results_lock.php');
        }
        $errors[] = 'Could not unlock results.';
    } elseif ($action === 'lock_event' || $action === 'unlock_event') {
        $sportId = (int) post('sport_id');
        $sportName = 'Event';
        foreach ($sports as $s) {
            if ((int) $s['id'] === $sportId) {
                $sportName = sportLabel($s);
                break;
            }
        }
        if ($sportId <= 0) {
            $errors[] = 'Select a valid event.';
        } elseif ($action === 'lock_event') {
            if (lockEventResults($sportId, $seasonId, (int) $_SESSION['user_id'])) {
                auditLog((int) $_SESSION['user_id'], 'lock_event_results', 'intramural_sport', $sportId, null, [
                    'season_id' => $seasonId,
                    'event' => $sportName,
                ]);
                flash('success', 'Results locked for ' . $sportName . '.');
                redirect(BASE_URL . '/admin/results_lock.php#per-event');
            }
            $errors[] = 'Could not lock results for that event.';
        } else {
            if (unlockEventResults($sportId, $seasonId)) {
                auditLog((int) $_SESSION['user_id'], 'unlock_event_results', 'intramural_sport', $sportId, null, [
                    'season_id' => $seasonId,
                    'event' => $sportName,
                ]);
                flash('success', 'Results unlocked for ' . $sportName . '.');
                redirect(BASE_URL . '/admin/results_lock.php#per-event');
            }
            $errors[] = 'Could not unlock results for that event.';
        }
    } else {
        $errors[] = 'Unknown action.';
    }

    $status = getResultsLockStatus();
    $eventLocks = getEventResultsLocksMap($seasonId);
}

$pageTitle = 'Lock Results';
require_once __DIR__ . '/../includes/header.php';
$seasonWideLocked = !empty($status['is_locked']);
$lockedEventCount = count($eventLocks);
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-lock"></i> Lock Results</h1>
        <p class="text-muted mb-0">Lock all results for the season, or lock individual events so scores and rankings stay fixed.</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-outline-secondary">Back to Admin</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">Active Season Status</div>
            <div class="card-body">
                <?php if (!$season): ?>
                <div class="alert alert-warning mb-0">
                    No active intramurals season. <a href="<?= BASE_URL ?>/intramurals/seasons/index.php">Manage seasons</a> first.
                </div>
                <?php else: ?>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Season</dt>
                    <dd class="col-sm-8"><strong><?= sanitize($season['year_label']) ?></strong> — <?= sanitize($season['name']) ?></dd>

                    <dt class="col-sm-4">Season-wide status</dt>
                    <dd class="col-sm-8">
                        <?php if ($seasonWideLocked): ?>
                        <span class="badge bg-danger"><i class="bi bi-lock-fill"></i> Locked</span>
                        <?php elseif (!empty($status['is_scheduled'])): ?>
                        <span class="badge bg-info text-dark"><i class="bi bi-calendar-event"></i> Scheduled</span>
                        <?php else: ?>
                        <span class="badge bg-success"><i class="bi bi-unlock"></i> Open</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-4">Per-event locks</dt>
                    <dd class="col-sm-8">
                        <span class="badge bg-<?= $lockedEventCount ? 'warning text-dark' : 'secondary' ?>">
                            <?= (int) $lockedEventCount ?> event<?= $lockedEventCount === 1 ? '' : 's' ?> locked
                        </span>
                    </dd>

                    <?php if (!empty($status['lock_date'])): ?>
                    <dt class="col-sm-4">Lock date</dt>
                    <dd class="col-sm-8"><?= sanitize(formatDate($status['lock_date'])) ?></dd>
                    <?php endif; ?>

                    <?php if ($seasonWideLocked): ?>
                    <dt class="col-sm-4">Locked at</dt>
                    <dd class="col-sm-8"><?= !empty($status['locked_at']) ? sanitize(formatDateTime($status['locked_at'])) : '—' ?></dd>
                    <dt class="col-sm-4">Locked by</dt>
                    <dd class="col-sm-8"><?= sanitize($status['locked_by_name'] ?: 'Administrator') ?></dd>
                    <?php endif; ?>
                </dl>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($season): ?>
        <div class="card mt-4">
            <div class="card-header">Season-wide Settings</div>
            <div class="card-body">
                <?php if ($seasonWideLocked || !empty($status['is_scheduled'])): ?>
                <p class="text-muted">Unlock to allow tournament managers and staff to record scores and update rankings again (unless an event is locked individually).</p>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="unlock">
                    <button type="submit" class="btn btn-success" data-confirm="Unlock match results for this season?">
                        <i class="bi bi-unlock"></i> Unlock Season Results
                    </button>
                </form>
                <?php endif; ?>

                <?php if (!$seasonWideLocked): ?>
                <hr class="<?= (!empty($status['is_scheduled']) ? '' : 'd-none') ?>">
                <p class="text-muted <?= empty($status['is_scheduled']) ? '' : 'mt-3' ?>">
                    Choose today (or a past date) to lock immediately, or a future date to schedule automatic locking.
                </p>
                <form method="POST" class="row g-2 align-items-end">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="lock">
                    <div class="col-md-5">
                        <label class="form-label" for="lock_date">Lock date</label>
                        <input type="date" name="lock_date" id="lock_date" class="form-control" required
                            value="<?= sanitize($status['lock_date'] ?? date('Y-m-d')) ?>">
                    </div>
                    <div class="col-md-7">
                        <button type="submit" class="btn btn-warning" data-confirm="Save results lock date? Today or past dates lock immediately.">
                            <i class="bi bi-lock"></i> <?= !empty($status['is_scheduled']) ? 'Update Lock Date' : 'Lock All Season Results' ?>
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <div class="card border-warning">
            <div class="card-header bg-warning bg-opacity-10">What gets locked</div>
            <div class="card-body">
                <ul class="mb-0">
                    <li>Live score updates on match details</li>
                    <li>Record / edit match scores and final status</li>
                    <li>Bracket advance from completed results</li>
                    <li>Manual event rankings entry</li>
                </ul>
                <p class="small text-muted mt-3 mb-0">
                    Season-wide lock covers every event. Per-event lock only blocks that sport. Viewing standings, calendars, and reports stays available. Scheduling match date/time is not blocked.
                </p>
            </div>
        </div>
    </div>
</div>

<?php if ($season && $sports): ?>
<div class="card mt-4" id="per-event">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-trophy"></i> Lock Results Per Event</span>
        <small class="text-muted"><?= count($sports) ?> event<?= count($sports) === 1 ? '' : 's' ?></small>
    </div>
    <div class="card-body p-0">
        <?php if ($seasonWideLocked): ?>
        <div class="alert alert-warning border-0 rounded-0 mb-0">
            Season-wide results are locked. Every event is blocked until you unlock the season. Per-event locks still apply after the season unlock.
        </div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Event</th>
                        <th>Status</th>
                        <th>Locked details</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sports as $s): ?>
                    <?php
                    $sid = (int) $s['id'];
                    $lock = $eventLocks[$sid] ?? null;
                    $eventLocked = !empty($lock['is_locked']);
                    $effectivelyLocked = $seasonWideLocked || $eventLocked;
                    ?>
                    <tr>
                        <td>
                            <strong><?= sanitize($s['name']) ?></strong>
                            <div class="small text-muted"><?= sanitize(ucfirst((string) $s['category'])) ?></div>
                        </td>
                        <td>
                            <?php if ($seasonWideLocked): ?>
                            <span class="badge bg-danger">Season locked</span>
                            <?php elseif ($eventLocked): ?>
                            <span class="badge bg-warning text-dark"><i class="bi bi-lock-fill"></i> Event locked</span>
                            <?php else: ?>
                            <span class="badge bg-success"><i class="bi bi-unlock"></i> Open</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted">
                            <?php if ($eventLocked): ?>
                            <?= !empty($lock['locked_at']) ? sanitize(formatDateTime($lock['locked_at'])) : '—' ?>
                            <?= !empty($lock['locked_by_name']) ? ' · ' . sanitize($lock['locked_by_name']) : '' ?>
                            <?php else: ?>
                            —
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($eventLocked): ?>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="unlock_event">
                                <input type="hidden" name="sport_id" value="<?= $sid ?>">
                                <button type="submit" class="btn btn-sm btn-success" <?= $seasonWideLocked ? 'title="Season lock still applies until unlocked"' : '' ?> data-confirm="Unlock results for <?= sanitize(sportLabel($s)) ?>?">
                                    <i class="bi bi-unlock"></i> Unlock
                                </button>
                            </form>
                            <?php else: ?>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="lock_event">
                                <input type="hidden" name="sport_id" value="<?= $sid ?>">
                                <button type="submit" class="btn btn-sm btn-outline-warning" data-confirm="Lock results for <?= sanitize(sportLabel($s)) ?>? Scores and rankings for this event will be frozen.">
                                    <i class="bi bi-lock"></i> Lock
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php elseif ($season): ?>
<div class="alert alert-info mt-4 mb-0">No sports/events found to lock individually.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
