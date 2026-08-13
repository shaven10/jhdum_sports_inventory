<?php
/**
 * Helper Functions
 */

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function sanitize(string $input): string
{
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function post(string $key, $default = ''): string
{
    return isset($_POST[$key]) ? trim($_POST[$key]) : $default;
}

function get(string $key, $default = ''): string
{
    return isset($_GET[$key]) ? trim($_GET[$key]) : $default;
}

function generateRequestNumber(): string
{
    return 'BR-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

function generateBarcode(): string
{
    return 'EQ-' . strtoupper(substr(md5(uniqid()), 0, 8));
}

function auditLog(?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, ?array $oldValues = null, ?array $newValues = null): void
{
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $userId,
        $action,
        $entityType,
        $entityId,
        $oldValues ? json_encode($oldValues) : null,
        $newValues ? json_encode($newValues) : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
}

function createNotification(int $userId, string $title, string $message, string $type = 'info', ?string $link = null): void
{
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $title, $message, $type, $link]);
}

function getUnreadNotificationCount(int $userId): int
{
    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function getNotifications(int $userId, int $limit = 10): array
{
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?');
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

function markNotificationRead(int $notificationId, int $userId): void
{
    $db = getDB();
    $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
    $stmt->execute([$notificationId, $userId]);
}

function getSetting(string $key, $default = null)
{
    $db = getDB();
    $stmt = $db->prepare('SELECT setting_value, setting_type FROM system_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    if (!$row) {
        return $default;
    }

    return match ($row['setting_type']) {
        'integer' => (int) $row['setting_value'],
        'boolean' => (bool) $row['setting_value'],
        'json'    => json_decode($row['setting_value'], true),
        default   => $row['setting_value'],
    };
}

function updateSetting(string $key, $value, int $userId): void
{
    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM system_settings WHERE setting_key = ?');
    $stmt->execute([$key]);

    if ($stmt->fetch()) {
        $db->prepare('UPDATE system_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?')
           ->execute([(string) $value, $userId, $key]);
    } else {
        $db->prepare('INSERT INTO system_settings (setting_key, setting_value, setting_type, updated_by) VALUES (?, ?, ?, ?)')
           ->execute([$key, (string) $value, 'string', $userId]);
    }
}

function formatDate(?string $date, string $format = 'M d, Y'): string
{
    if (!$date) {
        return '-';
    }
    return date($format, strtotime($date));
}

function formatDateTime(?string $datetime, string $format = 'M d, Y h:i A'): string
{
    if (!$datetime) {
        return '-';
    }
    return date($format, strtotime($datetime));
}

function statusBadge(string $status): string
{
    $classes = [
        'pending'     => 'warning',
        'approved'    => 'info',
        'rejected'    => 'danger',
        'cancelled'   => 'secondary',
        'checked_out' => 'primary',
        'returned'    => 'success',
        'overdue'     => 'danger',
        'excellent'   => 'success',
        'good'        => 'info',
        'fair'        => 'warning',
        'poor'        => 'danger',
        'damaged'     => 'danger',
        'scheduled'   => 'info',
        'in_progress' => 'warning',
        'ongoing'     => 'warning',
        'completed'   => 'success',
        'forfeit'     => 'danger',
        'reported'    => 'warning',
        'resolved'    => 'success',
    ];

    $class = $classes[$status] ?? 'secondary';
    return '<span class="badge bg-' . $class . '">' . ucfirst(str_replace('_', ' ', $status)) . '</span>';
}

function roleLabel(string $role): string
{
    $labels = getAllRoles();
    return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

function roleBadge(string $role): string
{
    $classes = [
        'admin'         => 'danger',
        'coordinator'   => 'primary',
        'staff'         => 'info',
        'unit_manager'  => 'warning',
        'coach'         => 'dark',
        'tabulator'     => 'secondary',
        'secretariat'   => 'primary',
        'student'       => 'success',
    ];
    $class = $classes[$role] ?? 'secondary';
    return '<span class="badge bg-' . $class . '">' . sanitize(roleLabel($role)) . '</span>';
}

function purposeLabel(string $purpose): string
{
    $labels = [
        'pe_class'   => 'PE Class',
        'training'   => 'Training',
        'tournament' => 'Tournament',
        'practice'   => 'Practice',
        'event'      => 'Event',
        'other'      => 'Other',
    ];
    return $labels[$purpose] ?? ucfirst($purpose);
}

function uploadImage(array $file, string $prefix = 'eq'): ?string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowed, true)) {
        return null;
    }

    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return null;
    }

    if (!is_dir(UPLOAD_PATH)) {
        mkdir(UPLOAD_PATH, 0755, true);
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = $prefix . '_' . time() . '_' . uniqid() . '.' . $ext;

    if (move_uploaded_file($file['tmp_name'], UPLOAD_PATH . $filename)) {
        return $filename;
    }

    return null;
}

