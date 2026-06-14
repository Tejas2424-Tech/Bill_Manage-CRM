<?php
/**
 * Change Password Module
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = 'Change Password';
$breadcrumb = '<a href="' . BASE_URL . '/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Change Password</span>';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
        redirect(BASE_URL . '/modules/auth/change_password.php');
    }
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Basic Validation
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = 'All fields are required.';
    } elseif (strlen($new_password) < 6) {
        $error = 'New password must be at least 6 characters long.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'New password and confirmation do not match.';
    } else {
        // Verify current password
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if ($user && password_verify($current_password, $user['password'])) {
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
            $update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $update->execute([$hashed_password, $_SESSION['user_id']]);

            logAudit('change_password', 'auth', 'User changed their password');
            flashMessage('success', 'Password updated successfully.');
            redirect(BASE_URL . '/modules/auth/change_password.php');
        } else {
            $error = 'Current password is incorrect.';
        }
    }
}

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="form-card">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Security Settings</h3>
                    <p class="card-subtitle">Update your account password to keep it secure.</p>
                </div>
                <i class="fas fa-shield-alt text-accent fs-20"></i>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo $error; ?></div>
                </div>
            <?php endif; ?>

            <form action="" method="POST" class="mt-4">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div class="form-group">
                    <label class="form-label">Current Password <span class="req">*</span></label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>

                <hr class="border-secondary my-4">

                <div class="form-group">
                    <label class="form-label">New Password <span class="req">*</span></label>
                    <input type="password" name="new_password" class="form-control" required minlength="6">
                    <p class="form-hint">Must be at least 6 characters long.</p>
                </div>

                <div class="form-group">
                    <label class="form-label">Confirm New Password <span class="req">*</span></label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="6">
                </div>

                <div class="d-flex justify-end gap-3 mt-5">
                    <a href="<?php echo BASE_URL; ?>/modules/dashboard/index.php" class="btn btn-outline">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-key"></i> Update Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
