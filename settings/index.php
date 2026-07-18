<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $settings = [
        'max_borrow_days', 'default_borrow_days', 'max_borrow_items',
        'low_stock_threshold', 'require_approval', 'notification_email',
        'overdue_reminder_days', 'campus_name', 'borrowing_policy'
    ];

    foreach ($settings as $key) {
        if (isset($_POST[$key])) {
            updateSetting($key, post($key), $_SESSION['user_id']);
        }
    }

    auditLog($_SESSION['user_id'], 'update_settings', 'system_settings');
    flash('success', 'Settings updated successfully.');
    redirect(BASE_URL . '/settings/index.php');
}

$allSettings = $db->query('SELECT * FROM system_settings ORDER BY setting_key')->fetchAll();
$settingsMap = [];
foreach ($allSettings as $s) {
    $settingsMap[$s['setting_key']] = $s['setting_value'];
}

$pageTitle = 'System Settings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header"><h1><i class="bi bi-gear"></i> System Settings</h1></div>

<div class="row"><div class="col-lg-8">
<div class="card"><div class="card-body">
<form method="POST">
<?= csrfField() ?>
<h5 class="mb-3">Borrowing Policies</h5>
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <label class="form-label">Max Borrow Days</label>
        <input type="number" name="max_borrow_days" class="form-control" value="<?= sanitize($settingsMap['max_borrow_days'] ?? '14') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Default Borrow Days</label>
        <input type="number" name="default_borrow_days" class="form-control" value="<?= sanitize($settingsMap['default_borrow_days'] ?? '7') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Max Items Per User</label>
        <input type="number" name="max_borrow_items" class="form-control" value="<?= sanitize($settingsMap['max_borrow_items'] ?? '5') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Low Stock Threshold</label>
        <input type="number" name="low_stock_threshold" class="form-control" value="<?= sanitize($settingsMap['low_stock_threshold'] ?? '3') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Overdue Reminder (days before)</label>
        <input type="number" name="overdue_reminder_days" class="form-control" value="<?= sanitize($settingsMap['overdue_reminder_days'] ?? '1') ?>">
    </div>
    <div class="col-md-4">
        <div class="form-check mt-4"><input type="checkbox" name="require_approval" class="form-check-input" value="1" <?= ($settingsMap['require_approval'] ?? '1') ? 'checked' : '' ?>><label class="form-check-label">Require Approval</label></div>
    </div>
</div>

<h5 class="mb-3">General Settings</h5>
<div class="row g-3 mb-4">
    <div class="col-12">
        <label class="form-label">Campus Name</label>
        <input type="text" name="campus_name" class="form-control" value="<?= sanitize($settingsMap['campus_name'] ?? APP_CAMPUS) ?>">
    </div>
    <div class="col-12">
        <label class="form-label">Borrowing Policy Text</label>
        <textarea name="borrowing_policy" class="form-control" rows="4"><?= sanitize($settingsMap['borrowing_policy'] ?? '') ?></textarea>
    </div>
</div>

<button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Settings</button>
</form>
</div></div></div></div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
