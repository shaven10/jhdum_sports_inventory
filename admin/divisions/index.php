<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole(['admin']);
ensureIntramuralDivisionsSchema();

$db = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $action = post('action', 'create');

    if ($action === 'create') {
        $name = trim(post('name'));
        $description = trim(post('description'));
        $sortOrder = (int) post('sort_order', '0');
        if ($name === '') {
            $errors[] = 'Division name is required.';
        } else {
            try {
                $db->prepare('INSERT INTO intramural_divisions (name, description, sort_order) VALUES (?, ?, ?)')
                    ->execute([$name, $description !== '' ? $description : null, $sortOrder]);
                $id = (int) $db->lastInsertId();
                auditLog((int) $_SESSION['user_id'], 'create', 'intramural_division', $id, null, ['name' => $name]);
                flash('success', 'Division created. Assign teams and events next.');
                redirect(BASE_URL . '/admin/divisions/edit.php?id=' . $id);
            } catch (PDOException $e) {
                $errors[] = 'A division with this name already exists.';
            }
        }
    }

    if ($action === 'deactivate') {
        $id = (int) post('division_id');
        if ($id && getDivisionById($id)) {
            $db->prepare('UPDATE intramural_divisions SET is_active = 0 WHERE id = ?')->execute([$id]);
            auditLog((int) $_SESSION['user_id'], 'deactivate', 'intramural_division', $id);
            flash('success', 'Division deactivated.');
        }
        redirect(BASE_URL . '/admin/divisions/index.php');
    }

    if ($action === 'activate') {
        $id = (int) post('division_id');
        if ($id && getDivisionById($id)) {
            $db->prepare('UPDATE intramural_divisions SET is_active = 1 WHERE id = ?')->execute([$id]);
            auditLog((int) $_SESSION['user_id'], 'activate', 'intramural_division', $id);
            flash('success', 'Division activated.');
        }
        redirect(BASE_URL . '/admin/divisions/index.php');
    }
}

$divisions = getDivisions(false);

$pageTitle = 'Divisions';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-diagram-3"></i> Divisions</h1>
        <p class="text-muted mb-0">Group teams (e.g. High School vs College) and choose which events each division plays.</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-outline-secondary">Back to Admin</a>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-plus-circle"></i> New Division</div>
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
                </div>
                <?php endif; ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label" for="name">Name *</label>
                        <input type="text" name="name" id="name" class="form-control" required
                               value="<?= sanitize(post('name')) ?>" placeholder="e.g. High School">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="description">Description</label>
                        <textarea name="description" id="description" class="form-control" rows="2"><?= sanitize(post('description')) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="sort_order">Sort order</label>
                        <input type="number" name="sort_order" id="sort_order" class="form-control" value="<?= sanitize(post('sort_order', '0')) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">Create Division</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">All Divisions</div>
            <div class="card-body p-0">
                <?php if (empty($divisions)): ?>
                <div class="p-4 text-muted">No divisions yet. Create High School and College (or any grouping you need).</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Division</th>
                                <th>Teams</th>
                                <th>Events</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($divisions as $d): ?>
                            <tr>
                                <td>
                                    <strong><?= sanitize($d['name']) ?></strong>
                                    <?php if (!empty($d['description'])): ?>
                                    <div class="small text-muted"><?= sanitize($d['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int) $d['team_count'] ?></td>
                                <td><?= (int) $d['sport_count'] ?></td>
                                <td>
                                    <?php if ((int) $d['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="<?= BASE_URL ?>/admin/divisions/edit.php?id=<?= (int) $d['id'] ?>" class="btn btn-sm btn-outline-primary">Manage</a>
                                    <?php if ((int) $d['team_count'] > 2): ?>
                                    <a href="<?= BASE_URL ?>/admin/team_positions/edit.php?division_id=<?= (int) $d['id'] ?>" class="btn btn-sm btn-outline-secondary">Positions</a>
                                    <?php endif; ?>
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="division_id" value="<?= (int) $d['id'] ?>">
                                        <?php if ((int) $d['is_active']): ?>
                                        <input type="hidden" name="action" value="deactivate">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" data-confirm="Deactivate this division?">Deactivate</button>
                                        <?php else: ?>
                                        <input type="hidden" name="action" value="activate">
                                        <button type="submit" class="btn btn-sm btn-outline-success">Activate</button>
                                        <?php endif; ?>
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
        <p class="small text-muted mt-3 mb-0">
            Teams without a division can still enter any event. Once a team is assigned to a division, it may only play the events selected for that division.
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
