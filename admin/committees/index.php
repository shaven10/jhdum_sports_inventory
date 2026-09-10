<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole(['admin']);
ensureWorkingCommitteesSchema();

$db = getDB();
$errors = [];
$editCommittee = null;
$categoryOptions = workingCommitteeCategoryOptions();
$activeSeason = getActiveSeason();
$activeSeasonId = $activeSeason ? (int) $activeSeason['id'] : null;
$activeSeasonLabel = $activeSeason ? seasonLabel($activeSeason) : 'No active season';

if (!$activeSeasonId) {
    flash('error', 'Set an active intramural season before managing working committees.');
    redirect(BASE_URL . '/intramurals/seasons/index.php');
}

if (isset($_GET['edit'])) {
    $editCommittee = getWorkingCommitteeById((int) $_GET['edit']);
    if ($editCommittee && (int) ($editCommittee['season_id'] ?? 0) !== $activeSeasonId) {
        flash('error', 'That committee belongs to another season. Switch the active season to edit it.');
        redirect(BASE_URL . '/admin/committees/index.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $action = post('action', 'create');

    if ($action === 'create' || $action === 'update') {
        $name = trim(post('name'));
        $description = trim(post('description'));
        $category = normalizeWorkingCommitteeCategory(post('category', 'overall'));
        $sortOrder = (int) post('sort_order', '0');
        $id = (int) post('committee_id');

        if ($name === '') {
            $errors[] = 'Committee name is required.';
        } elseif (mb_strlen($name) > 150) {
            $errors[] = 'Committee name must be 150 characters or fewer.';
        }

        if ($errors === []) {
            try {
                if ($action === 'create') {
                    $db->prepare('INSERT INTO working_committees (season_id, name, category, description, sort_order, is_active) VALUES (?, ?, ?, ?, ?, 1)')
                        ->execute([$activeSeasonId, $name, $category, $description !== '' ? $description : null, $sortOrder]);
                    $newId = (int) $db->lastInsertId();
                    auditLog((int) $_SESSION['user_id'], 'create', 'working_committee', $newId, null, [
                        'name' => $name,
                        'category' => $category,
                        'season_id' => $activeSeasonId,
                    ]);
                    flash('success', 'Committee created. Add members next.');
                    redirect(BASE_URL . '/admin/committees/members.php?committee_id=' . $newId);
                }

                $existing = getWorkingCommitteeById($id);
                if (!$existing || (int) ($existing['season_id'] ?? 0) !== $activeSeasonId) {
                    $errors[] = 'Committee not found in the active season.';
                } else {
                    $db->prepare('UPDATE working_committees SET name = ?, category = ?, description = ?, sort_order = ? WHERE id = ? AND season_id = ?')
                        ->execute([$name, $category, $description !== '' ? $description : null, $sortOrder, $id, $activeSeasonId]);
                    auditLog((int) $_SESSION['user_id'], 'update', 'working_committee', $id, [
                        'name' => $existing['name'],
                        'category' => $existing['category'] ?? null,
                    ], [
                        'name' => $name,
                        'category' => $category,
                    ]);
                    flash('success', 'Committee updated.');
                    redirect(BASE_URL . '/admin/committees/index.php');
                }
            } catch (PDOException $e) {
                $errors[] = 'A committee with this name already exists for the active season.';
                if ($action === 'update' && $id) {
                    $editCommittee = getWorkingCommitteeById($id);
                }
            }
        } elseif ($action === 'update' && $id) {
            $editCommittee = getWorkingCommitteeById($id) ?: [
                'id' => $id,
                'name' => $name,
                'category' => $category,
                'description' => $description,
                'sort_order' => $sortOrder,
            ];
        }
    }

    if ($action === 'deactivate') {
        $id = (int) post('committee_id');
        $committee = getWorkingCommitteeById($id);
        if ($committee && (int) ($committee['season_id'] ?? 0) === $activeSeasonId) {
            $db->prepare('UPDATE working_committees SET is_active = 0 WHERE id = ? AND season_id = ?')->execute([$id, $activeSeasonId]);
            auditLog((int) $_SESSION['user_id'], 'deactivate', 'working_committee', $id);
            flash('success', 'Committee hidden from the public page.');
        }
        redirect(BASE_URL . '/admin/committees/index.php');
    }

    if ($action === 'activate') {
        $id = (int) post('committee_id');
        $committee = getWorkingCommitteeById($id);
        if ($committee && (int) ($committee['season_id'] ?? 0) === $activeSeasonId) {
            $db->prepare('UPDATE working_committees SET is_active = 1 WHERE id = ? AND season_id = ?')->execute([$id, $activeSeasonId]);
            auditLog((int) $_SESSION['user_id'], 'activate', 'working_committee', $id);
            flash('success', 'Committee is now visible on the public page.');
        }
        redirect(BASE_URL . '/admin/committees/index.php');
    }

    if ($action === 'delete') {
        $id = (int) post('committee_id');
        $committee = getWorkingCommitteeById($id);
        if ($committee && (int) ($committee['season_id'] ?? 0) === $activeSeasonId) {
            $db->prepare('DELETE FROM working_committee_members WHERE committee_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM working_committees WHERE id = ? AND season_id = ?')->execute([$id, $activeSeasonId]);
            auditLog((int) $_SESSION['user_id'], 'delete', 'working_committee', $id, ['name' => $committee['name']]);
            flash('success', 'Committee and its members deleted.');
        }
        redirect(BASE_URL . '/admin/committees/index.php');
    }

    if ($action === 'reseed') {
        $sporting = seedSportingEventWorkingCommittees(true, $activeSeasonId);
        $socio = seedSocioCulturalWorkingCommittees(true, $activeSeasonId);
        $overall = seedOverallWorkingCommittees(true, $activeSeasonId);
        $created = $sporting + $socio + $overall;
        auditLog((int) $_SESSION['user_id'], 'reseed', 'working_committee', null, null, [
            'sporting_created' => $sporting,
            'socio_created' => $socio,
            'overall_created' => $overall,
            'season_id' => $activeSeasonId,
        ]);
        flash('success', $created > 0
            ? 'Restored ' . $created . ' committee(s) for ' . $activeSeasonLabel . ' (' . $overall . ' overall, ' . $sporting . ' sporting, ' . $socio . ' socio-cultural).'
            : 'All document committees are already present for the active season.');
        redirect(BASE_URL . '/admin/committees/index.php');
    }
}

$filterCategory = get('category', 'all');
$committees = getWorkingCommittees(false, $filterCategory === 'all' ? null : $filterCategory, $activeSeasonId);
$formName = $editCommittee['name'] ?? post('name');
$formCategory = $editCommittee['category'] ?? post('category', 'sporting_events');
$formDescription = $editCommittee['description'] ?? post('description');
$formSort = isset($editCommittee['sort_order']) ? (string) $editCommittee['sort_order'] : post('sort_order', (string) count($committees));

$pageTitle = 'Working Committees';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-people"></i> Working Committees</h1>
        <p class="text-muted mb-0">
            Bound to the active season: <strong><?= sanitize($activeSeasonLabel) ?></strong>.
            Change the active season under Intramurals → Seasons to manage a different set.
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/intramurals/seasons/index.php" class="btn btn-outline-secondary"><i class="bi bi-calendar3"></i> Seasons</a>
        <a href="<?= BASE_URL ?>/committees.php" class="btn btn-outline-primary" target="_blank"><i class="bi bi-box-arrow-up-right"></i> Public page</a>
        <form method="POST" class="d-inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reseed">
            <button type="submit" class="btn btn-outline-warning"
                    data-confirm="Re-add overall, sporting event, and socio-cultural committees from the official document for <?= sanitize($activeSeasonLabel) ?>? Existing committees in this season are left untouched.">
                <i class="bi bi-arrow-counterclockwise"></i> Restore document defaults
            </button>
        </form>
        <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-outline-secondary">Back to Admin</a>
    </div>
</div>

<div class="filter-bar mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label" for="categoryFilter">Group</label>
            <select name="category" id="categoryFilter" class="form-select" onchange="this.form.submit()">
                <option value="all" <?= $filterCategory === 'all' ? 'selected' : '' ?>>All groups</option>
                <?php foreach ($categoryOptions as $key => $label): ?>
                <option value="<?= sanitize($key) ?>" <?= $filterCategory === $key ? 'selected' : '' ?>><?= sanitize($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-<?= $editCommittee ? 'pencil' : 'plus-circle' ?>"></i>
                <?= $editCommittee ? 'Edit Committee' : 'New Committee' ?>
            </div>
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
                </div>
                <?php endif; ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editCommittee ? 'update' : 'create' ?>">
                    <?php if ($editCommittee): ?>
                    <input type="hidden" name="committee_id" value="<?= (int) $editCommittee['id'] ?>">
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label" for="name">Committee name *</label>
                        <input type="text" name="name" id="name" class="form-control" required maxlength="150"
                               value="<?= sanitize((string) $formName) ?>"
                               placeholder="e.g. VOLLEYBALL Men">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="category">Group *</label>
                        <select name="category" id="category" class="form-select" required>
                            <?php foreach ($categoryOptions as $key => $label): ?>
                            <option value="<?= sanitize($key) ?>" <?= $formCategory === $key ? 'selected' : '' ?>><?= sanitize($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="description">Description</label>
                        <textarea name="description" id="description" class="form-control" rows="3"
                                  placeholder="Optional notes (e.g. Game: MLBB / CODM)"><?= sanitize((string) $formDescription) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="sort_order">Sort order</label>
                        <input type="number" name="sort_order" id="sort_order" class="form-control"
                               value="<?= sanitize((string) $formSort) ?>">
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary"><?= $editCommittee ? 'Save Changes' : 'Add Committee' ?></button>
                        <?php if ($editCommittee): ?>
                        <a href="<?= BASE_URL ?>/admin/committees/index.php" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Committees for active season</div>
            <div class="card-body p-0">
                <?php if (empty($committees)): ?>
                <div class="p-4 text-muted">No committees for this season yet. Add one, or restore document defaults.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Committee</th>
                                <th>Group</th>
                                <th>Members</th>
                                <th>Order</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($committees as $c): ?>
                            <tr>
                                <td>
                                    <strong><?= sanitize($c['name']) ?></strong>
                                    <?php if (!empty($c['description'])): ?>
                                    <br><small class="text-muted"><?= sanitize($c['description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-secondary"><?= sanitize(workingCommitteeCategoryLabel((string) ($c['category'] ?? 'overall'))) ?></span></td>
                                <td><?= (int) ($c['member_count'] ?? 0) ?></td>
                                <td><?= (int) $c['sort_order'] ?></td>
                                <td>
                                    <?= !empty($c['is_active'])
                                        ? '<span class="badge bg-success">Visible</span>'
                                        : '<span class="badge bg-secondary">Hidden</span>' ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="<?= BASE_URL ?>/admin/committees/members.php?committee_id=<?= (int) $c['id'] ?>"
                                       class="btn btn-sm btn-outline-primary" title="Members">
                                        <i class="bi bi-people"></i>
                                    </a>
                                    <a href="?edit=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if (!empty($c['is_active'])): ?>
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="deactivate">
                                        <input type="hidden" name="committee_id" value="<?= (int) $c['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-warning" title="Hide"><i class="bi bi-eye-slash"></i></button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="committee_id" value="<?= (int) $c['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Show"><i class="bi bi-eye"></i></button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="committee_id" value="<?= (int) $c['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                                data-confirm="Delete this committee and all its members?" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
