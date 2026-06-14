<?php
/**
 * Main Entry Point - Redirection Logic
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (isset($_SESSION['user_id'])) {
    // Redirect to dashboard
    header('Location: modules/dashboard/index.php');
} else {
    // Redirect to login page
    header('Location: modules/auth/login.php');
}
exit;
?>
