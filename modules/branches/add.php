<?php
/**
 * Add Branch - Superadmin Only
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireRole('superadmin');

$pageTitle = 'Add Branch';
$breadcrumb = '<a href="' . BASE_URL . '/modules/branches/index.php">Branches</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Add Branch</span>';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $code = strtoupper(sanitize($_POST['code'] ?? ''));
    $address = sanitize($_POST['address'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $manager = sanitize($_POST['manager_name'] ?? '');
    $status = $_POST['status'] ?? 'active';

    // Validation
    if (empty($name) || empty($code)) {
        $error = 'Branch Name and Code are required.';
    } elseif (!preg_match('/^[A-Z0-9]{2,10}$/', $code)) {
        $error = 'Branch Code must be 2-10 alphanumeric characters.';
    } else {
        // Check if code is unique
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE code = ?");
        $stmt->execute([$code]);
        if ($stmt->fetchColumn() > 0) {
            $error = 'Branch Code already exists. Please choose a unique code.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO branches (name, code, address, phone, manager_name, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $code, $address, $phone, $manager, $status]);
                
                logAudit('add_branch', 'branches', "Created new branch: $name ($code)");
                flashMessage('success', 'Branch created successfully.');
                redirect('index.php');
            } catch (Exception $e) {
                $error = 'Error: ' . $e->getMessage();
            }
        }
    }
}

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="form-card">
            <div class="card-header">
                <h3 class="card-title">Register New Branch</h3>
                <a href="index.php" class="btn btn-outline btn-sm">Back</a>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger mt-3">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo $error; ?></div>
                </div>
            <?php endif; ?>

            <form action="" method="POST" class="mt-4">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Branch Name <span class="req">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?php echo sanitize($_POST['name'] ?? ''); ?>" required placeholder="e.g. Downtown Outlet">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Branch Code <span class="req">*</span></label>
                        <input type="text" name="code" class="form-control fw-700" 
                               value="<?php echo sanitize($_POST['code'] ?? ''); ?>" 
                               required maxlength="10" 
                               oninput="this.value=this.value.toUpperCase()"
                               placeholder="e.g. DNTN01">
                        <small class="text-muted">Unique 2-10 char code used for tracking.</small>
                    </div>
                </div>

                <div class="form-row mt-3">
                    <div class="form-group">
                        <label class="form-label">Manager Name</label>
                        <input type="text" name="manager_name" class="form-control" value="<?php echo sanitize($_POST['manager_name'] ?? ''); ?>" placeholder="Full Name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="phone" class="form-control" value="<?php echo sanitize($_POST['phone'] ?? ''); ?>" placeholder="Phone number">
                    </div>
                </div>

                <div class="form-group mt-3">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2" placeholder="Full branch address..."><?php echo sanitize($_POST['address'] ?? ''); ?></textarea>
                </div>

                <div class="form-group mt-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <option value="active" <?php echo ($_POST['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($_POST['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <div class="d-flex justify-end gap-2 mt-5 border-top pt-4">
                    <button type="reset" class="btn btn-outline">Reset</button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-save me-1"></i> Create Branch
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
