<?php
/**
 * Add / Edit Alteration job.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../customers/customer_lib.php';

requireNotCashier();

$isAdmin   = isAdmin();
$branch_id = (int)($_SESSION['branch_id'] ?? 0);

$id      = isset($_GET['id']) ? (int)$_GET['id'] : null;
$is_edit = $id !== null;

$pageTitle  = $is_edit ? 'Edit Alteration' : 'Add Alteration';
$breadcrumb = '<a href="'.BASE_URL.'/modules/alteration/index.php">Alterations</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">'.($is_edit ? 'Edit' : 'Add').'</span>';

$alt = null;
if ($is_edit) {
    $stmt = $pdo->prepare("SELECT * FROM alterations WHERE id = ?" . (!$isAdmin ? " AND branch_id = " . $branch_id : ""));
    $stmt->execute([$id]);
    $alt = $stmt->fetch();
    if (!$alt) { flashMessage('danger', 'Alteration not found.'); redirect('index.php'); }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
        redirect('index.php');
    }

    $customer_name   = sanitize($_POST['customer_name'] ?? '');
    $mobile          = sanitize($_POST['mobile'] ?? '');
    $bill_number     = sanitize($_POST['bill_number'] ?? '');
    $product_name    = sanitize($_POST['product_name'] ?? '');
    $alteration_type = sanitize($_POST['alteration_type'] ?? '');
    $charge          = (float)($_POST['alteration_charge'] ?? 0);
    $alteration_date = $_POST['alteration_date'] ?: date('Y-m-d');
    $status          = in_array($_POST['status'] ?? '', ['pending','ready','delivered'], true) ? $_POST['status'] : 'pending';

    // Resolve the branch (superadmin posts a branch; others use their own).
    if ($isAdmin && !$is_edit) {
        $target_branch = (int)($_POST['branch_id'] ?? 0);
    } else {
        $target_branch = $is_edit ? (int)$alt['branch_id'] : $branch_id;
    }

    if ($customer_name === '' || $alteration_type === '' || ($isAdmin && !$is_edit && !$target_branch)) {
        $error = 'Customer Name, Alteration Type' . ($isAdmin && !$is_edit ? ' and Branch' : '') . ' are required.';
    } else {
        // Link to a customer master record when a mobile is supplied (reused helper).
        $customer_id = findOrCreateCustomer($pdo, $target_branch, $customer_name, $mobile, null, (int)$_SESSION['user_id']);

        if ($is_edit) {
            $stmt = $pdo->prepare("UPDATE alterations SET customer_id=?, customer_name=?, mobile=?, bill_number=?, product_name=?, alteration_type=?, alteration_charge=?, alteration_date=?, status=? WHERE id=?" . (!$isAdmin ? " AND branch_id = " . $branch_id : ""));
            $stmt->execute([$customer_id, $customer_name, $mobile, $bill_number, $product_name, $alteration_type, $charge, $alteration_date, $status, $id]);
            logAudit('edit_alteration', 'alteration', "Updated alteration ID: $id");
            flashMessage('success', 'Alteration updated.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO alterations (branch_id, customer_id, customer_name, mobile, bill_number, product_name, alteration_type, alteration_charge, alteration_date, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$target_branch, $customer_id, $customer_name, $mobile, $bill_number, $product_name, $alteration_type, $charge, $alteration_date, $status, $_SESSION['user_id']]);
            logAudit('add_alteration', 'alteration', "Added alteration for $customer_name");
            flashMessage('success', 'Alteration recorded.');
        }
        redirect('index.php');
    }
}

$branches = $isAdmin ? $pdo->query("SELECT id, name FROM branches WHERE status='active' ORDER BY name ASC")->fetchAll() : [];

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="form-card">
            <div class="card-header">
                <h3 class="card-title"><?php echo $is_edit ? 'Update Alteration' : 'Record New Alteration'; ?></h3>
                <a href="index.php" class="btn btn-outline btn-sm">Back to List</a>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger mt-3"><i class="fas fa-exclamation-circle"></i><div><?php echo $error; ?></div></div>
            <?php endif; ?>

            <form action="" method="POST" class="mt-4">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                <?php if ($isAdmin && !$is_edit): ?>
                <div class="form-group mb-3">
                    <label class="form-label">Branch <span class="req">*</span></label>
                    <select name="branch_id" class="form-control" required>
                        <option value="">Select Branch</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo sanitize($b['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Customer Name <span class="req">*</span></label>
                        <input type="text" name="customer_name" class="form-control" value="<?php echo sanitize($alt['customer_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mobile Number</label>
                        <input type="text" name="mobile" class="form-control" value="<?php echo sanitize($alt['mobile'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row mt-3">
                    <div class="form-group">
                        <label class="form-label">Bill Number</label>
                        <input type="text" name="bill_number" class="form-control" value="<?php echo sanitize($alt['bill_number'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Product Name</label>
                        <input type="text" name="product_name" class="form-control" value="<?php echo sanitize($alt['product_name'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row mt-3">
                    <div class="form-group">
                        <label class="form-label">Alteration Type <span class="req">*</span></label>
                        <input type="text" name="alteration_type" class="form-control" list="altTypes" value="<?php echo sanitize($alt['alteration_type'] ?? ''); ?>" required>
                        <datalist id="altTypes">
                            <?php foreach (['Hemming','Length','Waist','Sleeve','Shoulder','Tapering','Lining','Other'] as $t): ?>
                                <option value="<?php echo $t; ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Alteration Charge (₹)</label>
                        <input type="number" step="0.01" min="0" name="alteration_charge" class="form-control" value="<?php echo $alt['alteration_charge'] ?? '0'; ?>">
                    </div>
                </div>

                <div class="form-row mt-3">
                    <div class="form-group">
                        <label class="form-label">Date</label>
                        <input type="date" name="alteration_date" class="form-control" value="<?php echo sanitize($alt['alteration_date'] ?? date('Y-m-d')); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <?php foreach (['pending'=>'Pending','ready'=>'Ready','delivered'=>'Delivered'] as $k=>$v): ?>
                                <option value="<?php echo $k; ?>" <?php echo ($alt['status'] ?? 'pending') === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="d-flex justify-end gap-3 mt-5 border-top pt-4">
                    <a href="index.php" class="btn btn-outline">Cancel</a>
                    <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> <?php echo $is_edit ? 'Update' : 'Save'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
