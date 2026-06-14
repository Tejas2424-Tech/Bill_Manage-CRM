<?php
/**
 * Edit Branch - Superadmin Only
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireRole('superadmin');

$branch_id = (int)($_GET['id'] ?? 0);
if (!$branch_id) redirect('index.php');

$pageTitle = 'Edit Branch';
$breadcrumb = '<a href="' . BASE_URL . '/modules/branches/index.php">Branches</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Edit Branch</span>';

// Fetch Branch with Stats
$stmt = $pdo->prepare("SELECT b.*, 
                      (SELECT COUNT(*) FROM bills WHERE branch_id = b.id) as total_bills,
                      (SELECT COUNT(*) FROM products WHERE branch_id = b.id) as total_products,
                      (SELECT COUNT(*) FROM users WHERE branch_id = b.id) as total_staff
                      FROM branches b WHERE b.id = ?");
$stmt->execute([$branch_id]);
$branch = $stmt->fetch();

if (!$branch) {
    flashMessage('danger', 'Branch not found.');
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $manager = sanitize($_POST['manager_name'] ?? '');
    $status = $_POST['status'] ?? 'active';

    if (empty($name)) {
        $error = 'Branch Name is required.';
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE branches SET name=?, address=?, phone=?, manager_name=?, status=? WHERE id=?");
            $stmt->execute([$name, $address, $phone, $manager, $status, $branch_id]);
            
            logAudit('edit_branch', 'branches', "Updated branch: $name ({$branch['code']})");
            flashMessage('success', 'Branch updated successfully.');
            redirect('index.php');
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="stat-grid mb-4">
            <div class="stat-card blue">
                <div class="stat-value"><?php echo $branch['total_bills']; ?></div>
                <div class="stat-label">Total Bills</div>
            </div>
            <div class="stat-card purple">
                <div class="stat-value"><?php echo $branch['total_products']; ?></div>
                <div class="stat-label">Total Products</div>
            </div>
            <div class="stat-card green">
                <div class="stat-value"><?php echo $branch['total_staff']; ?></div>
                <div class="stat-label">Staff Count</div>
            </div>
        </div>

        <div class="form-card">
            <div class="card-header">
                <h3 class="card-title">Edit Branch Details</h3>
                <div class="fs-12 text-muted">Created: <?php echo formatDate($branch['created_at']); ?></div>
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
                        <input type="text" name="name" class="form-control" value="<?php echo sanitize($branch['name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Branch Code</label>
                        <input type="text" class="form-control bg-secondary text-muted" value="<?php echo sanitize($branch['code']); ?>" disabled>
                        <small class="text-muted italic">Code cannot be changed after creation.</small>
                    </div>
                </div>

                <div class="form-row mt-3">
                    <div class="form-group">
                        <label class="form-label">Manager Name</label>
                        <input type="text" name="manager_name" class="form-control" value="<?php echo sanitize($branch['manager_name']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="phone" class="form-control" value="<?php echo sanitize($branch['phone']); ?>">
                    </div>
                </div>

                <div class="form-group mt-3">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2"><?php echo sanitize($branch['address']); ?></textarea>
                </div>

                <div class="form-group mt-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <option value="active" <?php echo $branch['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $branch['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <div class="d-flex justify-end gap-2 mt-5 border-top pt-4">
                    <a href="index.php" class="btn btn-outline">Cancel</a>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-save me-1"></i> Update Branch
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
