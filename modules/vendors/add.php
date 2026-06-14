<?php
/**
 * Add / Edit Vendor
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$vendor_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$is_edit = $vendor_id !== null;

$pageTitle = $is_edit ? 'Edit Vendor' : 'Add Vendor';
$breadcrumb = "People / Vendors / " . ($is_edit ? 'Edit' : 'Add');

$vendor = null;
if ($is_edit) {
    $stmt = $pdo->prepare("SELECT * FROM vendors WHERE id = ?" . (!isAdmin() ? " AND branch_id = " . (int)$_SESSION['branch_id'] : ""));
    $stmt->execute([$vendor_id]);
    $vendor = $stmt->fetch();
    if (!$vendor) {
        flashMessage('danger', 'Vendor not found.');
        redirect('index.php');
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $gst = sanitize($_POST['gst_number'] ?? '');
    $payment = $_POST['payment_type'] ?? 'cash';
    $notes = sanitize($_POST['notes'] ?? '');
    $status = (int)($_POST['status'] ?? 1);
    $branch_id = $_SESSION['branch_id'];

    if (empty($name) || empty($phone)) {
        $error = 'Vendor Name and Contact Number are required.';
    } else {
        if ($is_edit) {
            $stmt = $pdo->prepare("UPDATE vendors SET name=?, phone=?, address=?, gst_number=?, payment_type=?, notes=?, status=? WHERE id=?");
            $stmt->execute([$name, $phone, $address, $gst, $payment, $notes, $status, $vendor_id]);
            logAudit('edit_vendor', 'vendors', "Updated vendor: $name");
            flashMessage('success', 'Vendor updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO vendors (branch_id, name, phone, address, gst_number, payment_type, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$branch_id, $name, $phone, $address, $gst, $payment, $notes, $status]);
            logAudit('add_vendor', 'vendors', "Added new vendor: $name");
            flashMessage('success', 'Vendor added successfully.');
        }
        redirect('index.php');
    }
}

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="form-card">
            <div class="card-header">
                <h3 class="card-title"><?php echo $is_edit ? 'Edit Vendor Details' : 'Register New Vendor'; ?></h3>
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
                        <label class="form-label">Vendor Name <span class="req">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?php echo sanitize($vendor['name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contact Number <span class="req">*</span></label>
                        <input type="text" name="phone" class="form-control" value="<?php echo sanitize($vendor['phone'] ?? ''); ?>" required>
                    </div>
                </div>

                <div class="form-group mt-3">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2"><?php echo sanitize($vendor['address'] ?? ''); ?></textarea>
                </div>

                <div class="form-row mt-3">
                    <div class="form-group">
                        <label class="form-label">GST Number</label>
                        <input type="text" name="gst_number" class="form-control" value="<?php echo sanitize($vendor['gst_number'] ?? ''); ?>" placeholder="Optional">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Preferred Payment Type</label>
                        <select name="payment_type" class="form-control">
                            <option value="cash" <?php echo ($vendor['payment_type'] ?? '') === 'cash' ? 'selected' : ''; ?>>
                                💵 Cash
                            </option>
                            <option value="upi" <?php echo ($vendor['payment_type'] ?? '') === 'upi' ? 'selected' : ''; ?>>
                                📱 UPI / PhonePe
                            </option>
                            <option value="bank" <?php echo ($vendor['payment_type'] ?? '') === 'bank' ? 'selected' : ''; ?>>
                                🏦 Bank Transfer
                            </option>
                        </select>
                    </div>
                </div>

                <div class="form-group mt-3">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Any internal notes about this vendor..."><?php echo sanitize($vendor['notes'] ?? ''); ?></textarea>
                </div>

                <div class="form-group mt-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <option value="1" <?php echo ($vendor['status'] ?? 1) == 1 ? 'selected' : ''; ?>>Active</option>
                        <option value="0" <?php echo ($vendor['status'] ?? 1) == 0 ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <div class="d-flex justify-end gap-2 mt-5 border-top pt-4">
                    <button type="reset" class="btn btn-outline">Reset</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> <?php echo $is_edit ? 'Update Vendor' : 'Save Vendor'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
