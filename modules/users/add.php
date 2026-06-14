<?php
/**
 * Add New User
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole('superadmin');

$pageTitle = 'Add User';
$breadcrumb = '<a href="' . BASE_URL . '/modules/users/index.php">Users</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Add User</span>';

$error = '';
$branches = $pdo->query("SELECT id, name FROM branches WHERE status='active'")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
        redirect('add.php');
    }
    $name = sanitize($_POST['name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? 'staff';
    $branch_id = $role === 'superadmin' ? null : ($_POST['branch_id'] ?: null);
    $status = $_POST['status'] ?? 'active';

    // Validation
    if (empty($name) || empty($email) || empty($password)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif ($role !== 'superadmin' && empty($branch_id)) {
        $error = 'Please select a branch for non-superadmin users.';
    } else {
        // Check if email exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'Email already registered.';
        } else {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (branch_id, name, email, password, role, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$branch_id, $name, $email, $hashed, $role, $status]);
            
            logAudit('add_user', 'users', "Created user: $email");
            flashMessage('success', 'User created successfully.');
            redirect('index.php');
        }
    }
}

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="form-card">
            <div class="card-header">
                <h3 class="card-title">Create New User Account</h3>
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
                        <input type="text" name="name" class="form-control" value="<?php echo sanitize($_POST['name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email Address <span class="req">*</span></label>
                        <input type="email" name="email" class="form-control" value="<?php echo sanitize($_POST['email'] ?? ''); ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Password <span class="req">*</span></label>
                        <input type="password" name="password" class="form-control" required minlength="6">
                        <p class="form-hint">Min. 6 characters</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm Password <span class="req">*</span></label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="6">
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label">Role <span class="req">*</span></label>
                        <select name="role" id="roleSelect" class="form-control" required>
                            <option value="cashier" <?php echo (isset($_POST['role']) && $_POST['role'] === 'cashier') ? 'selected' : ''; ?>>Cashier</option>
                            <option value="staff" <?php echo (isset($_POST['role']) && $_POST['role'] === 'staff') ? 'selected' : ''; ?>>Staff</option>
                            <option value="branch_admin" <?php echo (isset($_POST['role']) && $_POST['role'] === 'branch_admin') ? 'selected' : ''; ?>>Branch Admin</option>
                            <option value="superadmin" <?php echo (isset($_POST['role']) && $_POST['role'] === 'superadmin') ? 'selected' : ''; ?>>Super Admin</option>
                        </select>
                    </div>
                    <div class="form-group" id="branchGroup">
                        <label class="form-label">Branch <span class="req">*</span></label>
                        <select name="branch_id" class="form-control">
                            <option value="">Select Branch</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>" <?php echo (isset($_POST['branch_id']) && $_POST['branch_id'] == $b['id']) ? 'selected' : ''; ?>>
                                    <?php echo $b['name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="active" selected>Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="d-flex justify-end gap-3 mt-5">
                    <button type="reset" class="btn btn-outline">Reset Form</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const roleSelect = document.getElementById('roleSelect');
    const branchGroup = document.getElementById('branchGroup');
    const branchSelect = branchGroup.querySelector('select');

    function toggleBranchVisibility() {
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

    roleSelect.addEventListener('change', toggleBranchVisibility);
    toggleBranchVisibility();
</script>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
