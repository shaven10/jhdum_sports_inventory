<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$id = (int) get('id');
$db = getDB();
$stmt = $db->prepare('SELECT * FROM notifications WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $_SESSION['user_id']]);
$notif = $stmt->fetch();

if ($notif) {
    markNotificationRead($id, $_SESSION['user_id']);
    if ($notif['link']) {
        redirect($notif['link']);
    }
}

redirect(BASE_URL . '/notifications/index.php');
