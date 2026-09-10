<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole(['admin']);
ensureSeasonGalleryTable();

$db = getDB();
$errors = [];
$seasons = getAllSeasons(true);
$activeSeason = getActiveSeason();
$viewSeasonId = (int) get('season_id');
if ($viewSeasonId <= 0 && $activeSeason) {
    $viewSeasonId = (int) $activeSeason['id'];
}
$viewSeason = $viewSeasonId ? getSeasonById($viewSeasonId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $action = post('action');
    $seasonId = (int) post('season_id');

    if (!$seasonId || !getSeasonById($seasonId)) {
        flash('error', 'Select a valid season.');
        redirect(BASE_URL . '/admin/gallery/index.php');
    }

    if ($action === 'upload') {
        $uploaded = 0;
        $failed = 0;
        $captions = $_POST['captions'] ?? [];
        if (!is_array($captions)) {
            $captions = [];
        }

        if (!empty($_FILES['photos']['name']) && is_array($_FILES['photos']['name'])) {
            $count = count($_FILES['photos']['name']);
            $existing = getSeasonGalleryPhotos($seasonId);
            $nextSort = 0;
            foreach ($existing as $row) {
                $nextSort = max($nextSort, (int) ($row['sort_order'] ?? 0) + 1);
            }

            for ($i = 0; $i < $count; $i++) {
                if (empty($_FILES['photos']['name'][$i])) {
                    continue;
                }

                $file = [
                    'name' => $_FILES['photos']['name'][$i],
                    'type' => $_FILES['photos']['type'][$i],
                    'tmp_name' => $_FILES['photos']['tmp_name'][$i],
                    'error' => $_FILES['photos']['error'][$i],
                    'size' => $_FILES['photos']['size'][$i],
                ];

                $filename = uploadGalleryPhoto($file);
                if (!$filename) {
                    $failed++;
                    continue;
                }

                $caption = trim((string) ($captions[$i] ?? ''));
                $photoId = addSeasonGalleryPhoto($seasonId, $filename, $caption, (int) $_SESSION['user_id'], $nextSort);
                if ($photoId) {
                    auditLog((int) $_SESSION['user_id'], 'upload_gallery_photo', 'intramural_season_gallery', $photoId, null, [
                        'season_id' => $seasonId,
                        'filename' => $filename,
                    ]);
                    $uploaded++;
                    $nextSort++;
                } else {
                    deleteUploadedFile($filename, UPLOAD_PATH_GALLERY);
                    $failed++;
                }
            }
        }

        if ($uploaded > 0) {
            $msg = $uploaded . ' photo' . ($uploaded === 1 ? '' : 's') . ' uploaded.';
            if ($failed > 0) {
                $msg .= ' ' . $failed . ' file(s) could not be uploaded (invalid type or too large).';
            }
            flash('success', $msg);
        } elseif ($failed > 0) {
            flash('error', 'No photos were uploaded. Use JPEG, PNG, GIF, or WebP under 5 MB each.');
        } else {
            flash('error', 'Choose at least one photo to upload.');
        }

        redirect(BASE_URL . '/admin/gallery/index.php?season_id=' . $seasonId);
    }

    if ($action === 'update') {
        $photoId = (int) post('photo_id');
        $photo = getSeasonGalleryPhotoById($photoId);
        if (!$photo || (int) $photo['season_id'] !== $seasonId) {
            flash('error', 'Photo not found.');
        } else {
            updateSeasonGalleryPhoto($photoId, trim(post('caption')), (int) post('sort_order', '0'));
            auditLog((int) $_SESSION['user_id'], 'update_gallery_photo', 'intramural_season_gallery', $photoId);
            flash('success', 'Photo updated.');
        }
        redirect(BASE_URL . '/admin/gallery/index.php?season_id=' . $seasonId);
    }

    if ($action === 'delete') {
        $photoId = (int) post('photo_id');
        $photo = getSeasonGalleryPhotoById($photoId);
        if (!$photo || (int) $photo['season_id'] !== $seasonId) {
            flash('error', 'Photo not found.');
        } elseif (deleteSeasonGalleryPhoto($photoId)) {
            auditLog((int) $_SESSION['user_id'], 'delete_gallery_photo', 'intramural_season_gallery', $photoId);
            flash('success', 'Photo deleted.');
        } else {
            flash('error', 'Could not delete photo.');
        }
        redirect(BASE_URL . '/admin/gallery/index.php?season_id=' . $seasonId);
    }
}

