<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$db = getDB();
$userId = $_SESSION['user_id'];

if (isset($_GET['mark_all'])) {
    $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$userId]);
    flash('success', 'All notifications marked as read.');
    redirect(BASE_URL . '/notifications/index.php');
}

$notifications = $db->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
$notifications->execute([$userId]);
$notifs = $notifications->fetchAll();

$pageTitle = 'Notifications';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <h1><i class="bi bi-bell"></i> Notifications</h1>
    <a href="?mark_all=1" class="btn btn-outline-primary btn-sm">Mark All as Read</a>
</div>

<div class="card">
    <div class="list-group list-group-flush">
        <?php if (empty($notifs)): ?>
        <div class="list-group-item text-center text-muted py-5">No notifications</div>
        <?php else: ?>
        <?php foreach ($notifs as $n): ?>
        <a href="<?= BASE_URL ?>/notifications/read.php?id=<?= $n['id'] ?>" class="list-group-item list-group-item-action <?= $n['is_read'] ? '' : 'list-group-item-primary' ?>">
            <div class="d-flex justify-content-between">
                <h6 class="mb-1"><?= sanitize($n['title']) ?></h6>
                <small><?= formatDateTime($n['created_at']) ?></small>
            </div>
            <p class="mb-1 text-muted"><?= sanitize($n['message']) ?></p>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
