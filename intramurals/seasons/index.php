<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
requireRole(['admin', 'coordinator', 'staff']);

$db = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $action = post('action');

    if ($action === 'save') {
        $id = (int) post('id');
        $name = post('name');
        $yearLabel = post('year_label');
        $startDate = post('start_date') ?: null;
        $endDate = post('end_date') ?: null;
        $description = post('description');
        $makeActive = post('make_active') === '1';

        if ($name === '' || $yearLabel === '') {
            $errors[] = 'Name and year label are required.';
        }

        if (empty($errors)) {
            try {
                if ($id) {
                    $stmt = $db->prepare('UPDATE intramural_seasons SET name=?, year_label=?, start_date=?, end_date=?, description=? WHERE id=?');
                    $stmt->execute([$name, $yearLabel, $startDate, $endDate, $description, $id]);
                    auditLog($_SESSION['user_id'], 'update', 'intramural_season', $id, null, ['year_label' => $yearLabel]);
                    flash('success', 'Season updated.');
                } else {
                    $stmt = $db->prepare('INSERT INTO intramural_seasons (name, year_label, start_date, end_date, description, is_active) VALUES (?, ?, ?, ?, ?, 0)');
                    $stmt->execute([$name, $yearLabel, $startDate, $endDate, $description]);
                    $id = (int) $db->lastInsertId();
                    auditLog($_SESSION['user_id'], 'create', 'intramural_season', $id, null, ['year_label' => $yearLabel]);
                    flash('success', 'Season created.');
                }

                if ($makeActive) {
                    setActiveSeason($id);
                    flash('success', 'Season saved and set as the active intramurals year.');
                }
            } catch (PDOException $e) {
                flash('error', 'Could not save season (duplicate year label?).');
            }
            redirect(BASE_URL . '/intramurals/seasons/index.php');
        }
    }

    if ($action === 'activate') {
        $id = (int) post('id');
        if (setActiveSeason($id)) {
            auditLog($_SESSION['user_id'], 'activate', 'intramural_season', $id);
            flash('success', 'Active intramurals year updated. New matches and registrations will use this season.');
        } else {
            flash('error', 'Season not found.');
        }
        redirect(BASE_URL . '/intramurals/seasons/index.php');
    }

    if ($action === 'archive') {
        $id = (int) post('id');
        $season = getSeasonById($id);
        if ($season && !empty($season['is_active'])) {
            flash('error', 'Cannot archive the active season. Activate another season first.');
        } else {
            $db->prepare('UPDATE intramural_seasons SET is_archived = 1, is_active = 0 WHERE id = ?')->execute([$id]);
            auditLog($_SESSION['user_id'], 'archive', 'intramural_season', $id);
            flash('success', 'Season archived.');
        }
        redirect(BASE_URL . '/intramurals/seasons/index.php');
    }

    if ($action === 'lock_roster' && canLockRoster()) {
        $id = (int) post('id');
        $lockDate = trim((string) post('lock_date', date('Y-m-d')));
        $season = getSeasonById($id);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lockDate)) {
            flash('error', 'Please choose a valid lock date.');
        } elseif ($season && setSeasonRosterLockDate($id, (int) $_SESSION['user_id'], $lockDate)) {
            auditLog((int) $_SESSION['user_id'], 'lock_roster', 'intramural_season', $id, null, [
                'year_label' => $season['year_label'],
                'lock_date' => $lockDate,
            ]);
            if ($lockDate > date('Y-m-d')) {
                flash('success', 'Roster lock scheduled for ' . formatDate($lockDate) . ' (' . $season['year_label'] . ').');
            } else {
                flash('success', 'Roster locked for ' . $season['year_label'] . ' (effective ' . formatDate($lockDate) . ').');
            }
        } else {
            flash('error', 'Could not set roster lock date.');
        }
        redirect(BASE_URL . '/intramurals/seasons/index.php');
    }

    if ($action === 'unlock_roster' && canLockRoster()) {
        $id = (int) post('id');
        $season = getSeasonById($id);
        if ($season && unlockSeasonRoster($id)) {
            auditLog((int) $_SESSION['user_id'], 'unlock_roster', 'intramural_season', $id, null, ['year_label' => $season['year_label']]);
            flash('success', 'Roster unlocked for ' . $season['year_label'] . '.');
        } else {
            flash('error', 'Could not unlock roster.');
        }
        redirect(BASE_URL . '/intramurals/seasons/index.php');
    }

    if ($action === 'restore') {
        $id = (int) post('id');
        $db->prepare('UPDATE intramural_seasons SET is_archived = 0 WHERE id = ?')->execute([$id]);
        flash('success', 'Season restored.');
        redirect(BASE_URL . '/intramurals/seasons/index.php');
    }
}

$editId = (int) get('edit');
$editSeason = $editId ? getSeasonById($editId) : null;
$seasons = getAllSeasons(true);

$pageTitle = 'Intramurals Seasons';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-calendar3"></i> Intramurals Seasons / Years</h1>
        <p class="text-muted mb-0">Set the active intramurals year so results stay separated by edition</p>
    </div>
    <a href="<?= BASE_URL ?>/intramurals/index.php" class="btn btn-outline-secondary">Back</a>
