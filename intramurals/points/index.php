<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsModule();

$db = getDB();
$canEdit = canManageIntramurals();
$labels = placementLabels();
$defaults = [1 => 10, 2 => 7, 3 => 5, 4 => 3, 5 => 2, 6 => 1];

$formState = null;
if (!empty($_SESSION['point_scheme_form'])) {
    $formState = $_SESSION['point_scheme_form'];
    unset($_SESSION['point_scheme_form']);
}

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
        $errors = [];

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
                redirect(BASE_URL . '/intramurals/points/index.php');
            } catch (PDOException $e) {
                $errors[] = 'Could not save scheme (duplicate name?).';
            }
        }

        if (!empty($errors)) {
            $_SESSION['point_scheme_form'] = [
                'mode' => $id ? 'edit' : 'add',
                'id' => $id,
                'errors' => $errors,
                'data' => [
                    'name' => $name,
                    'description' => $description,
                    'points_1' => $p1,
                    'points_2' => $p2,
                    'points_3' => $p3,
                    'points_4' => $p4,
                    'points_5' => $p5,
                    'points_6' => $p6,
                ],
            ];
            redirect(BASE_URL . '/intramurals/points/index.php' . ($id ? '?edit=' . $id : ''));
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
$schemes = getAllPointSchemes(false);
$sports = $db->query('SELECT s.id, s.name, s.category, s.point_scheme_id, ps.name as scheme_name
    FROM intramural_sports s
    LEFT JOIN intramural_point_schemes ps ON s.point_scheme_id = ps.id
    ORDER BY s.name, s.category')->fetchAll();
$activeSchemes = getAllPointSchemes(true);

$formDefaults = [
    'name' => '',
    'description' => '',
    'points_1' => $defaults[1],
    'points_2' => $defaults[2],
    'points_3' => $defaults[3],
    'points_4' => $defaults[4],
    'points_5' => $defaults[5],
    'points_6' => $defaults[6],
];
$formData = $formDefaults;
$formErrors = [];
$openModal = '';

if ($formState) {
    $openModal = $formState['mode'];
    $formErrors = $formState['errors'] ?? [];
    $formData = array_merge($formDefaults, $formState['data'] ?? []);
    if ($openModal === 'edit' && !empty($formState['id'])) {
        $editId = (int) $formState['id'];
    }
} elseif ($editId) {
    $openModal = 'edit';
    foreach ($schemes as $scheme) {
        if ((int) $scheme['id'] === $editId) {
            $formData = [
                'name' => $scheme['name'],
                'description' => $scheme['description'] ?? '',
                'points_1' => (int) $scheme['points_1'],
                'points_2' => (int) $scheme['points_2'],
                'points_3' => (int) $scheme['points_3'],
                'points_4' => (int) $scheme['points_4'],
                'points_5' => (int) $scheme['points_5'],
                'points_6' => (int) $scheme['points_6'],
            ];
            break;
        }
    }
}

$pageTitle = 'Placement Point System';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <div>
        <h1 class="mb-1"><i class="bi bi-calculator"></i> Placement Point System</h1>
        <p class="text-muted mb-0">Dynamic points for Champion through 5th Runner Up — used in overall rankings</p>
    </div>
    <div class="d-flex flex-wrap gap-2 ms-auto">
        <?php if ($canEdit): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#schemeModal" data-scheme-mode="add">
            <i class="bi bi-plus-lg"></i> Add Point Scheme
        </button>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/intramurals/index.php" class="btn btn-sm btn-outline-secondary">Back</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">Point Reference</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Scheme</th>
                        <?php foreach ($labels as $label): ?>
                        <th><?= sanitize($label) ?></th>
                        <?php endforeach; ?>
                        <th>Status</th>
                        <?php if ($canEdit): ?><th class="text-end" style="width: 9rem;">Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($schemes)): ?>
                    <tr><td colspan="<?= $canEdit ? 9 : 8 ?>" class="text-center text-muted py-4">No schemes yet. Add one to get started.</td></tr>
                    <?php else: ?>
                    <?php foreach ($schemes as $s): ?>
                    <?php
                    $schemePayload = htmlspecialchars(json_encode([
                        'id' => (int) $s['id'],
                        'name' => $s['name'],
                        'description' => $s['description'] ?? '',
                        'points_1' => (int) $s['points_1'],
                        'points_2' => (int) $s['points_2'],
                        'points_3' => (int) $s['points_3'],
                        'points_4' => (int) $s['points_4'],
                        'points_5' => (int) $s['points_5'],
                        'points_6' => (int) $s['points_6'],
                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                    ?>
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
                        <td class="text-end text-nowrap">
                            <button type="button"
                                    class="btn btn-sm btn-outline-primary btn-edit-scheme"
                                    data-bs-toggle="modal"
                                    data-bs-target="#schemeModal"
                                    data-scheme-mode="edit"
                                    data-scheme="<?= $schemePayload ?>"
                                    title="Edit scheme">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_scheme">
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="<?= $s['is_active'] ? 'Disable' : 'Enable' ?>">
                                    <i class="bi bi-<?= $s['is_active'] ? 'pause' : 'play' ?>"></i>
                                </button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($canEdit): ?>
<div class="card mb-4">
    <div class="card-header">Assign Schemes to Events / Sports</div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="assign_sports">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-3">
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
                            <td style="min-width: 220px;">
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
                        <?php if (empty($sports)): ?>
                        <tr><td colspan="3" class="text-muted">No sports to assign.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" class="btn btn-primary">Save Assignments</button>
        </form>
    </div>
</div>

<div class="modal fade" id="schemeModal" tabindex="-1" aria-labelledby="schemeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" id="schemeForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_scheme">
                <input type="hidden" name="id" id="schemeId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="schemeModalLabel"><i class="bi bi-plus-lg"></i> Add Point Scheme</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="schemeFormErrors" class="alert alert-danger d-none"></div>
                    <div class="mb-3">
                        <label class="form-label" for="schemeName">Scheme Name *</label>
                        <input type="text" name="name" id="schemeName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="schemeDescription">Description / Events</label>
                        <textarea name="description" id="schemeDescription" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="row g-2">
                        <?php foreach ($labels as $rank => $label): ?>
                        <div class="col-md-6 col-lg-4">
                            <label class="form-label" for="points_<?= $rank ?>"><?= sanitize($label) ?></label>
                            <input type="number" name="points_<?= $rank ?>" id="points_<?= $rank ?>" class="form-control" min="0" required value="<?= $defaults[$rank] ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="schemeFormSubmit">Add Scheme</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const modalEl = document.getElementById('schemeModal');
    const form = document.getElementById('schemeForm');
    const schemeId = document.getElementById('schemeId');
    const modalTitle = document.getElementById('schemeModalLabel');
    const formSubmit = document.getElementById('schemeFormSubmit');
    const errorsBox = document.getElementById('schemeFormErrors');
    const defaultPoints = <?= json_encode($defaults) ?>;

    function showErrors(errors) {
        if (!errors || !errors.length) {
            errorsBox.classList.add('d-none');
            return;
        }
        errorsBox.innerHTML = '<ul class="mb-0">' + errors.map(function (e) {
            return '<li>' + String(e).replace(/</g, '&lt;') + '</li>';
        }).join('') + '</ul>';
        errorsBox.classList.remove('d-none');
    }

    function setMode(mode, data) {
        data = data || {};
        schemeId.value = data.id || '';
        form.reset();

        if (mode === 'add') {
            modalTitle.innerHTML = '<i class="bi bi-plus-lg"></i> Add Point Scheme';
            formSubmit.textContent = 'Add Scheme';
        } else {
            modalTitle.innerHTML = '<i class="bi bi-pencil"></i> Edit Point Scheme';
            formSubmit.textContent = 'Update Scheme';
        }

        document.getElementById('schemeName').value = data.name || '';
        document.getElementById('schemeDescription').value = data.description || '';

        for (let rank = 1; rank <= 6; rank++) {
            const field = document.getElementById('points_' + rank);
            if (field) {
                field.value = data['points_' + rank] ?? defaultPoints[rank] ?? 0;
            }
        }

        showErrors([]);
    }

    document.querySelectorAll('[data-bs-target="#schemeModal"]').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            const mode = this.dataset.schemeMode || 'add';
            let data = {};
            if (mode === 'edit' && this.dataset.scheme) {
                try {
                    data = JSON.parse(this.dataset.scheme);
                } catch (e) {
                    data = {};
                }
            }
            setMode(mode, data);
        });
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
        if (window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.delete('edit');
            window.history.replaceState({}, '', url.pathname + url.search);
        }
    });

    const bootMode = <?= json_encode($openModal) ?>;
    const bootData = <?= json_encode(array_merge(['id' => $editId ?: null], $formData), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const bootErrors = <?= json_encode($formErrors, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    if (bootMode) {
        setMode(bootMode, bootData);
        showErrors(bootErrors);
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
})();
</script>
<?php endif; ?>

<div class="alert alert-info mb-0">
    <strong>How ranking works:</strong> Match results determine each event’s finish order (Champion, 1st Runner Up, …).
    Staff can <a href="<?= BASE_URL ?>/intramurals/rankings/index.php">enter official ranks directly</a> only for events that do not have scheduled matches.
    Overall intramurals standing sums the <em>placement points</em> from each event’s assigned scheme.
    Change scheme values anytime — rankings recalculate automatically.
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
