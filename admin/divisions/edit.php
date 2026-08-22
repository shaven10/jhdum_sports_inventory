<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole(['admin']);
ensureIntramuralDivisionsSchema();

$db = getDB();
$id = (int) get('id');
$division = getDivisionById($id);
if (!$division) {
    flash('error', 'Division not found.');
    redirect(BASE_URL . '/admin/divisions/index.php');
}

$errors = [];
$sports = $db->query('SELECT * FROM intramural_sports ORDER BY name, category')->fetchAll();
$teams = $db->query('SELECT id, name, short_name, department, division_id, is_active FROM intramural_teams ORDER BY is_active DESC, name ASC')->fetchAll();
$selectedSportIds = getDivisionSportIds($id);
$selectedTeamIds = [];
foreach ($teams as $t) {
    if ((int) ($t['division_id'] ?? 0) === $id) {
        $selectedTeamIds[] = (int) $t['id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $name = trim(post('name'));
    $description = trim(post('description'));
    $sortOrder = (int) post('sort_order', '0');
    $isActive = post('is_active') === '1' ? 1 : 0;
    $postedSports = $_POST['sport_ids'] ?? [];
    $postedTeams = $_POST['team_ids'] ?? [];
    if (!is_array($postedSports)) {
        $postedSports = [];
    }
    if (!is_array($postedTeams)) {
        $postedTeams = [];
    }
    $selectedSportIds = array_values(array_unique(array_filter(array_map('intval', $postedSports))));
    $selectedTeamIds = array_values(array_unique(array_filter(array_map('intval', $postedTeams))));

    if ($name === '') {
        $errors[] = 'Division name is required.';
    } else {
        $dup = $db->prepare('SELECT id FROM intramural_divisions WHERE name = ? AND id != ? LIMIT 1');
        $dup->execute([$name, $id]);
        if ($dup->fetch()) {
            $errors[] = 'Another division already uses this name.';
        }
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();
            $db->prepare('UPDATE intramural_divisions SET name = ?, description = ?, sort_order = ?, is_active = ? WHERE id = ?')
                ->execute([$name, $description !== '' ? $description : null, $sortOrder, $isActive, $id]);
            saveDivisionSports($id, $selectedSportIds);
            saveDivisionTeams($id, $selectedTeamIds);
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $sqlState = (string) (($e->errorInfo[0] ?? null) ?: $e->getCode());
            $message = $e->getMessage();
            if ($sqlState === '23000' && str_contains($message, 'uq_division_name')) {
                $errors[] = 'Another division already uses this name.';
            } elseif ($sqlState === '23000' && str_contains($message, 'uq_division_sport')) {
                $errors[] = 'Could not save events for this division. Remove duplicate event selections and try again.';
            } elseif ($sqlState === '23000') {
                $errors[] = 'Could not save division changes. A database constraint was violated.';
            } else {
                $errors[] = 'Could not save division changes. Please try again or contact support.';
            }
        }

        if (empty($errors)) {
            try {
                auditLog((int) $_SESSION['user_id'], 'update', 'intramural_division', $id, null, [
                    'name' => $name,
                    'sports' => count($selectedSportIds),
                    'teams' => count($selectedTeamIds),
                ]);
            } catch (PDOException $e) {
                // Save succeeded; do not block the user if audit logging fails.
            }
            flash('success', 'Division updated.');
            redirect(BASE_URL . '/admin/divisions/edit.php?id=' . $id);
        }
    }

    $division['name'] = $name;
    $division['description'] = $description;
    $division['sort_order'] = $sortOrder;
    $division['is_active'] = $isActive;
}

$pageTitle = 'Edit Division';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-diagram-3"></i> <?= sanitize($division['name']) ?></h1>
        <p class="text-muted mb-0">Assign teams to this division and select the events they will play.</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/divisions/index.php" class="btn btn-outline-secondary">Back to Divisions</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="POST">
    <?= csrfField() ?>
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">Division details</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="name">Name *</label>
                        <input type="text" name="name" id="name" class="form-control" required value="<?= sanitize($division['name']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="description">Description</label>
                        <textarea name="description" id="description" class="form-control" rows="3"><?= sanitize($division['description'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="sort_order">Sort order</label>
                        <input type="number" name="sort_order" id="sort_order" class="form-control" value="<?= (int) ($division['sort_order'] ?? 0) ?>">
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="is_active">Status</label>
                        <select name="is_active" id="is_active" class="form-select">
                            <option value="1" <?= (int) $division['is_active'] ? 'selected' : '' ?>>Active</option>
                            <option value="0" <?= !(int) $division['is_active'] ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="mt-3 d-grid gap-2">
                <button type="submit" class="btn btn-primary">Save Division</button>
                <?php if (count($selectedTeamIds) > 2): ?>
                <a href="<?= BASE_URL ?>/admin/team_positions/edit.php?division_id=<?= $id ?>" class="btn btn-outline-primary">
                    <i class="bi bi-list-ol"></i> Set team positions
                </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100" data-check-group="sports">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span><i class="bi bi-trophy"></i> Events for this division</span>
                    <span class="badge bg-secondary" data-selected-count><?= count($selectedSportIds) ?> selected</span>
                </div>
                <?php if (!empty($sports)): ?>
                <div class="px-3 pt-2 pb-1 border-bottom d-flex gap-2 flex-wrap small">
                    <button type="button" class="btn btn-link btn-sm p-0" data-select-all>Select all</button>
                    <span class="text-muted">·</span>
                    <button type="button" class="btn btn-link btn-sm p-0" data-select-none>Clear</button>
                </div>
                <?php endif; ?>
                <div class="card-body" style="max-height: 28rem; overflow-y: auto;">
                    <?php if (empty($sports)): ?>
                    <p class="text-muted mb-0">No events exist yet. Add sports under Intramurals first.</p>
                    <?php else: ?>
                    <?php foreach ($sports as $s): ?>
                    <?php $sid = (int) $s['id']; ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="sport_ids[]" value="<?= $sid ?>"
                               id="sport_<?= $sid ?>" data-group-item <?= in_array($sid, $selectedSportIds, true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="sport_<?= $sid ?>">
                            <?= sanitize(sportLabel($s)) ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100" data-check-group="teams">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span><i class="bi bi-shield-shaded"></i> Teams in this division</span>
                    <span class="badge bg-secondary" data-selected-count><?= count($selectedTeamIds) ?> selected</span>
                </div>
                <?php if (!empty($teams)): ?>
                <div class="px-3 pt-2 pb-1 border-bottom d-flex gap-2 flex-wrap small">
                    <button type="button" class="btn btn-link btn-sm p-0" data-select-all>Select all</button>
                    <span class="text-muted">·</span>
                    <button type="button" class="btn btn-link btn-sm p-0" data-select-none>Clear</button>
                </div>
                <?php endif; ?>
                <div class="card-body" style="max-height: 28rem; overflow-y: auto;">
                    <?php if (empty($teams)): ?>
                    <p class="text-muted mb-0">No teams registered yet.</p>
                    <?php else: ?>
                    <?php foreach ($teams as $t): ?>
                    <?php
                        $tid = (int) $t['id'];
                        $otherDiv = (int) ($t['division_id'] ?? 0);
                        $inOther = $otherDiv > 0 && $otherDiv !== $id;
                        $otherName = '';
                        if ($inOther) {
                            $od = getDivisionById($otherDiv);
                            $otherName = $od['name'] ?? ('#' . $otherDiv);
                        }
                    ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="team_ids[]" value="<?= $tid ?>"
                               id="team_<?= $tid ?>" data-group-item <?= in_array($tid, $selectedTeamIds, true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="team_<?= $tid ?>">
                            <?= sanitize($t['name']) ?>
                            <?php if (!(int) $t['is_active']): ?>
                            <span class="badge bg-warning text-dark">Inactive</span>
                            <?php endif; ?>
                            <?php if ($inOther): ?>
                            <span class="badge bg-info text-dark">Currently: <?= sanitize($otherName) ?></span>
                            <?php endif; ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
(function () {
    function updateCount(group) {
        var items = group.querySelectorAll('[data-group-item]');
        var checked = group.querySelectorAll('[data-group-item]:checked').length;
        var badge = group.querySelector('[data-selected-count]');
        if (badge) {
            badge.textContent = checked + ' selected';
        }
        return items.length;
    }

    function setAll(group, checked) {
        group.querySelectorAll('[data-group-item]').forEach(function (el) {
            el.checked = checked;
        });
        updateCount(group);
    }

    document.querySelectorAll('[data-check-group]').forEach(function (group) {
        var selectAll = group.querySelector('[data-select-all]');
        var selectNone = group.querySelector('[data-select-none]');
        if (selectAll) {
            selectAll.addEventListener('click', function () { setAll(group, true); });
        }
        if (selectNone) {
            selectNone.addEventListener('click', function () { setAll(group, false); });
        }
        group.querySelectorAll('[data-group-item]').forEach(function (el) {
            el.addEventListener('change', function () { updateCount(group); });
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
