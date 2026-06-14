<?php
/**
 * Edit User Account
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['superadmin', 'branch_admin']);

$user_id = (int)($_GET['id'] ?? 0);
if (!$user_id) redirect('index.php');

// Fetch user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    flashMessage('danger', 'User not found.');
    redirect('index.php');
}

// Access Control Checks
if (!isAdmin()) {
    // Branch admin can only edit users in their branch
    if ($user['branch_id'] != $_SESSION['branch_id']) {
        flashMessage('danger', 'You do not have permission to edit users from other branches.');
        redirect('index.php');
    }
    // Branch admin cannot edit superadmins
    if ($user['role'] === 'superadmin') {
        flashMessage('danger', 'You do not have permission to edit Super Admin accounts.');
        redirect('index.php');
    }
}

$pageTitle = 'Edit User';
$breadcrumb = '<a href="' . BASE_URL . '/modules/users/index.php">Users</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Edit User</span>';

$error = '';
$branches = $pdo->query("SELECT id, name FROM branches WHERE status='active'")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
        redirect('index.php');
    }
    $name = sanitize($_POST['name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? $user['role'];
    $branch_id = $role === 'superadmin' ? null : ($_POST['branch_id'] ?: null);
    $status = $_POST['status'] ?? $user['status'];

    // Validation
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$email, $user_id]);
    $email_exists = $stmt->fetchColumn() > 0;

    if (empty($name) || empty($email)) {
        $error = 'Name and Email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($email_exists) {
        $error = 'Email address is already in use by another user.';
    } elseif (!empty($password) && strlen($password) < 6) {
        $error = 'New password must be at least 6 characters long.';
    } else {
        // Prevent branch admin from elevating their own role or changing their own branch
        if (!isAdmin() && $user_id === (int)$_SESSION['user_id']) {
            $role = $user['role'];
            $branch_id = $user['branch_id'];
        }

        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, password = ?, role = ?, branch_id = ?, status = ? WHERE id = ?");
            $stmt->execute([$name, $email, $hashed_password, $role, $branch_id, $status, $user_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, branch_id = ?, status = ? WHERE id = ?");
            $stmt->execute([$name, $email, $role, $branch_id, $status, $user_id]);
        }
        
        logAudit('edit_user', 'users', "Updated user account: $email");
        flashMessage('success', 'User updated successfully.');
        redirect('index.php');
    }
}

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="form-card">
            <div class="card-header">
                <h3 class="card-title">Edit User Account: <?php echo sanitize($user['name']); ?></h3>
                <a href="index.php" class="btn btn-outline btn-sm">Back to List</a>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger mt-3">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo $error; ?></div>
                </div>
            <?php endif; ?>

            <form action="" method="POST" class="mt-4">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Full Name <span class="req">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?php echo sanitize($user['name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email Address <span class="req">*</span></label>
                        <input type="email" name="email" class="form-control" value="<?php echo sanitize($user['email']); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">New Password (Leave blank to keep current)</label>
                    <input type="password" name="password" class="form-control" minlength="6">
                    <p class="form-hint">Only fill this if you want to change the user's password.</p>
                </div>

                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label">Role <span class="req">*</span></label>
                        <select name="role" id="roleSelect" class="form-control" required <?php echo (!isAdmin() && $user_id === (int)$_SESSION['user_id']) ? 'disabled' : ''; ?>>
                            <option value="cashier" <?php echo $user['role'] === 'cashier' ? 'selected' : ''; ?>>Cashier</option>
                            <option value="staff" <?php echo $user['role'] === 'staff' ? 'selected' : ''; ?>>Staff</option>
                            <option value="branch_admin" <?php echo $user['role'] === 'branch_admin' ? 'selected' : ''; ?>>Branch Admin</option>
                            <?php if (isAdmin()): ?>
                                <option value="superadmin" <?php echo $user['role'] === 'superadmin' ? 'selected' : ''; ?>>Super Admin</option>
                            <?php endif; ?>
                        </select>
                        <?php if (!isAdmin() && $user_id === (int)$_SESSION['user_id']): ?>
                            <input type="hidden" name="role" value="<?php echo $user['role']; ?>">
                        <?php endif; ?>
                    </div>
                    <div class="form-group" id="branchGroup">
                        <label class="form-label">Branch <span class="req">*</span></label>
                        <select name="branch_id" class="form-control" <?php echo (!isAdmin() && $user_id === (int)$_SESSION['user_id']) ? 'disabled' : ''; ?>>
                            <option value="">Select Branch</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>" <?php echo $user['branch_id'] == $b['id'] ? 'selected' : ''; ?>>
                                    <?php echo $b['name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!isAdmin() && $user_id === (int)$_SESSION['user_id']): ?>
                            <input type="hidden" name="branch_id" value="<?php echo $user['branch_id']; ?>">
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control" <?php echo ($user_id === (int)$_SESSION['user_id']) ? 'disabled' : ''; ?>>
                            <option value="active" <?php echo $user['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $user['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                        <?php if ($user_id === (int)$_SESSION['user_id']): ?>
                            <input type="hidden" name="status" value="<?php echo $user['status']; ?>">
                        <?php endif; ?>
                    </div>
                </div>

                <div class="d-flex justify-end gap-3 mt-5">
                    <a href="index.php" class="btn btn-outline">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const roleSelect = document.getElementById('roleSelect');
    const branchGroup = document.getElementById('branchGroup');
    const branchSelect = branchGroup ? branchGroup.querySelector('select') : null;

    function toggleBranchVisibility() {
        if (!branchSelect) return;
        if (roleSelect.value === 'superadmin') {
            branchGroup.style.opacity = '0.5';
            branchSelect.disabled = true;
            branchSelect.required = false;
        } else {
            branchGroup.style.opacity = '1';
            branchSelect.disabled = false;
            branchSelect.required = true;
        }
    }

    if (roleSelect) {
        roleSelect.addEventListener('change', toggleBranchVisibility);
        toggleBranchVisibility();
    }
</script>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
