<?php
/**
 * Collect Credit Payment
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$customer_id = (int)($_GET['id'] ?? 0);
if (!$customer_id) redirect('index.php');

$branch_id = $_SESSION['branch_id'];
$isAdmin = isAdmin();

// Fetch Customer
$stmt = $pdo->prepare("SELECT * FROM credit_customers WHERE id = ?" . (!$isAdmin ? " AND branch_id = " . (int)$branch_id : ""));
$stmt->execute([$customer_id]);
$customer = $stmt->fetch();

if (!$customer) {
    flashMessage('danger', 'Customer not found.');
    redirect('index.php');
}

// Fetch Unpaid/Partial Credit Bills
$stmt = $pdo->prepare("SELECT id, bill_number, total_amount, paid_amount, (total_amount - paid_amount) as outstanding, created_at 
                     FROM bills 
                     WHERE customer_phone = ? AND bill_type = 'credit' AND status IN ('credit', 'partial') 
                     ORDER BY created_at ASC");
$stmt->execute([$customer['phone']]);
$unpaid_bills = $stmt->fetchAll();

$total_outstanding = 0;
foreach ($unpaid_bills as $ub) $total_outstanding += $ub['outstanding'];

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float)($_POST['amount'] ?? 0);
    $date = $_POST['payment_date'] ?? date('Y-m-d');
    $note = sanitize($_POST['note'] ?? '');
    $selected_bills = $_POST['bill_ids'] ?? [];

    if ($amount <= 0) {
        $error = 'Please enter a valid payment amount.';
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Insert Credit Payment record
            $stmt = $pdo->prepare("INSERT INTO credit_payments (credit_customer_id, amount, payment_date, note, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$customer_id, $amount, $date, $note, $_SESSION['user_id']]);
            $payment_id = $pdo->lastInsertId();

            // 2. Distribute payment to bills if selected
            if (!empty($selected_bills)) {
                $remaining_payment = $amount;
                foreach ($selected_bills as $bill_id) {
                    if ($remaining_payment <= 0) break;

                    // Fetch current bill state to be safe
                    $stmt = $pdo->prepare("SELECT total_amount, paid_amount FROM bills WHERE id = ?");
                    $stmt->execute([$bill_id]);
                    $bill = $stmt->fetch();
                    
                    $bill_out = $bill['total_amount'] - $bill['paid_amount'];
                    $payment_to_apply = min($remaining_payment, $bill_out);

                    if ($payment_to_apply > 0) {
                        $stmt = $pdo->prepare("UPDATE bills SET paid_amount = paid_amount + ?, status = IF(paid_amount + ? >= total_amount, 'paid', 'partial') WHERE id = ?");
                        $stmt->execute([$payment_to_apply, $payment_to_apply, $bill_id]);
                        $remaining_payment -= $payment_to_apply;
                    }
                }
            }

            $pdo->commit();
            logAudit('collect_payment', 'customers', "Collected " . formatCurrency($amount) . " from " . $customer['name']);
            flashMessage('success', 'Payment recorded successfully.');
            redirect('ledger.php?id=' . $customer_id);

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Collect Payment: ' . $customer['name'];
include_once __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="form-card">
            <div class="card-header">
                <h3 class="card-title text-success">Collect Payment</h3>
                <a href="ledger.php?id=<?php echo $customer_id; ?>" class="btn btn-outline btn-sm">Cancel</a>
            </div>

            <div class="alert alert-info mt-3 d-flex justify-between align-center">
                <div>
                    <strong><?php echo sanitize($customer['name']); ?></strong><br>
                    <span class="fs-12"><?php echo sanitize($customer['phone']); ?></span>
                </div>
                <div class="text-end">
                    <div class="fs-12 text-muted">Current Dues</div>
                    <div class="fs-20 fw-700 text-danger"><?php echo formatCurrency($total_outstanding); ?></div>
                </div>
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
                        <label class="form-label">Payment Amount (₹) <span class="req">*</span></label>
                        <input type="number" step="0.01" name="amount" class="form-control fs-18 fw-700 text-success" 
                               value="<?php echo $total_outstanding; ?>" max="<?php echo $total_outstanding; ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Date <span class="req">*</span></label>
                        <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <div class="form-group mt-3">
                    <label class="form-label">Internal Note</label>
                    <textarea name="note" class="form-control" rows="2" placeholder="e.g. Received via PhonePe, Cash, etc."></textarea>
                </div>

                <?php if (!empty($unpaid_bills)): ?>
                    <div class="pos-section-title mt-5 mb-3">Apply Payment to Bills</div>
                    <div class="table-wrapper" style="max-height: 300px; overflow-y: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width: 40px;"></th>
                                    <th>Bill #</th>
                                    <th>Date</th>
                                    <th class="text-end">Outstanding</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($unpaid_bills as $ub): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="bill_ids[]" value="<?php echo $ub['id']; ?>" checked class="form-check-input">
                                        </td>
                                        <td class="fw-600">#<?php echo $ub['bill_number']; ?></td>
                                        <td class="fs-11 text-muted"><?php echo formatDate($ub['created_at']); ?></td>
                                        <td class="text-end text-danger fw-600"><?php echo formatCurrency($ub['outstanding']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="fs-11 text-muted mt-2"><i class="fas fa-info-circle me-1"></i> Payment will be distributed among selected bills starting from the oldest.</p>
                <?php endif; ?>

                <div class="d-flex justify-end gap-2 mt-5 border-top pt-4">
                    <button type="submit" class="btn btn-success btn-lg px-5">
                        <i class="fas fa-check-circle me-1"></i> Confirm Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