$photos = $viewSeason ? getSeasonGalleryPhotos((int) $viewSeason['id']) : [];

$pageTitle = 'Activity Photo Gallery';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-images"></i> Activity Photo Gallery</h1>
        <p class="text-muted mb-0">Upload intramurals activity photos by season for the public landing page.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/admin/landing.php" class="btn btn-outline-secondary"><i class="bi bi-house-door"></i> Landing content</a>
        <a href="<?= BASE_URL ?>/landing.php" class="btn btn-outline-secondary" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Preview</a>
        <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-outline-secondary">Back to Admin</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Season</div>
            <div class="card-body">
                <form method="GET" class="mb-0">
                    <label class="form-label" for="season_id">Manage photos for</label>
                    <select name="season_id" id="season_id" class="form-select mb-3" onchange="this.form.submit()">
                        <?php foreach ($seasons as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $viewSeasonId ? 'selected' : '' ?>>
                            <?= sanitize($s['year_label']) ?> — <?= sanitize($s['name']) ?>
                            <?php if (!empty($s['is_active'])): ?>(Active)<?php elseif (!empty($s['is_archived'])): ?>(Archived)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php if ($viewSeason): ?>
                <p class="small text-muted mb-0"><?= count($photos) ?> photo<?= count($photos) === 1 ? '' : 's' ?> in this season's gallery.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($viewSeason): ?>
        <div class="card mt-4">
            <div class="card-header"><i class="bi bi-cloud-upload"></i> Upload Photos</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="season_id" value="<?= (int) $viewSeason['id'] ?>">
                    <div class="mb-3">
                        <label class="form-label" for="photos">Select images</label>
                        <input type="file" name="photos[]" id="photos" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp" multiple required>
                        <div class="form-text">JPEG, PNG, GIF, or WebP — max 5 MB each. You can select multiple files.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="shared_caption">Caption for all (optional)</label>
                        <input type="text" name="captions[0]" id="shared_caption" class="form-control" maxlength="255" placeholder="e.g. Opening ceremony">
                        <div class="form-text">Applied to the first photo; edit individual captions after upload.</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-upload"></i> Upload</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <?php if (!$viewSeason): ?>
        <div class="alert alert-warning mb-0">Create a season first before uploading gallery photos.</div>
        <?php elseif (empty($photos)): ?>
        <div class="card">
            <div class="card-body text-center text-muted py-5">
                <i class="bi bi-images display-4 d-block mb-3 opacity-50"></i>
                <p class="mb-0">No photos yet for <?= sanitize(seasonLabel($viewSeason)) ?>. Upload images using the form on the left.</p>
            </div>
        </div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($photos as $photo): ?>
            <div class="col-md-6">
                <div class="card gallery-admin-card h-100">
                    <div class="gallery-admin-thumb">
                        <img src="<?= sanitize(galleryPhotoUrl($photo['filename'])) ?>" alt="<?= sanitize($photo['caption'] ?? 'Activity photo') ?>">
                    </div>
                    <div class="card-body">
                        <form method="POST" class="gallery-admin-form">
                            <?= csrfField() ?>
                            <input type="hidden" name="season_id" value="<?= (int) $viewSeason['id'] ?>">
                            <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                            <div class="mb-2">
                                <label class="form-label small">Caption</label>
                                <input type="text" name="caption" class="form-control form-control-sm" maxlength="255" value="<?= sanitize($photo['caption'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Sort order</label>
                                <input type="number" name="sort_order" class="form-control form-control-sm" value="<?= (int) ($photo['sort_order'] ?? 0) ?>" min="0">
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" name="action" value="update" class="btn btn-sm btn-outline-primary">Save</button>
                                <button type="submit" name="action" value="delete" class="btn btn-sm btn-outline-danger" data-confirm="Delete this photo permanently?">Delete</button>
                            </div>
                        </form>
                        <p class="small text-muted mb-0 mt-2">Added <?= sanitize(formatDateTime($photo['created_at'])) ?></p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
