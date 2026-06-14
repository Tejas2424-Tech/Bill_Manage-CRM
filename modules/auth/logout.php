<?php
/**
 * Logout Handler
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Log activity before destroying session
logAudit('logout', 'auth', 'User logged out');

// Unset all session variables
$_SESSION = [];

// Destroy the session
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// Redirect to login page with message
header('Location: ' . BASE_URL . '/modules/auth/login.php?msg=logged_out');
exit;
?>
