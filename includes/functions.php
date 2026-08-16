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

/** Staff/admin announcements (not shown to student accounts). */
function ensureAnnouncementsTable(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(['announcements']);
    if ((int) $stmt->fetchColumn() === 0) {
        $db->exec("CREATE TABLE announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            message TEXT NOT NULL,
            type ENUM('info', 'success', 'warning', 'danger') NOT NULL DEFAULT 'info',
            target_roles JSON DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
        return;
    }

    $col = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $col->execute(['announcements', 'target_roles']);
    if ((int) $col->fetchColumn() === 0) {
        $db->exec('ALTER TABLE announcements ADD COLUMN target_roles JSON DEFAULT NULL AFTER type');
    }
}

function canViewAnnouncements(): bool
{
    return isLoggedIn() && ($_SESSION['user_role'] ?? '') !== 'student';
}

function canManageAnnouncements(): bool
{
    return function_exists('isAdmin') ? isAdmin() : (isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'admin');
}

function requireAnnouncementsAccess(): void
{
    requireLogin();
    if (!canViewAnnouncements()) {
        flash('error', 'Announcements are not available for student accounts.');
        redirect(getHomeUrl());
    }
}

/** Roles that may be targeted by announcements (students excluded). */
function getAnnouncementTargetRoleOptions(): array
{
    $roles = getAllRoles();
    unset($roles['student']);
    return $roles;
}

/**
 * @param mixed $raw
 * @return list<string>
 */
function normalizeAnnouncementRoles($raw): array
{
    $allowed = array_keys(getAnnouncementTargetRoleOptions());
    $roles = [];

    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $raw = $decoded;
        } else {
            $raw = array_map('trim', explode(',', $raw));
        }
    }

    if (!is_array($raw)) {
        return $allowed;
    }

    foreach ($raw as $role) {
        $role = (string) $role;
        if (in_array($role, $allowed, true) && !in_array($role, $roles, true)) {
            $roles[] = $role;
        }
    }

    return $roles;
}

/**
 * @param array<string, mixed> $announcement
 * @return list<string>
 */
function getAnnouncementRoles(array $announcement): array
{
    return normalizeAnnouncementRoles($announcement['target_roles'] ?? null);
}

function announcementTargetsRole(array $announcement, string $role): bool
{
    if ($role === 'student') {
        return false;
    }
    $roles = getAnnouncementRoles($announcement);
    return in_array($role, $roles, true);
}

function canViewAnnouncementRecord(array $announcement): bool
{
    if (!canViewAnnouncements()) {
        return false;
    }
    if (canManageAnnouncements()) {
        return true;
    }
    return announcementTargetsRole($announcement, (string) ($_SESSION['user_role'] ?? ''));
}

/**
 * Active users in the selected roles (students never included).
 *
 * @param list<string>|null $roles
 * @return list<int>
 */
