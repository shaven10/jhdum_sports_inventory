<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $settings = [
        'max_borrow_days', 'default_borrow_days', 'max_borrow_items',
        'low_stock_threshold', 'require_approval', 'notification_email',
        'overdue_reminder_days', 'campus_name', 'borrowing_policy',
        'cert_place', 'cert_city',
        'cert_sports_incharge', 'cert_sports_director', 'cert_campus_director',
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

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div><h1><i class="bi bi-gear"></i> System Settings</h1></div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-outline-secondary"><i class="bi bi-shield-lock"></i> Admin Panel</a>
        <a href="<?= BASE_URL ?>/settings/database.php" class="btn btn-outline-primary"><i class="bi bi-database-gear"></i> Database Tools</a>
        <a href="<?= BASE_URL ?>/settings/theme.php" class="btn btn-outline-secondary"><i class="bi bi-palette"></i> Theme Manager</a>
    </div>
</div>

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

<h5 class="mb-3">Certificate Information</h5>
<p class="text-muted small">Saved once and reused on Certificate of Recognition prints.</p>
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <label class="form-label">Place / Venue</label>
        <input type="text" name="cert_place" class="form-control" value="<?= sanitize($settingsMap['cert_place'] ?? '') ?>">
    </div>
    <div class="col-md-6">
        <label class="form-label">City</label>
        <input type="text" name="cert_city" class="form-control" value="<?= sanitize($settingsMap['cert_city'] ?? '') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Sports Incharge</label>
        <input type="text" name="cert_sports_incharge" class="form-control" value="<?= sanitize($settingsMap['cert_sports_incharge'] ?? '') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Sports Director</label>
        <input type="text" name="cert_sports_director" class="form-control" value="<?= sanitize($settingsMap['cert_sports_director'] ?? '') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Campus Director</label>
        <input type="text" name="cert_campus_director" class="form-control" value="<?= sanitize($settingsMap['cert_campus_director'] ?? '') ?>">
    </div>
</div>

<button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Settings</button>
</form>
</div></div></div></div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
