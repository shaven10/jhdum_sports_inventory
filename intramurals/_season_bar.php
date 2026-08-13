<?php
ensureRosterLockColumns();
$__season = getCurrentSeason();
$__seasons = getAllSeasons(true);
$__active = getActiveSeason();
$__writable = isViewingActiveSeason();
$__lockStatus = $__season ? getRosterLockStatus((int) $__season['id']) : getRosterLockStatus();
$__rosterLocked = $__lockStatus['is_locked'];
$__rosterScheduled = $__lockStatus['is_scheduled'];
$__returnUri = sanitize($_SERVER['REQUEST_URI'] ?? BASE_URL . '/intramurals/index.php');
?>
<div class="card mb-3 border-0 shadow-sm season-bar">
    <div class="card-body py-2 px-3">
        <div class="row g-2 align-items-center">
            <div class="col-auto">
                <span class="text-muted small"><i class="bi bi-calendar3"></i> Intramurals Year</span>
            </div>
            <div class="col-md-4 col-lg-3">
                <form method="GET" id="seasonSwitchForm">
                    <?php
                    foreach ($_GET as $key => $value) {
                        if ($key === 'season_id' || is_array($value)) {
                            continue;
                        }
                        echo '<input type="hidden" name="' . sanitize($key) . '" value="' . sanitize((string) $value) . '">';
                    }
                    ?>
                    <select name="season_id" class="form-select form-select-sm" onchange="document.getElementById('seasonSwitchForm').submit()">
                        <?php foreach ($__seasons as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $__season && (int) $__season['id'] === (int) $s['id'] ? 'selected' : '' ?>>
                            <?= sanitize($s['year_label']) ?> — <?= sanitize($s['name']) ?>
                            <?= !empty($s['is_active']) ? '(Active)' : '' ?>
                            <?= !empty($s['is_archived']) ? '(Archived)' : '' ?>
                            <?= !empty($s['roster_locked']) ? '(Roster Locked)' : '' ?>
                            <?= empty($s['roster_locked']) && !empty($s['roster_lock_date']) && $s['roster_lock_date'] > date('Y-m-d') ? '(Lock ' . formatDate($s['roster_lock_date']) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                        <?php if (empty($__seasons)): ?>
                        <option value="">No seasons configured</option>
                        <?php endif; ?>
                    </select>
                </form>
            </div>
            <div class="col-auto d-flex flex-wrap gap-1 align-items-center">
                <?php if ($__season && !empty($__season['is_active'])): ?>
                <span class="badge bg-success">Active Season</span>
                <?php else: ?>
                <span class="badge bg-secondary">Viewing Historical</span>
                <?php endif; ?>
                <?php if ($__rosterLocked): ?>
                <span class="badge bg-warning text-dark"><i class="bi bi-lock-fill"></i> Roster Locked</span>
                <?php elseif ($__rosterScheduled && !empty($__lockStatus['lock_date'])): ?>
                <span class="badge bg-info text-dark"><i class="bi bi-calendar-event"></i> Locks <?= sanitize(formatDate($__lockStatus['lock_date'])) ?></span>
                <?php endif; ?>
            </div>
            <div class="col-auto ms-auto d-flex gap-2 flex-wrap align-items-center">
                <?php if (!$__writable): ?>
                <span class="small text-muted align-self-center">Read-only (not active year)</span>
                <?php endif; ?>
                <?php if ($__season && canLockRoster()): ?>
                    <?php if ($__rosterLocked || $__rosterScheduled): ?>
                    <form method="POST" action="<?= BASE_URL ?>/intramurals/roster/lock.php" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="unlock">
                        <input type="hidden" name="season_id" value="<?= (int) $__season['id'] ?>">
                        <input type="hidden" name="return" value="<?= $__returnUri ?>">
                        <button type="submit" class="btn btn-sm btn-outline-success" data-confirm="Unlock the roster for <?= sanitize($__season['year_label']) ?>? Teams will be able to modify rosters again.">
                            <i class="bi bi-unlock"></i> Unlock Roster
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php if (!empty($__season['is_active']) && !$__rosterLocked): ?>
                    <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#seasonRosterLockModal">
                        <i class="bi bi-lock"></i> <?= $__rosterScheduled ? 'Change Lock Date' : 'Set Lock Date' ?>
                    </button>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (canManageIntramurals()): ?>
                <a href="<?= BASE_URL ?>/intramurals/seasons/index.php" class="btn btn-sm btn-outline-primary">Manage Seasons</a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($__active && $__season && (int) $__active['id'] !== (int) $__season['id']): ?>
        <div class="small text-warning mt-1 mb-0">
            Active year is <strong><?= sanitize($__active['year_label']) ?></strong>.
            Results below are for <strong><?= sanitize($__season['year_label']) ?></strong> only.
        </div>
        <?php endif; ?>
        <?php if (shouldShowRosterLockStatus()): ?>
            <?php if ($__rosterLocked): ?>
            <div class="small text-muted mt-1 mb-0">
                <i class="bi bi-lock-fill"></i>
                Roster locked<?= !empty($__lockStatus['lock_date']) ? ' (effective ' . sanitize(formatDate($__lockStatus['lock_date'])) . ')' : '' ?><?= $__lockStatus['locked_by_name'] ? ' by ' . sanitize($__lockStatus['locked_by_name']) : '' ?><?= $__lockStatus['locked_at'] ? ' on ' . sanitize(formatDateTime($__lockStatus['locked_at'])) : '' ?>.
                Imports and sport assignments are disabled.
            </div>
            <?php elseif ($__rosterScheduled && !empty($__lockStatus['lock_date'])): ?>
            <div class="small text-info mt-1 mb-0">
                <i class="bi bi-calendar-event"></i>
                Roster will lock on <strong><?= sanitize(formatDate($__lockStatus['lock_date'])) ?></strong><?= $__lockStatus['locked_by_name'] ? ' (set by ' . sanitize($__lockStatus['locked_by_name']) . ')' : '' ?>.
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php if (shouldShowRosterLockStatus()): ?>
<?= renderRosterLockAlerts($__season ? (int) $__season['id'] : null) ?>
<?php endif; ?>

<?php if ($__season && canLockRoster() && !empty($__season['is_active']) && !$__rosterLocked): ?>
<div class="modal fade" id="seasonRosterLockModal" tabindex="-1" aria-labelledby="seasonRosterLockModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= BASE_URL ?>/intramurals/roster/lock.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="lock">
                <input type="hidden" name="season_id" value="<?= (int) $__season['id'] ?>">
                <input type="hidden" name="return" value="<?= $__returnUri ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="seasonRosterLockModalLabel"><i class="bi bi-lock"></i> Set Roster Lock Date</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Choose when rosters for <strong><?= sanitize($__season['year_label']) ?></strong> should lock. Today or a past date locks immediately. A future date schedules automatic locking.</p>
                    <div class="mb-0">
                        <label class="form-label" for="seasonRosterLockDate">Lock Date *</label>
                        <input type="date" class="form-control" id="seasonRosterLockDate" name="lock_date" required value="<?= sanitize($__lockStatus['lock_date'] ?? date('Y-m-d')) ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-lock"></i> Save Lock Date</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
