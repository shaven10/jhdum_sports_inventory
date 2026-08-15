<?php
require_once __DIR__ . '/../includes/auth.php';
requireAnnouncementsAccess();
ensureAnnouncementsTable();

$announcements = getActiveAnnouncements(50);

$pageTitle = 'Announcements';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-megaphone"></i> Announcements</h1>
        <p class="text-muted mb-0">Official notices for staff across inventory and intramurals modules.</p>
    </div>
    <?php if (canManageAnnouncements()): ?>
    <a href="<?= BASE_URL ?>/admin/announcements/index.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Manage / Send</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="list-group list-group-flush">
        <?php if (empty($announcements)): ?>
        <div class="list-group-item text-center text-muted py-5">No active announcements.</div>
        <?php else: ?>
        <?php foreach ($announcements as $a): ?>
        <a href="<?= BASE_URL ?>/announcements/view.php?id=<?= (int) $a['id'] ?>" class="list-group-item list-group-item-action">
            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <?= statusBadge($a['type']) ?>
                        <h6 class="mb-0"><?= sanitize($a['title']) ?></h6>
                    </div>
                    <p class="mb-1 text-muted"><?= sanitize(mb_strimwidth($a['message'], 0, 160, '…')) ?></p>
                    <div class="small text-muted">To: <?= sanitize(formatAnnouncementRoles($a)) ?></div>
                </div>
                <small class="text-muted text-nowrap"><?= formatDateTime($a['created_at']) ?></small>
            </div>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
