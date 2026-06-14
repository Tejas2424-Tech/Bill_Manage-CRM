<?php
/**
 * Authentication and Authorization Logic
 */

require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Require user to be logged in
 */
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/modules/auth/login.php');
        exit;
    }
}

/**
 * Require specific roles to access a page
 */
function requireRole($roles) {
    requireLogin();
    
    $user_role = $_SESSION['role'] ?? '';
    
    if (is_array($roles)) {
        if (!in_array($user_role, $roles)) {
            redirect403();
        }
    } else {
        if ($user_role !== $roles) {
            redirect403();
        }
    }
}

/**
 * Redirect to 403 Forbidden page (or show error)
 */
function redirect403() {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Access Denied: You do not have permission to view this page.'];
    header('Location: ' . BASE_URL . '/modules/dashboard/index.php');
    exit;
}

/**
 * Get current user data from session
 */
function getCurrentUser() {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'name' => $_SESSION['name'] ?? '',
        'email' => $_SESSION['email'] ?? '',
        'role' => $_SESSION['role'] ?? '',
        'branch_id' => $_SESSION['branch_id'] ?? null,
        'branch_name' => $_SESSION['branch_name'] ?? 'Main System'
    ];
}

/**
 * Check if current user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if current user is superadmin
 */
function isAdmin() {
    return ($_SESSION['role'] ?? '') === 'superadmin';
}

/**
 * Check if current user is admin or branch admin
 */
function isBranchAdmin() {
    $role = $_SESSION['role'] ?? '';
    return in_array($role, ['superadmin', 'branch_admin']);
}

/**
 * Check if current user is a cashier
 */
function isCashier() {
    return ($_SESSION['role'] ?? '') === 'cashier';
}

/**
 * Block cashier role from accessing a page
 */
function requireNotCashier() {
    requireLogin();
    if (isCashier()) redirect403();
}

/**
 * Get current user's branch ID
 */
function getCurrentBranchId() {
    return $_SESSION['branch_id'] ?? null;
}
?>