function deleteImage(?string $filename): void
{
    if ($filename && file_exists(UPLOAD_PATH . $filename)) {
        unlink(UPLOAD_PATH . $filename);
    }
}

function getDashboardStats(): array
{
    $db = getDB();

    $stats = [];

    $stats['total_equipment'] = (int) $db->query('SELECT COUNT(*) FROM equipment WHERE is_active = 1')->fetchColumn();
    $stats['total_items'] = (int) $db->query('SELECT COALESCE(SUM(quantity_total), 0) FROM equipment WHERE is_active = 1')->fetchColumn();
    $stats['available_items'] = (int) $db->query('SELECT COALESCE(SUM(quantity_available), 0) FROM equipment WHERE is_active = 1')->fetchColumn();
    $stats['borrowed_items'] = (int) $db->query('SELECT COALESCE(SUM(quantity_borrowed), 0) FROM equipment WHERE is_active = 1')->fetchColumn();
    $stats['reserved_items'] = (int) $db->query('SELECT COALESCE(SUM(quantity_reserved), 0) FROM equipment WHERE is_active = 1')->fetchColumn();
    $stats['damaged_items'] = (int) $db->query('SELECT COALESCE(SUM(quantity_damaged), 0) FROM equipment WHERE is_active = 1')->fetchColumn();
    $stats['maintenance_items'] = (int) $db->query('SELECT COALESCE(SUM(quantity_maintenance), 0) FROM equipment WHERE is_active = 1')->fetchColumn();

    $stats['pending_requests'] = (int) $db->query("SELECT COUNT(*) FROM borrowing_requests WHERE status = 'pending'")->fetchColumn();
    $stats['approved_requests'] = (int) $db->query("SELECT COUNT(*) FROM borrowing_requests WHERE status = 'approved'")->fetchColumn();
    $stats['overdue_borrowings'] = (int) $db->query("SELECT COUNT(*) FROM borrowing_requests WHERE status IN ('checked_out', 'overdue') AND return_date < CURDATE()")->fetchColumn();
    $stats['total_users'] = (int) $db->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();

    $threshold = getSetting('low_stock_threshold', LOW_STOCK_THRESHOLD);
    $stmt = $db->prepare('SELECT COUNT(*) FROM equipment WHERE is_active = 1 AND quantity_available <= low_stock_threshold');
    $stmt->execute();
    $stats['low_stock'] = (int) $stmt->fetchColumn();

    return $stats;
}

function updateEquipmentQuantities(int $equipmentId): void
{
    $db = getDB();
    $stmt = $db->prepare('SELECT quantity_total, quantity_borrowed, quantity_reserved, quantity_damaged, quantity_maintenance FROM equipment WHERE id = ?');
    $stmt->execute([$equipmentId]);
    $eq = $stmt->fetch();

    if ($eq) {
        $available = $eq['quantity_total'] - $eq['quantity_borrowed'] - $eq['quantity_reserved'] - $eq['quantity_damaged'] - $eq['quantity_maintenance'];
        $available = max(0, $available);

        $update = $db->prepare('UPDATE equipment SET quantity_available = ? WHERE id = ?');
        $update->execute([$available, $equipmentId]);
    }
}

