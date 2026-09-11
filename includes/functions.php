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
        'tournament_manager' => 'primary',
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
