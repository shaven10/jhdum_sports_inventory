<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$db = getDB();
$errors = [];
$sdoTitle = getSetting('sdo_about_title', 'About the Sports Development Office');
$sdoContent = getSetting('sdo_about_content', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Your session expired or the form was invalid. Please try saving again.');
        redirect(BASE_URL . '/admin/landing.php');
    }

    $sdoTitle = post('sdo_about_title');
    $sdoContent = post('sdo_about_content');

    if ($sdoTitle === '') {
        $errors[] = 'Section title is required.';
    }

    if ($errors === []) {
        $savedTitle = updateSetting('sdo_about_title', $sdoTitle, (int) $_SESSION['user_id']);
        $savedContent = updateSetting('sdo_about_content', $sdoContent, (int) $_SESSION['user_id']);

        if ($savedTitle && $savedContent) {
            auditLog((int) $_SESSION['user_id'], 'update_landing_content', 'system_settings');
            flash('success', 'Landing page content updated.');
            redirect(BASE_URL . '/admin/landing.php');
        }

        $errors[] = 'Could not save landing page content. Please try again.';
    }
} else {
    ensureSdoAboutSettings();
    $sdoTitle = getSetting('sdo_about_title', 'About the Sports Development Office');
    $sdoContent = getSetting('sdo_about_content', '');
}

$pageTitle = 'Landing Page Content';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-house-door"></i> Landing Page Content</h1>
        <p class="text-muted mb-0">Edit the public landing page — Sports Development Office info and activity gallery.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/admin/gallery/index.php" class="btn btn-outline-primary"><i class="bi bi-images"></i> Photo Gallery</a>
        <a href="<?= BASE_URL ?>/landing.php" class="btn btn-outline-secondary" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Preview landing</a>
        <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-outline-secondary">Back to Admin</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-building"></i> Sports Development Office</div>
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
                </div>
                <?php endif; ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label" for="sdo_about_title">Section title</label>
                        <input type="text" name="sdo_about_title" id="sdo_about_title" class="form-control" required maxlength="200" value="<?= formValue($sdoTitle) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="sdo_about_content">About text</label>
                        <textarea name="sdo_about_content" id="sdo_about_content" class="form-control" rows="8" placeholder="Describe the Sports Development Office, its mission, and programs."><?= formValue($sdoContent) ?></textarea>
                        <div class="form-text">Line breaks are preserved on the landing page.</div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save content</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-images"></i> Activity Gallery</div>
            <div class="card-body">
                <p class="mb-3">Upload photos from intramurals seasons. The landing page shows images from the most recent completed season, or the current season if no prior gallery exists.</p>
                <a href="<?= BASE_URL ?>/admin/gallery/index.php" class="btn btn-primary w-100">
                    <i class="bi bi-cloud-upload"></i> Manage photo gallery
                </a>
            </div>
        </div>
        <div class="alert alert-info mt-3 mb-0">
            <strong>Tip:</strong> Tag photos to the correct season year so visitors see the right activity highlights on the landing page.
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
