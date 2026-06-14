<?php
/**
 * Add / Edit Expense
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$expense_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$is_edit = $expense_id !== null;

$pageTitle = $is_edit ? 'Edit Expense' : 'Add Expense';
$breadcrumb = "Financials / Expenses / " . ($is_edit ? 'Edit' : 'Add');

$expense = null;
if ($is_edit) {
    $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ?" . (!isAdmin() ? " AND branch_id = " . (int)$_SESSION['branch_id'] : ""));
    $stmt->execute([$expense_id]);
    $expense = $stmt->fetch();
    if (!$expense) {
        flashMessage('danger', 'Expense record not found.');
        redirect('index.php');
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category = sanitize($_POST['category'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $description = sanitize($_POST['description'] ?? '');
    $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
    $branch_id = $_SESSION['branch_id'];

    if (empty($category) || $amount <= 0) {
        $error = 'Category and valid Amount are required.';
    } else {
        try {
            if ($is_edit) {
                $stmt = $pdo->prepare("UPDATE expenses SET category=?, amount=?, description=?, expense_date=? WHERE id=?");
                $stmt->execute([$category, $amount, $description, $expense_date, $expense_id]);
                logAudit('edit_expense', 'expenses', "Updated expense: $category - " . formatCurrency($amount));
                flashMessage('success', 'Expense record updated.');
            } else {
                $stmt = $pdo->prepare("INSERT INTO expenses (branch_id, category, amount, description, expense_date, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$branch_id, $category, $amount, $description, $expense_date, $_SESSION['user_id']]);
                logAudit('add_expense', 'expenses', "Added expense: $category - " . formatCurrency($amount));
                flashMessage('success', 'Expense recorded successfully.');
            }
            redirect('index.php');
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="form-card">
            <div class="card-header">
                <h3 class="card-title"><?php echo $is_edit ? 'Update Expense Details' : 'Record New Expense'; ?></h3>
                <a href="index.php" class="btn btn-outline btn-sm">Back</a>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger mt-3">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo $error; ?></div>
                </div>
            <?php endif; ?>

            <form action="" method="POST" class="mt-4">
                <div class="form-group">
                    <label class="form-label">Category <span class="req">*</span></label>
                    <select name="category" class="form-control" required>
                        <option value="">Select Category</option>
                        <option value="rent" <?php echo ($expense['category'] ?? '') === 'rent' ? 'selected' : ''; ?>>ðŸ¢ Rent</option>
                        <option value="salary" <?php echo ($expense['category'] ?? '') === 'salary' ? 'selected' : ''; ?>>ðŸ‘¥ Salary</option>
                        <option value="electricity" <?php echo ($expense['category'] ?? '') === 'electricity' ? 'selected' : ''; ?>>âš¡ Electricity</option>
                        <option value="maintenance" <?php echo ($expense['category'] ?? '') === 'maintenance' ? 'selected' : ''; ?>>ðŸ”§ Maintenance</option>
                        <option value="transportation" <?php echo ($expense['category'] ?? '') === 'transportation' ? 'selected' : ''; ?>>ðŸšš Transportation</option>
                        <option value="internet" <?php echo ($expense['category'] ?? '') === 'internet' ? 'selected' : ''; ?>>ðŸŒ Internet</option>
                        <option value="miscellaneous" <?php echo ($expense['category'] ?? '') === 'miscellaneous' ? 'selected' : ''; ?>>ðŸ’¬ Miscellaneous</option>
                    </select>
                </div>

                <div class="form-row mt-3">
                    <div class="form-group">
                        <label class="form-label">Amount (â‚¹) <span class="req">*</span></label>
                        <input type="number" step="0.01" name="amount" class="form-control" value="<?php echo $expense['amount'] ?? ''; ?>" required placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Expense Date <span class="req">*</span></label>
                        <input type="date" name="expense_date" class="form-control" value="<?php echo $expense['expense_date'] ?? date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <div class="form-group mt-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Brief details about this expense..."><?php echo sanitize($expense['description'] ?? ''); ?></textarea>
                </div>

                <div class="d-flex justify-end gap-2 mt-5 border-top pt-4">
                    <button type="reset" class="btn btn-outline">Reset</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> <?php echo $is_edit ? 'Update Record' : 'Save Expense'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>

