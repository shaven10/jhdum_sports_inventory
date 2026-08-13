<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
requireRole(['admin', 'coordinator', 'staff']);

$db = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/intramurals/teams/add.php');
    }

    $name = post('name');
    $shortName = post('short_name');
    $color = post('color', '#1a5276');
    $department = post('department');
    $coachName = post('coach_name');
    $description = post('description');

    if ($name === '') {
        $errors[] = 'Team name is required.';
    }

    $logo = null;
    if (!empty($_FILES['logo']['name'])) {
        $logo = uploadTeamLogo($_FILES['logo']);
        if (!$logo) {
            $errors[] = 'Invalid logo image.';
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $db->prepare('INSERT INTO intramural_teams (name, short_name, color, logo, department, coach_name, description) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $shortName, $color, $logo, $department, $coachName, $description]);
            $id = (int) $db->lastInsertId();
            auditLog($_SESSION['user_id'], 'create', 'intramural_team', $id, null, ['name' => $name]);
            flash('success', 'Team registered successfully.');
            redirect(BASE_URL . '/intramurals/teams/view.php?id=' . $id);
        } catch (PDOException $e) {
            $errors[] = 'A team with this name already exists.';
        }
    }
}

$pageTitle = 'Add Team';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header"><h1><i class="bi bi-plus-circle"></i> Register Team</h1></div>

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
                            <input type="text" name="name" class="form-control" required value="<?= sanitize(post('name')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Short Name</label>
                            <input type="text" name="short_name" class="form-control" value="<?= sanitize(post('short_name')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Team Color</label>
                            <input type="color" name="color" class="form-control form-control-color w-100" value="<?= sanitize(post('color', '#1a5276')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department / College</label>
                            <input type="text" name="department" class="form-control" value="<?= sanitize(post('department')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Coach</label>
                            <input type="text" name="coach_name" class="form-control" value="<?= sanitize(post('coach_name')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Team Logo</label>
                            <input type="file" name="logo" class="form-control" accept="image/*">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3"><?= sanitize(post('description')) ?></textarea>
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Save Team</button>
                        <a href="<?= BASE_URL ?>/intramurals/teams/index.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
