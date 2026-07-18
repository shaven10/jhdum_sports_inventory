<?php
/**
 * Authentication and Authorization
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        flash('error', 'Please login to access this page.');
        redirect(BASE_URL . '/login.php');
    }
}

function requireRole(array $roles): void
{
    requireLogin();
    if (!in_array($_SESSION['user_role'], $roles, true)) {
        flash('error', 'You do not have permission to access this page.');
        redirect(BASE_URL . '/dashboard.php');
    }
}

function login(string $username, string $password): array
{
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1');
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        if ($user) {
            logLoginAttempt($user['id'], 'failed');
        }
        return ['success' => false, 'message' => 'Invalid username or password.'];
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
    $_SESSION['username'] = $user['username'];

    logLoginAttempt($user['id'], 'success');
    auditLog($user['id'], 'login', 'user', $user['id']);

    return ['success' => true, 'message' => 'Login successful.', 'role' => $user['role']];
}

function logout(): void
{
    if (isLoggedIn()) {
        auditLog($_SESSION['user_id'], 'logout', 'user', $_SESSION['user_id']);
    }
    session_destroy();
    session_start();
}

function getCurrentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function logLoginAttempt(int $userId, string $status): void
{
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO login_history (user_id, ip_address, user_agent, status) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        $userId,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null,
        $status
    ]);
}

function hasRole(string $role): bool
{
    return isLoggedIn() && $_SESSION['user_role'] === $role;
}

function canManageInventory(): bool
{
    return isLoggedIn() && in_array($_SESSION['user_role'], ['admin', 'coordinator', 'staff'], true);
}

function canApproveRequests(): bool
{
    return isLoggedIn() && in_array($_SESSION['user_role'], ['admin', 'coordinator'], true);
}

function canManageUsers(): bool
{
    return isLoggedIn() && $_SESSION['user_role'] === 'admin';
}

function canViewReports(): bool
{
    return isLoggedIn() && in_array($_SESSION['user_role'], ['admin', 'coordinator', 'staff'], true);
}

function canManageSettings(): bool
{
    return isLoggedIn() && $_SESSION['user_role'] === 'admin';
}
