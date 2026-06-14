<?php
/**
 * Customer Ledger (Khata Timeline)
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

// Fetch Bills
$stmt = $pdo->prepare("SELECT id, bill_number as ref, total_amount as amount, created_at as date, 'BILL' as type 
                     FROM bills 
                     WHERE customer_phone = ? AND bill_type = 'credit' AND status != 'cancelled' 
                     ORDER BY created_at ASC");
$stmt->execute([$customer['phone']]);
$bills = $stmt->fetchAll();

// Fetch Payments
$stmt = $pdo->prepare("SELECT id, 'Payment' as ref, amount, payment_date as date, 'PAYMENT' as type, note 
                     FROM credit_payments 
                     WHERE credit_customer_id = ? 
                     ORDER BY payment_date ASC, created_at ASC");
$stmt->execute([$customer_id]);
$payments = $stmt->fetchAll();

// Merge and Sort
$timeline = array_merge($bills, $payments);
usort($timeline, function($a, $b) {
    return strtotime($a['date']) - strtotime($b['date']);
});

// Calculate Running Balance & Stats
$total_credit = 0;
$total_paid = 0;
foreach ($timeline as &$item) {
    if ($item['type'] === 'BILL') {
        $total_credit += $item['amount'];
    } else {
        $total_paid += $item['amount'];
    }
    $item['balance'] = $total_credit - $total_paid;
}
unset($item);

$outstanding = $total_credit - $total_paid;

// Reverse for display (latest first)
$display_timeline = array_reverse($timeline);

$pageTitle = 'Ledger: ' . $customer['name'];
include_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4">
    <div>
        <h1 class="mb-0"><?php echo sanitize($customer['name']); ?></h1>
        <div class="text-muted fs-14"><?php echo sanitize($customer['phone']); ?> | <?php echo sanitize($customer['address']); ?></div>
    </div>
    <div class="d-flex gap-2">
        <a href="collect_payment.php?id=<?php echo $customer['id']; ?>" class="btn btn-success">
            <i class="fas fa-hand-holding-dollar me-1"></i> Collect Payment
        </a>
        <a href="index.php" class="btn btn-outline btn-sm">Back to List</a>
    </div>
</div>

<div class="stat-grid mb-5">
    <div class="stat-card blue">
        <div class="stat-value"><?php echo formatCurrency($total_credit); ?></div>
        <div class="stat-label">Total Credit (Bills)</div>
    </div>
    <div class="stat-card green">
        <div class="stat-value"><?php echo formatCurrency($total_paid); ?></div>
        <div class="stat-label">Total Paid</div>
    </div>
    <div class="stat-card <?php echo $outstanding > 0 ? 'red' : 'green'; ?> shadow-lg">
        <div class="stat-value fs-30"><?php echo formatCurrency($outstanding); ?></div>
        <div class="stat-label fw-700">CURRENT OUTSTANDING</div>
    </div>
</div>

<div class="timeline-wrapper">
    <h3 class="pos-section-title mb-4">Transaction History</h3>
    
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th>Debit (Credit)</th>
                    <th>Credit (Paid)</th>
                    <th>Running Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($display_timeline)): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">No transactions found for this customer.</td></tr>
                <?php else: ?>
                    <?php foreach ($display_timeline as $item): ?>
                        <tr>
                            <td class="fs-12"><?php echo formatDate($item['date']); ?></td>
                            <td>
                                <span class="badge-pill <?php echo $item['type'] === 'BILL' ? 'badge-danger' : 'badge-success'; ?>">
                                    <?php echo $item['type']; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($item['type'] === 'BILL'): ?>
                                    <a href="../billing/invoice.php?id=<?php echo $item['id']; ?>" class="text-primary fw-600">Bill #<?php echo $item['ref']; ?></a>
                                <?php else: ?>
                                    <div class="fw-600">Payment Received</div>
                                    <?php if (!empty($item['note'])): ?>
                                        <div class="fs-10 text-muted italic"><?php echo sanitize($item['note']); ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-danger">
                                <?php echo $item['type'] === 'BILL' ? formatCurrency($item['amount']) : '-'; ?>
                            </td>
                            <td class="text-success">
                                <?php echo $item['type'] === 'PAYMENT' ? formatCurrency($item['amount']) : '-'; ?>
                            </td>
                            <td class="fw-700 <?php echo $item['balance'] > 0 ? 'text-warning' : 'text-success'; ?>">
                                <?php echo formatCurrency($item['balance']); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
