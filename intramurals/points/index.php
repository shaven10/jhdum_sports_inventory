<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$db = getDB();
$canEdit = canManageIntramurals();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/intramurals/points/index.php');
    }

    $action = post('action');

    if ($action === 'save_scheme') {
        $id = (int) post('id');
        $name = post('name');
        $description = post('description');
        $p1 = max(0, (int) post('points_1'));
        $p2 = max(0, (int) post('points_2'));
        $p3 = max(0, (int) post('points_3'));
        $p4 = max(0, (int) post('points_4'));
        $p5 = max(0, (int) post('points_5'));
        $p6 = max(0, (int) post('points_6'));

        if ($name === '') {
            $errors[] = 'Scheme name is required.';
        }

        if (empty($errors)) {
            try {
                if ($id) {
                    $stmt = $db->prepare('UPDATE intramural_point_schemes SET name=?, description=?, points_1=?, points_2=?, points_3=?, points_4=?, points_5=?, points_6=? WHERE id=?');
                    $stmt->execute([$name, $description, $p1, $p2, $p3, $p4, $p5, $p6, $id]);
                    auditLog($_SESSION['user_id'], 'update', 'point_scheme', $id, null, ['name' => $name]);
                    flash('success', 'Point scheme updated.');
                } else {
                    $stmt = $db->prepare('INSERT INTO intramural_point_schemes (name, description, points_1, points_2, points_3, points_4, points_5, points_6) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$name, $description, $p1, $p2, $p3, $p4, $p5, $p6]);
                    auditLog($_SESSION['user_id'], 'create', 'point_scheme', (int) $db->lastInsertId(), null, ['name' => $name]);
                    flash('success', 'Point scheme created.');
                }
            } catch (PDOException $e) {
                flash('error', 'Could not save scheme (duplicate name?).');
            }
            redirect(BASE_URL . '/intramurals/points/index.php');
        }
    }

    if ($action === 'toggle_scheme') {
        $id = (int) post('id');
        $db->prepare('UPDATE intramural_point_schemes SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash('success', 'Scheme status updated.');
        redirect(BASE_URL . '/intramurals/points/index.php');
    }

    if ($action === 'assign_sports') {
        $assignments = $_POST['sport_scheme'] ?? [];
        if (is_array($assignments)) {
            $stmt = $db->prepare('UPDATE intramural_sports SET point_scheme_id = ? WHERE id = ?');
            foreach ($assignments as $sportId => $schemeId) {
                $sid = (int) $sportId;
                $psid = (int) $schemeId ?: null;
                $stmt->execute([$psid, $sid]);
            }
            auditLog($_SESSION['user_id'], 'assign_point_schemes', 'intramural_sport', null);
            flash('success', 'Sport point schemes updated.');
        }
        redirect(BASE_URL . '/intramurals/points/index.php');
    }
}

$editId = (int) get('edit');
$editScheme = null;
if ($editId) {
    $stmt = $db->prepare('SELECT * FROM intramural_point_schemes WHERE id = ?');
    $stmt->execute([$editId]);
    $editScheme = $stmt->fetch() ?: null;
}

$schemes = getAllPointSchemes(false);
$sports = $db->query('SELECT s.id, s.name, s.category, s.point_scheme_id, ps.name as scheme_name
    FROM intramural_sports s
    LEFT JOIN intramural_point_schemes ps ON s.point_scheme_id = ps.id
    WHERE s.is_active = 1
    ORDER BY s.name, s.category')->fetchAll();
$activeSchemes = getAllPointSchemes(true);

$pageTitle = 'Placement Point System';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
$labels = placementLabels();
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-calculator"></i> Placement Point System</h1>
        <p class="text-muted mb-0">Dynamic points for Champion through 5th Runner Up — used in overall rankings</p>
    </div>
    <a href="<?= BASE_URL ?>/intramurals/index.php" class="btn btn-outline-secondary">Back</a>
</div>

<div class="card mb-4">
    <div class="card-header">Point Reference</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Scheme</th>
                        <?php foreach ($labels as $label): ?>
                        <th><?= sanitize($label) ?></th>
                        <?php endforeach; ?>
                        <th>Status</th>
                        <?php if ($canEdit): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schemes as $s): ?>
                    <tr class="<?= $s['is_active'] ? '' : 'table-secondary' ?>">
                        <td>
                            <strong><?= sanitize($s['name']) ?></strong>
                            <?php if ($s['description']): ?><br><small class="text-muted"><?= sanitize($s['description']) ?></small><?php endif; ?>
                        </td>
                        <td><strong><?= (int) $s['points_1'] ?></strong></td>
                        <td><?= (int) $s['points_2'] ?></td>
                        <td><?= (int) $s['points_3'] ?></td>
                        <td><?= (int) $s['points_4'] ?></td>
                        <td><?= (int) $s['points_5'] ?></td>
                        <td><?= (int) $s['points_6'] ?></td>
                        <td><?= $s['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                        <?php if ($canEdit): ?>
                        <td class="text-nowrap">
                            <a href="?edit=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_scheme">
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary"><?= $s['is_active'] ? 'Disable' : 'Enable' ?></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($schemes)): ?>
                    <tr><td colspan="9" class="text-muted p-3">No schemes yet. Run the points migration or add one below.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($canEdit): ?>
<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><?= $editScheme ? 'Edit Point Scheme' : 'Add Point Scheme' ?></div>
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_scheme">
                    <?php if ($editScheme): ?><input type="hidden" name="id" value="<?= $editScheme['id'] ?>"><?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Scheme Name *</label>
                        <input type="text" name="name" class="form-control" required value="<?= sanitize($editScheme['name'] ?? post('name')) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description / Events</label>
                        <textarea name="description" class="form-control" rows="2"><?= sanitize($editScheme['description'] ?? post('description')) ?></textarea>
                    </div>
                    <div class="row g-2">
                        <?php
                        $defaults = [1 => 10, 2 => 7, 3 => 5, 4 => 3, 5 => 2, 6 => 1];
                        foreach ($labels as $rank => $label):
                            $field = 'points_' . $rank;
                            $val = (int) ($editScheme[$field] ?? post($field, (string) $defaults[$rank]));
                        ?>
                        <div class="col-6">
                            <label class="form-label"><?= sanitize($label) ?></label>
                            <input type="number" name="<?= $field ?>" class="form-control" min="0" required value="<?= $val ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <button class="btn btn-primary"><?= $editScheme ? 'Update Scheme' : 'Add Scheme' ?></button>
                        <?php if ($editScheme): ?><a href="<?= BASE_URL ?>/intramurals/points/index.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">Assign Schemes to Events / Sports</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="assign_sports">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr><th>Event</th><th>Point Scheme</th><th>Values</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sports as $sport): ?>
                                <?php
                                    $current = null;
                                    foreach ($activeSchemes as $as) {
                                        if ((int) $as['id'] === (int) $sport['point_scheme_id']) {
                                            $current = $as;
                                            break;
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><?= sanitize(sportLabel($sport)) ?></td>
                                    <td>
                                        <select name="sport_scheme[<?= $sport['id'] ?>]" class="form-select form-select-sm">
                                            <option value="">Default (10/7/5/3/2/1)</option>
                                            <?php foreach ($activeSchemes as $as): ?>
                                            <option value="<?= $as['id'] ?>" <?= (int) $sport['point_scheme_id'] === (int) $as['id'] ? 'selected' : '' ?>>
                                                <?= sanitize($as['name']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="small text-muted"><?= $current ? sanitize(formatSchemePoints($current)) : '10/7/5/3/2/1' ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button class="btn btn-primary">Save Assignments</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="alert alert-info mb-0">
    <strong>How ranking works:</strong> Match results determine each event’s finish order (Champion, 1st Runner Up, …).
    Overall intramurals standing sums the <em>placement points</em> from each event’s assigned scheme.
    Change scheme values anytime — rankings recalculate automatically.
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
