<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();

$db = getDB();
$id = (int) get('id');
$stmt = $db->prepare('SELECT * FROM intramural_teams WHERE id = ?');
$stmt->execute([$id]);
$team = $stmt->fetch();

if (!$team) {
    flash('error', 'Team not found.');
    redirect(BASE_URL . '/intramurals/teams/index.php');
}

if (!canEditOwnTeam($id)) {
    flash('error', 'You do not have permission to edit this team.');
    redirect(BASE_URL . '/intramurals/teams/view.php?id=' . $id);
}

$isFullAdmin = canManageIntramurals();
$errors = [];

$managers = $db->query("SELECT id, first_name, last_name, username FROM users WHERE role = 'unit_manager' AND is_active = 1 ORDER BY first_name, last_name")->fetchAll();
$coaches = $db->query("SELECT id, first_name, last_name, username FROM users WHERE role = 'coach' AND is_active = 1 ORDER BY first_name, last_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/intramurals/teams/edit.php?id=' . $id);
    }

    if ($isFullAdmin && post('action') === 'deactivate') {
        $db->prepare('UPDATE intramural_teams SET is_active = 0 WHERE id = ?')->execute([$id]);
        auditLog($_SESSION['user_id'], 'delete', 'intramural_team', $id);
        flash('success', 'Team deactivated.');
        redirect(BASE_URL . '/intramurals/teams/index.php');
    }

    $name = $isFullAdmin ? post('name') : $team['name'];
    $shortName = $isFullAdmin ? post('short_name') : ($team['short_name'] ?? '');
    $color = post('color', '#1a5276');
    $department = post('department');
    $coachName = post('coach_name');
    $description = post('description');
    $isActive = $isFullAdmin ? (post('is_active') === '1' ? 1 : 0) : (int) $team['is_active'];
    if ($isFullAdmin) {
        $unitManagerId = (int) post('unit_manager_id') ?: null;
    } else {
        $unitManagerId = !empty($team['unit_manager_id']) ? (int) $team['unit_manager_id'] : null;
    }
    $coachUserId = !empty($team['coach_user_id']) ? (int) $team['coach_user_id'] : null;

    if ($name === '') {
        $errors[] = 'Team name is required.';
    }

    $logo = $team['logo'];
    if (!empty($_FILES['logo']['name'])) {
        $newLogo = uploadTeamLogo($_FILES['logo']);
        if (!$newLogo) {
            $errors[] = 'Invalid logo image.';
        } else {
            deleteUploadedFile($logo, UPLOAD_PATH_TEAMS);
            $logo = $newLogo;
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $db->prepare('UPDATE intramural_teams SET name=?, short_name=?, color=?, logo=?, department=?, coach_name=?, unit_manager_id=?, coach_user_id=?, description=?, is_active=? WHERE id=?');
            $stmt->execute([$name, $shortName, $color, $logo, $department, $coachName, $unitManagerId, $coachUserId, $description, $isActive, $id]);

            if ($isFullAdmin) {
                if ($unitManagerId) {
                    syncUserTeamAssignment($unitManagerId, 'unit_manager', $id);
                }
                if (!$unitManagerId && !empty($team['unit_manager_id'])) {
                    $db->prepare('UPDATE users SET team_id = NULL WHERE id = ? AND role = ?')->execute([$team['unit_manager_id'], 'unit_manager']);
                    $db->prepare('UPDATE intramural_teams SET unit_manager_id = NULL WHERE id = ?')->execute([$id]);
                }
            }

            auditLog($_SESSION['user_id'], 'update', 'intramural_team', $id, null, ['name' => $name]);
            flash('success', 'Team updated.');
            redirect(BASE_URL . '/intramurals/teams/view.php?id=' . $id);
        } catch (PDOException $e) {
            $errors[] = 'A team with this name already exists.';
        }
    }
}

$pageTitle = 'Edit Team';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h1 class="mb-0"><i class="bi bi-pencil"></i> Edit Team</h1>
    <a href="<?= BASE_URL ?>/intramurals/teams/coaches.php?id=<?= $id ?>" class="btn btn-primary"><i class="bi bi-person-badge"></i> Assign Event Coaches</a>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Team / House Name *</label>
                            <input type="text" name="name" class="form-control" required value="<?= sanitize($team['name']) ?>" <?= $isFullAdmin ? '' : 'readonly' ?>>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Short Name</label>
                            <input type="text" name="short_name" class="form-control" value="<?= sanitize($team['short_name'] ?? '') ?>" <?= $isFullAdmin ? '' : 'readonly' ?>>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Team Color</label>
                            <input type="color" name="color" class="form-control form-control-color w-100" value="<?= sanitize($team['color'] ?: '#1a5276') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department / College</label>
                            <input type="text" name="department" class="form-control" value="<?= sanitize($team['department'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Notes / Display Label</label>
                            <input type="text" name="coach_name" class="form-control" value="<?= sanitize($team['coach_name'] ?? '') ?>" placeholder="Optional team note">
                        </div>
                        <?php if ($isFullAdmin): ?>
                        <div class="col-md-6">
                            <label class="form-label">Unit Manager Account</label>
                            <select name="unit_manager_id" class="form-select">
                                <option value="">Unassigned</option>
                                <?php foreach ($managers as $m): ?>
                                <option value="<?= $m['id'] ?>" <?= (int) ($team['unit_manager_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($m['first_name'] . ' ' . $m['last_name'] . ' (' . $m['username'] . ')') ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="alert alert-secondary mb-0 py-2">
                                Coaches are assigned <strong>per event</strong>.
                                <a href="<?= BASE_URL ?>/intramurals/teams/coaches.php?id=<?= $id ?>">Manage event coaches</a>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-6">
                            <label class="form-label">Team Logo</label>
                            <input type="file" name="logo" class="form-control" accept="image/*">
                            <?php if ($team['logo']): ?>
                            <div class="mt-2"><img src="<?= UPLOAD_URL_TEAMS . sanitize($team['logo']) ?>" alt="" style="height:48px"></div>
                            <?php endif; ?>
                        </div>
                        <?php if ($isFullAdmin): ?>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="is_active" class="form-select">
                                <option value="1" <?= $team['is_active'] ? 'selected' : '' ?>>Active</option>
                                <option value="0" <?= !$team['is_active'] ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3"><?= sanitize($team['description'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="<?= BASE_URL ?>/intramurals/teams/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
                        <?php if ($isFullAdmin): ?>
                        <button type="submit" name="action" value="deactivate" class="btn btn-outline-danger ms-auto" data-confirm="Deactivate this team?">Deactivate</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
