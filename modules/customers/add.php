<?php
/**
 * Add / Edit Credit Customer
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$customer_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$is_edit = $customer_id !== null;

$pageTitle = $is_edit ? 'Edit Customer' : 'Add Credit Customer';
$breadcrumb = "People / Credit Customers / " . ($is_edit ? 'Edit' : 'Add');

$customer = null;
if ($is_edit) {
    $stmt = $pdo->prepare("SELECT * FROM credit_customers WHERE id = ?" . (!isAdmin() ? " AND branch_id = " . (int)$_SESSION['branch_id'] : ""));
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();
    if (!$customer) {
        flashMessage('danger', 'Customer not found.');
        redirect('index.php');
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $ref_name = sanitize($_POST['reference_name'] ?? '');
    $ref_rel = $_POST['reference_relation'] ?? '';
    $branch_id = (int)($_SESSION['branch_id'] ?? 1); // single-branch app

    // Validation
    if (empty($name) || empty($phone)) {
        $error = 'Customer Name and Mobile Number are required.';
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $error = 'Please enter a valid 10-digit mobile number.';
    } else {
        try {
            if ($is_edit) {
                $stmt = $pdo->prepare("UPDATE credit_customers SET name=?, phone=?, address=?, reference_name=?, reference_relation=? WHERE id=?");
                $stmt->execute([$name, $phone, $address, $ref_name, $ref_rel, $customer_id]);
                logAudit('edit_customer', 'customers', "Updated customer: $name ($phone)");
                flashMessage('success', 'Customer updated successfully.');
            } else {
                // Check if phone already exists in this branch
                $stmt = $pdo->prepare("SELECT id FROM credit_customers WHERE phone = ? AND branch_id = ?");
                $stmt->execute([$phone, $branch_id]);
                if ($stmt->fetch()) {
                    $error = 'A customer with this phone number already exists in this branch.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO credit_customers (branch_id, name, phone, address, reference_name, reference_relation, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$branch_id, $name, $phone, $address, $ref_name, $ref_rel, $_SESSION['user_id']]);
                    logAudit('add_customer', 'customers', "Added new credit customer: $name");
                    flashMessage('success', 'Customer registered successfully.');
                    redirect('index.php');
                }
            }
            if (!$error) redirect('index.php');
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="form-card">
            <div class="card-header">
                <h3 class="card-title"><?php echo $is_edit ? 'Edit Customer Details' : 'Register New Credit Customer'; ?></h3>
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
                        <label class="form-label">Customer Name <span class="req">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?php echo sanitize($customer['name'] ?? ''); ?>" required placeholder="Full Name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mobile Number <span class="req">*</span></label>
                        <input type="text" name="phone" class="form-control" value="<?php echo sanitize($customer['phone'] ?? ''); ?>" required maxlength="10" placeholder="10-digit mobile">
                    </div>
                </div>

                <div class="form-group mt-3">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2" placeholder="Street, Area, City..."><?php echo sanitize($customer['address'] ?? ''); ?></textarea>
                </div>

                <div class="pos-section-title mt-5">Reference Information (Optional)</div>
                <div class="form-row mt-3">
                    <div class="form-group">
                        <label class="form-label">Reference Person Name</label>
                        <input type="text" name="reference_name" class="form-control" value="<?php echo sanitize($customer['reference_name'] ?? ''); ?>" placeholder="Guarantor or relative">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Relation with Customer</label>
                        <select name="reference_relation" class="form-control">
                            <option value="">Select Relation</option>
                            <option value="son" <?php echo ($customer['reference_relation'] ?? '') === 'son' ? 'selected' : ''; ?>>Son</option>
                            <option value="daughter" <?php echo ($customer['reference_relation'] ?? '') === 'daughter' ? 'selected' : ''; ?>>Daughter</option>
                            <option value="mother" <?php echo ($customer['reference_relation'] ?? '') === 'mother' ? 'selected' : ''; ?>>Mother</option>
                            <option value="father" <?php echo ($customer['reference_relation'] ?? '') === 'father' ? 'selected' : ''; ?>>Father</option>
                            <option value="friend" <?php echo ($customer['reference_relation'] ?? '') === 'friend' ? 'selected' : ''; ?>>Friend</option>
                            <option value="other" <?php echo ($customer['reference_relation'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>

                <div class="d-flex justify-end gap-2 mt-5 border-top pt-4">
                    <button type="reset" class="btn btn-outline">Reset</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> <?php echo $is_edit ? 'Update Customer' : 'Save Customer'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