function checkOverdueRequests(): void
{
    $db = getDB();
    $stmt = $db->query("SELECT br.*, u.id as user_id FROM borrowing_requests br JOIN users u ON br.user_id = u.id WHERE br.status = 'checked_out' AND br.return_date < CURDATE()");
    $overdue = $stmt->fetchAll();

    foreach ($overdue as $req) {
        $db->prepare("UPDATE borrowing_requests SET status = 'overdue' WHERE id = ?")->execute([$req['id']]);
        createNotification(
            $req['user_id'],
            'Overdue Equipment',
            'Your borrowing request ' . $req['request_number'] . ' is overdue. Please return the equipment immediately.',
            'danger',
            BASE_URL . '/requests/view.php?id=' . $req['id']
        );
    }
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function paginate(int $total, int $perPage, int $currentPage): array
{
    $totalPages = max(1, (int) ceil($total / $perPage));
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $perPage;

    return [
        'total'        => $total,
        'per_page'     => $perPage,
        'current_page' => $currentPage,
        'total_pages'  => $totalPages,
        'offset'       => $offset,
    ];
}

function paginationLinks(array $pagination, string $baseUrl): string
{
    if ($pagination['total_pages'] <= 1) {
        return '';
    }

    $html = '<nav><ul class="pagination justify-content-center">';

    for ($i = 1; $i <= $pagination['total_pages']; $i++) {
        $active = $i === $pagination['current_page'] ? ' active' : '';
        $separator = strpos($baseUrl, '?') !== false ? '&' : '?';
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . $baseUrl . $separator . 'page=' . $i . '">' . $i . '</a></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}

function ensurePasswordPlainColumn(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute(['users', 'password_plain']);
    if ((int) $stmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE users ADD COLUMN password_plain VARCHAR(255) DEFAULT NULL AFTER password');
    }
}

/** Absolute URL to the official JHCSC logo. */
function appLogoUrl(): string
{
    return defined('APP_LOGO') ? APP_LOGO : (BASE_URL . '/assets/img/jhcsc-logo.png');
}

/**
 * Official branded header for printable reports / PDF.
 *
 * @param array{subtitle?:string,meta?:string,show_on_screen?:bool} $options
 */
function renderReportHeader(string $title, array $options = []): string
{
    $subtitle = $options['subtitle'] ?? (defined('APP_TAGLINE') ? APP_TAGLINE : '');
    $meta = $options['meta'] ?? '';
    $showOnScreen = !empty($options['show_on_screen']);
    $visibility = $showOnScreen ? '' : ' d-none d-print-block';
    $logo = sanitize(appLogoUrl());
    $appName = sanitize(defined('APP_NAME') ? APP_NAME : 'JHCSC Sports Development MIS');
    $campus = sanitize(defined('APP_CAMPUS') ? APP_CAMPUS : 'J.H. Cerilles State College');
    $titleSafe = sanitize($title);
    $subtitleSafe = sanitize((string) $subtitle);
    $metaSafe = sanitize((string) $meta);
    $generated = sanitize(date('F j, Y g:i A'));

    $metaHtml = $metaSafe !== ''
        ? '<p class="report-header-meta mb-0">' . $metaSafe . '</p>'
        : '';

    return <<<HTML
<div class="report-brand-header{$visibility}">
    <div class="report-brand-inner">
        <img src="{$logo}" alt="JHCSC Logo" class="report-brand-logo">
        <div class="report-brand-text">
            <div class="report-brand-campus">{$campus}</div>
            <div class="report-brand-app">{$appName}</div>
            <h2 class="report-brand-title">{$titleSafe}</h2>
            <p class="report-brand-subtitle mb-0">{$subtitleSafe}</p>
            {$metaHtml}
            <p class="report-brand-generated mb-0">Generated: {$generated}</p>
        </div>
    </div>
    <hr class="report-brand-rule">
</div>
HTML;
}

/** Official branded footer for printable reports / PDF. */
function renderReportFooter(?string $extra = null): string
{
    $appName = sanitize(defined('APP_NAME') ? APP_NAME : 'JHCSC Sports Development MIS');
    $campus = sanitize(defined('APP_CAMPUS') ? APP_CAMPUS : 'J.H. Cerilles State College');
    $extraSafe = $extra !== null && $extra !== '' ? ' · ' . sanitize($extra) : '';
    $when = sanitize(date('F j, Y g:i A'));

    return <<<HTML
<div class="report-brand-footer d-none d-print-block">
    <hr class="report-brand-rule">
    <p class="mb-0 text-center small">{$campus} · {$appName}{$extraSafe} · Printed {$when}</p>
</div>
HTML;
}

/** Banner shown to non-student accounts when the active season roster is locked. */
function renderRosterLockNotice(?int $seasonId = null): string
{
    if (!function_exists('shouldShowRosterLockStatus') || !shouldShowRosterLockStatus()) {
        return '';
    }

    $status = function_exists('getRosterLockStatus') ? getRosterLockStatus($seasonId) : null;
    if (!$status || !$status['is_locked']) {
        return '';
    }

    $year = sanitize($status['year_label'] ?? '');
    $by = $status['locked_by_name'] ? sanitize($status['locked_by_name']) : 'Administrator';
    $lockDate = !empty($status['lock_date']) ? sanitize(formatDate($status['lock_date'])) : null;
    $when = !empty($status['locked_at']) ? sanitize(formatDateTime($status['locked_at'])) : '—';
    $dateLine = $lockDate
        ? "Effective lock date: <strong>{$lockDate}</strong>. Locked by {$by} on {$when}."
        : "Locked by {$by} on {$when}.";

    return <<<HTML
<div class="alert alert-warning roster-lock-notice mb-3">
    <div class="d-flex flex-wrap align-items-start gap-2">
        <div class="flex-grow-1">
            <i class="bi bi-lock-fill"></i>
            <strong>Roster locked</strong> for intramurals {$year}.
            {$dateLine}
            Roster changes (imports, registrations, and sport assignments) are disabled until an administrator unlocks the roster.
        </div>
    </div>
</div>
HTML;
}

/** Banner when a future roster lock date is scheduled. */
function renderRosterLockScheduleNotice(?int $seasonId = null): string
{
    if (!function_exists('shouldShowRosterLockStatus') || !shouldShowRosterLockStatus()) {
        return '';
    }

    $status = function_exists('getRosterLockStatus') ? getRosterLockStatus($seasonId) : null;
    if (!$status || !$status['is_scheduled'] || empty($status['lock_date'])) {
        return '';
    }

    $year = sanitize($status['year_label'] ?? '');
    $lockDate = sanitize(formatDate($status['lock_date']));
    $by = $status['locked_by_name'] ? sanitize($status['locked_by_name']) : 'Administrator';

    return <<<HTML
<div class="alert alert-info roster-lock-schedule-notice mb-3">
    <div class="d-flex flex-wrap align-items-start gap-2">
        <div class="flex-grow-1">
            <i class="bi bi-calendar-event"></i>
            <strong>Roster lock scheduled</strong> for intramurals {$year}.
            Rosters will lock on <strong>{$lockDate}</strong> (set by {$by}).
            Make roster changes before that date.
        </div>
    </div>
</div>
HTML;
}

/** Locked and scheduled roster alerts for staff dashboards and intramurals pages. */
function renderRosterLockAlerts(?int $seasonId = null): string
{
    return renderRosterLockNotice($seasonId) . renderRosterLockScheduleNotice($seasonId);
}

/**
 * Branded dashboard header with official logo.
 *
 * @param array{icon?:string, actions?:string} $options
 */
function renderDashboardHero(string $title, string $subtitle = '', array $options = []): string
{
    $icon = $options['icon'] ?? 'bi-speedometer2';
    $actions = $options['actions'] ?? '';
    $logo = sanitize(appLogoUrl());
    $campus = sanitize(defined('APP_CAMPUS') ? APP_CAMPUS : 'J.H. Cerilles State College');
    $appName = sanitize(defined('APP_SHORT_NAME') ? APP_SHORT_NAME : 'JHCSC SDMIS');
    $titleSafe = sanitize($title);
    $subtitleSafe = sanitize($subtitle);
    $actionsHtml = $actions !== ''
        ? '<div class="dashboard-hero-actions d-flex gap-2 flex-wrap">' . $actions . '</div>'
        : '';

    return <<<HTML
<div class="dashboard-hero">
    <div class="dashboard-hero-inner d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="d-flex align-items-center gap-3">
            <img src="{$logo}" alt="JHCSC Logo" class="dashboard-brand-logo">
            <div>
                <div class="dashboard-campus">{$campus}</div>
                <div class="dashboard-app-name">{$appName}</div>
                <h1 class="dashboard-title mb-1"><i class="bi {$icon}"></i> {$titleSafe}</h1>
                <p class="dashboard-subtitle mb-0">{$subtitleSafe}</p>
            </div>
        </div>
        {$actionsHtml}
    </div>
</div>
HTML;
}
