<?php
require_once __DIR__ . '/../includes/auth.php';
requireAnnouncementsAccess();
ensureAnnouncementsTable();

$id = (int) get('id');
$announcement = getAnnouncementById($id, !canManageAnnouncements());

if (!$announcement || !canViewAnnouncementRecord($announcement)) {
    flash('error', 'Announcement not found.');
    redirect(BASE_URL . '/announcements/index.php');
}

$pageTitle = 'Announcement';
require_once __DIR__ . '/../includes/header.php';

$typeClass = match ($announcement['type'] ?? 'info') {
    'success' => 'success',
    'warning' => 'warning',
    'danger' => 'danger',
    default => 'info',
};
$author = trim(($announcement['first_name'] ?? '') . ' ' . ($announcement['last_name'] ?? ''));
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-megaphone"></i> Announcement</h1>
        <p class="text-muted mb-0">
            <?= formatDateTime($announcement['created_at']) ?>
            <?php if ($author !== ''): ?> · Posted by <?= sanitize($author) ?><?php endif; ?>
            · To: <?= sanitize(formatAnnouncementRoles($announcement)) ?>
            <?php if (empty($announcement['is_active'])): ?> · <span class="badge bg-secondary">Inactive</span><?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (canManageAnnouncements()): ?>
        <a href="<?= BASE_URL ?>/admin/announcements/index.php" class="btn btn-outline-primary">Manage</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/announcements/index.php" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

<div class="card border-<?= $typeClass ?>">
    <div class="card-header bg-<?= $typeClass ?> bg-opacity-10 d-flex align-items-center gap-2">
        <?= statusBadge($announcement['type']) ?>
        <strong><?= sanitize($announcement['title']) ?></strong>
    </div>
    <div class="card-body">
        <div class="fs-5" style="white-space: pre-wrap;"><?= sanitize($announcement['message']) ?></div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