</div>

<?php require __DIR__ . '/../_season_bar.php'; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><?= $editSeason ? 'Edit Season' : 'Add Season / Year' ?></div>
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save">
                    <?php if ($editSeason): ?><input type="hidden" name="id" value="<?= $editSeason['id'] ?>"><?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Display Name *</label>
                        <input type="text" name="name" class="form-control" required value="<?= sanitize($editSeason['name'] ?? post('name', 'Intramurals ' . date('Y') . '-' . (date('Y') + 1))) ?>" placeholder="e.g. Intramurals 2026-2027">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Year Label *</label>
                        <input type="text" name="year_label" class="form-control" required value="<?= sanitize($editSeason['year_label'] ?? post('year_label', date('Y') . '-' . (date('Y') + 1))) ?>" placeholder="e.g. 2026-2027">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-control" value="<?= sanitize($editSeason['start_date'] ?? post('start_date')) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-control" value="<?= sanitize($editSeason['end_date'] ?? post('end_date')) ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"><?= sanitize($editSeason['description'] ?? post('description')) ?></textarea>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="make_active" value="1" id="makeActive" <?= empty($seasons) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="makeActive">Set as current active intramurals</label>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary"><?= $editSeason ? 'Update' : 'Create Season' ?></button>
                        <?php if ($editSeason): ?><a href="<?= BASE_URL ?>/intramurals/seasons/index.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">All Seasons</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Year</th>
                                <th>Name</th>
                                <th>Period</th>
                                <th>Status</th>
                                <th>Roster</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($seasons as $s): ?>
                            <tr class="<?= !empty($s['is_archived']) ? 'table-secondary' : '' ?>">
                                <td><strong><?= sanitize($s['year_label']) ?></strong></td>
                                <td><?= sanitize($s['name']) ?></td>
                                <td class="small">
                                    <?= $s['start_date'] ? formatDate($s['start_date']) : '-' ?>
                                    →
                                    <?= $s['end_date'] ? formatDate($s['end_date']) : '-' ?>
                                </td>
                                <td>
                                    <?php if (!empty($s['is_active'])): ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php elseif (!empty($s['is_archived'])): ?>
                                    <span class="badge bg-secondary">Archived</span>
                                    <?php else: ?>
                                    <span class="badge bg-light text-dark border">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($s['roster_locked'])): ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-lock-fill"></i> Locked</span>
                                    <?php if (!empty($s['roster_lock_date'])): ?>
                                    <div class="small text-muted"><?= sanitize(formatDate($s['roster_lock_date'])) ?></div>
                                    <?php endif; ?>
                                    <?php elseif (!empty($s['roster_lock_date']) && $s['roster_lock_date'] > date('Y-m-d')): ?>
                                    <span class="badge bg-info text-dark"><i class="bi bi-calendar-event"></i> Locks <?= sanitize(formatDate($s['roster_lock_date'])) ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-light text-dark border">Open</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-nowrap">
                                    <a href="?edit=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <a href="<?= BASE_URL ?>/intramurals/index.php?season_id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
                                    <?php if (empty($s['is_active'])): ?>
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                        <button class="btn btn-sm btn-success" data-confirm="Set this as the active intramurals year?">Set Active</button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if (canLockRoster() && !empty($s['is_active'])): ?>
                                        <?php if (!empty($s['roster_locked']) || (!empty($s['roster_lock_date']) && $s['roster_lock_date'] > date('Y-m-d'))): ?>
                                        <form method="POST" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="unlock_roster">
                                            <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                            <button class="btn btn-sm btn-outline-success" data-confirm="Unlock roster for this season?">Unlock</button>
                                        </form>
                                        <?php endif; ?>
                                        <?php if (empty($s['roster_locked'])): ?>
                                        <form method="POST" class="d-inline-flex align-items-center gap-1">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="lock_roster">
                                            <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                            <input type="date" name="lock_date" class="form-control form-control-sm" style="width:10.5rem" required value="<?= sanitize($s['roster_lock_date'] ?? date('Y-m-d')) ?>">
                                            <button class="btn btn-sm btn-outline-warning" data-confirm="Save roster lock date? Today or past dates lock immediately; future dates schedule automatic locking.">Set Lock Date</button>
                                        </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (empty($s['is_active']) && empty($s['is_archived'])): ?>
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="archive">
                                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" data-confirm="Archive this season?">Archive</button>
                                    </form>
                                    <?php elseif (!empty($s['is_archived'])): ?>
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                        <button class="btn btn-sm btn-outline-success">Restore</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($seasons)): ?>
                            <tr><td colspan="6" class="text-muted p-3">No seasons yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="alert alert-info mt-3 mb-0">
            <strong>How it works:</strong> Only one season can be <em>Active</em>.
            Matches, athlete event registrations, event coaches, standings, and reports are stored per season.
            Switch years in the season bar to view historical results without mixing them.
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