function getAnnouncementRecipientIds(?array $roles = null): array
{
    $roles = normalizeAnnouncementRoles($roles);
    if (empty($roles)) {
        return [];
    }

    $db = getDB();
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $db->prepare("SELECT id FROM users WHERE is_active = 1 AND role IN ($placeholders) ORDER BY id");
    $stmt->execute($roles);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Create an announcement and fan out notifications to selected roles.
 *
 * @param list<string>|null $roles
 * @return array{success:bool,message?:string,id?:int,notified?:int}
 */
function publishAnnouncement(string $title, string $message, string $type = 'info', ?int $createdBy = null, ?array $roles = null): array
{
    ensureAnnouncementsTable();

    $title = trim($title);
    $message = trim($message);
    $allowedTypes = ['info', 'success', 'warning', 'danger'];
    if (!in_array($type, $allowedTypes, true)) {
        $type = 'info';
    }
    if ($title === '' || $message === '') {
        return ['success' => false, 'message' => 'Title and message are required.'];
    }
    if (mb_strlen($title) > 200) {
        return ['success' => false, 'message' => 'Title must be 200 characters or fewer.'];
    }

    $roles = normalizeAnnouncementRoles($roles);
    if (empty($roles)) {
        return ['success' => false, 'message' => 'Select at least one role to notify.'];
    }

    $db = getDB();
    $createdBy = $createdBy ?? ($_SESSION['user_id'] ?? null);
    $rolesJson = json_encode(array_values($roles));
    $stmt = $db->prepare('INSERT INTO announcements (title, message, type, target_roles, is_active, created_by) VALUES (?, ?, ?, ?, 1, ?)');
    $stmt->execute([$title, $message, $type, $rolesJson, $createdBy ?: null]);
    $id = (int) $db->lastInsertId();

    $link = BASE_URL . '/announcements/view.php?id=' . $id;
    $notifTitle = 'Announcement: ' . $title;
    $recipients = getAnnouncementRecipientIds($roles);
    foreach ($recipients as $userId) {
        createNotification($userId, $notifTitle, $message, $type, $link);
    }

    if ($createdBy) {
        auditLog((int) $createdBy, 'create_announcement', 'announcement', $id, null, [
            'title' => $title,
            'type' => $type,
            'roles' => $roles,
            'recipients' => count($recipients),
        ]);
    }

    $roleLabels = getAnnouncementTargetRoleOptions();
    $named = array_map(static fn($r) => $roleLabels[$r] ?? $r, $roles);

    return [
        'success' => true,
        'id' => $id,
        'notified' => count($recipients),
        'message' => 'Announcement published to ' . count($recipients) . ' account(s) (' . implode(', ', $named) . ').',
    ];
}

/**
 * Active announcements. By default filters to the current user's role.
 *
 * @return list<array<string, mixed>>
 */
function getActiveAnnouncements(int $limit = 20, bool $forCurrentRoleOnly = true): array
{
    ensureAnnouncementsTable();
    $db = getDB();
    $limit = max(1, min(100, $limit));
    $stmt = $db->prepare("SELECT a.*, u.first_name, u.last_name
        FROM announcements a
        LEFT JOIN users u ON a.created_by = u.id
        WHERE a.is_active = 1
        ORDER BY a.created_at DESC
        LIMIT {$limit}");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if ($forCurrentRoleOnly) {
        $role = (string) ($_SESSION['user_role'] ?? '');
        $rows = array_values(array_filter($rows, static function ($row) use ($role) {
            return announcementTargetsRole($row, $role);
        }));
    }

    return $rows;
}

function getAnnouncementById(int $id, bool $activeOnly = false): ?array
{
    ensureAnnouncementsTable();
    $db = getDB();
    $sql = "SELECT a.*, u.first_name, u.last_name
        FROM announcements a
        LEFT JOIN users u ON a.created_by = u.id
        WHERE a.id = ?";
    if ($activeOnly) {
        $sql .= ' AND a.is_active = 1';
    }
    $stmt = $db->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function formatAnnouncementRoles(array $announcement): string
{
    $labels = getAnnouncementTargetRoleOptions();
    $roles = getAnnouncementRoles($announcement);
    if (empty($roles)) {
        return 'All staff';
    }
    return implode(', ', array_map(static fn($r) => $labels[$r] ?? $r, $roles));
}

function setAnnouncementActive(int $id, bool $active): bool
{
    ensureAnnouncementsTable();
    $db = getDB();
    $stmt = $db->prepare('UPDATE announcements SET is_active = ? WHERE id = ?');
    $stmt->execute([$active ? 1 : 0, $id]);
    return $stmt->rowCount() > 0;
}

/** Incident reports from tournament / unit managers to administrators. */
function ensureIncidentReportsTable(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(['incident_reports']);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $db->exec("CREATE TABLE incident_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reporter_user_id INT NOT NULL,
        subject VARCHAR(200) NOT NULL,
        category VARCHAR(50) NOT NULL DEFAULT 'query',
        message TEXT NOT NULL,
        status ENUM('open', 'in_progress', 'resolved', 'closed') NOT NULL DEFAULT 'open',
        admin_response TEXT DEFAULT NULL,
        responded_by INT DEFAULT NULL,
        responded_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (responded_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_incident_status (status),
        INDEX idx_incident_reporter (reporter_user_id)
    ) ENGINE=InnoDB");
}

function getIncidentCategories(): array
{
    return [
        'query' => 'Query / Question',
        'incident' => 'Incident Report',
        'equipment' => 'Equipment Issue',
        'schedule' => 'Schedule Concern',
        'roster' => 'Roster Concern',
        'results' => 'Results Concern',
        'other' => 'Other',
    ];
}

function getIncidentStatuses(): array
{
    return [
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];
}

/**
 * @return list<int>
 */
function getAdminUserIds(): array
{
    $db = getDB();
    $stmt = $db->query("SELECT id FROM users WHERE is_active = 1 AND role = 'admin' ORDER BY id");
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * @return array{success:bool,message?:string,id?:int}
 */
function submitIncidentReport(int $reporterId, string $subject, string $message, string $category = 'query'): array
{
    ensureIncidentReportsTable();

    $subject = trim($subject);
    $message = trim($message);
    $categories = getIncidentCategories();
    if (!isset($categories[$category])) {
        $category = 'query';
    }
    if ($subject === '' || $message === '') {
        return ['success' => false, 'message' => 'Subject and message are required.'];
    }
    if (mb_strlen($subject) > 200) {
        return ['success' => false, 'message' => 'Subject must be 200 characters or fewer.'];
    }

    $db = getDB();
    $stmt = $db->prepare('INSERT INTO incident_reports (reporter_user_id, subject, category, message, status) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$reporterId, $subject, $category, $message, 'open']);
    $id = (int) $db->lastInsertId();

    $link = BASE_URL . '/admin/incidents/view.php?id=' . $id;
    $notifTitle = 'New incident report: ' . $subject;
    foreach (getAdminUserIds() as $adminId) {
        createNotification($adminId, $notifTitle, $message, 'warning', $link);
    }

    auditLog($reporterId, 'submit_incident_report', 'incident_report', $id, null, [
        'subject' => $subject,
        'category' => $category,
    ]);

    return [
        'success' => true,
        'id' => $id,
        'message' => 'Your report was sent to the administrator.',
    ];
}

/**
 * @return array{success:bool,message?:string}
 */
function respondToIncidentReport(int $reportId, int $adminId, string $response, string $status = 'resolved'): array
{
    ensureIncidentReportsTable();

    $response = trim($response);
    $statuses = getIncidentStatuses();
    if (!isset($statuses[$status])) {
        $status = 'resolved';
    }
    if ($response === '') {
        return ['success' => false, 'message' => 'A response message is required.'];
    }

    $report = getIncidentReportById($reportId);
    if (!$report) {
        return ['success' => false, 'message' => 'Report not found.'];
    }

    $db = getDB();
    $stmt = $db->prepare('UPDATE incident_reports SET admin_response = ?, status = ?, responded_by = ?, responded_at = NOW() WHERE id = ?');
    $stmt->execute([$response, $status, $adminId, $reportId]);

    $link = BASE_URL . '/incidents/view.php?id=' . $reportId;
    createNotification(
        (int) $report['reporter_user_id'],
        'Admin reply: ' . $report['subject'],
        $response,
        'info',
        $link
    );

    auditLog($adminId, 'respond_incident_report', 'incident_report', $reportId, null, [
        'status' => $status,
    ]);

    return ['success' => true, 'message' => 'Response sent to the reporter.'];
}

function getIncidentReportById(int $id): ?array
{
    ensureIncidentReportsTable();
    $db = getDB();
    $stmt = $db->prepare("SELECT r.*,
            u.first_name AS reporter_first_name, u.last_name AS reporter_last_name, u.username AS reporter_username, u.role AS reporter_role, u.email AS reporter_email,
            a.first_name AS responder_first_name, a.last_name AS responder_last_name
        FROM incident_reports r
        JOIN users u ON r.reporter_user_id = u.id
        LEFT JOIN users a ON r.responded_by = a.id
        WHERE r.id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * @return list<array<string, mixed>>
 */
function getIncidentReportsForUser(int $userId, int $limit = 50): array
{
    ensureIncidentReportsTable();
    $limit = max(1, min(200, $limit));
    $db = getDB();
    $stmt = $db->prepare("SELECT r.* FROM incident_reports r WHERE r.reporter_user_id = ? ORDER BY r.created_at DESC LIMIT {$limit}");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * @return list<array<string, mixed>>
 */
function getIncidentReportsForAdmin(?string $status = null, int $limit = 100): array
{
    ensureIncidentReportsTable();
    $limit = max(1, min(300, $limit));
    $db = getDB();
    $sql = "SELECT r.*, u.first_name AS reporter_first_name, u.last_name AS reporter_last_name, u.role AS reporter_role, u.username AS reporter_username
        FROM incident_reports r
        JOIN users u ON r.reporter_user_id = u.id";
    $params = [];
    if ($status !== null && $status !== '' && isset(getIncidentStatuses()[$status])) {
        $sql .= ' WHERE r.status = ?';
        $params[] = $status;
    }
    $sql .= " ORDER BY FIELD(r.status, 'open', 'in_progress', 'resolved', 'closed'), r.created_at DESC LIMIT {$limit}";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function countOpenIncidentReports(): int
{
    ensureIncidentReportsTable();
    $db = getDB();
    return (int) $db->query("SELECT COUNT(*) FROM incident_reports WHERE status IN ('open', 'in_progress')")->fetchColumn();
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
        'info'        => 'info',
        'success'     => 'success',
        'warning'     => 'warning',
        'danger'      => 'danger',
        'open'        => 'warning',
        'in_progress' => 'info',
        'resolved'    => 'success',
        'closed'      => 'secondary',
        'query'       => 'primary',
        'incident'    => 'danger',
        'equipment'   => 'warning',
        'schedule'    => 'info',
        'roster'      => 'secondary',
        'results'     => 'success',
        'other'       => 'secondary',
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
        'publication'   => 'info',
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

/** Absolute URL to the official Sports Development logo. */
function appLogoUrl(): string
{
    return defined('APP_LOGO') ? APP_LOGO : (BASE_URL . '/assets/img/sports-development-logo.png');
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
    $appName = sanitize(defined('APP_NAME') ? APP_NAME : 'JHCSC Sports Development IMIS');
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
        <img src="{$logo}" alt="Sports Development Logo" class="report-brand-logo">
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
    $appName = sanitize(defined('APP_NAME') ? APP_NAME : 'JHCSC Sports Development IMIS');
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

/** Banner when match results are locked. */
function renderResultsLockNotice(?int $seasonId = null): string
{
    if (!function_exists('shouldShowResultsLockStatus') || !shouldShowResultsLockStatus()) {
        return '';
    }

    $status = function_exists('getResultsLockStatus') ? getResultsLockStatus($seasonId) : null;
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
<div class="alert alert-danger results-lock-notice mb-3">
    <div class="d-flex flex-wrap align-items-start gap-2">
        <div class="flex-grow-1">
            <i class="bi bi-lock-fill"></i>
            <strong>Results locked</strong> for intramurals {$year}.
            {$dateLine}
            Score updates, live scoring, and event ranking edits are disabled until an administrator unlocks results.
        </div>
    </div>
</div>
HTML;
}

/** Banner when a future results lock date is scheduled. */
function renderResultsLockScheduleNotice(?int $seasonId = null): string
{
    if (!function_exists('shouldShowResultsLockStatus') || !shouldShowResultsLockStatus()) {
        return '';
    }

    $status = function_exists('getResultsLockStatus') ? getResultsLockStatus($seasonId) : null;
    if (!$status || !$status['is_scheduled'] || empty($status['lock_date'])) {
        return '';
    }

    $year = sanitize($status['year_label'] ?? '');
    $lockDate = sanitize(formatDate($status['lock_date']));
    $by = $status['locked_by_name'] ? sanitize($status['locked_by_name']) : 'Administrator';

    return <<<HTML
<div class="alert alert-info results-lock-schedule-notice mb-3">
    <div class="d-flex flex-wrap align-items-start gap-2">
        <div class="flex-grow-1">
            <i class="bi bi-calendar-event"></i>
            <strong>Results lock scheduled</strong> for intramurals {$year}.
            Results will lock on <strong>{$lockDate}</strong> (set by {$by}).
            Finish scoring before that date.
        </div>
    </div>
</div>
HTML;
}

/** Locked and scheduled results alerts for staff dashboards and match pages. */
function renderResultsLockAlerts(?int $seasonId = null): string
{
    return renderResultsLockNotice($seasonId) . renderResultsLockScheduleNotice($seasonId);
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
    $appName = sanitize(defined('APP_SHORT_NAME') ? APP_SHORT_NAME : 'JHCSC SDIMIS');
    $titleSafe = sanitize($title);
    $subtitleSafe = sanitize($subtitle);
    $actionsHtml = $actions !== ''
        ? '<div class="dashboard-hero-actions d-flex gap-2 flex-wrap">' . $actions . '</div>'
        : '';

    return <<<HTML
<div class="dashboard-hero">
    <div class="dashboard-hero-inner d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="d-flex align-items-center gap-3">
            <img src="{$logo}" alt="Sports Development Logo" class="dashboard-brand-logo">
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
