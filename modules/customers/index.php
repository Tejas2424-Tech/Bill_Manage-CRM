<?php
/**
 * Credit Customers (Khata) Management
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = 'Credit Customers';
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Credit Customers</span>';

$branch_id = $_SESSION['branch_id'];
$isAdmin = isAdmin();
$branch_filter = $isAdmin ? "" : " AND cc.branch_id = " . (int)$branch_id;
$branch_filter_bills = $isAdmin ? "" : " AND branch_id = " . (int)$branch_id;

// Handle Delete (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
    } else {
        $id = (int)$_POST['id'];
        
        // Check outstanding before delete
        $stmt = $pdo->prepare("SELECT SUM(total_amount - paid_amount) as outstanding 
                             FROM bills 
                             WHERE customer_phone = (SELECT phone FROM credit_customers WHERE id = ?) 
                             AND bill_type = 'credit' AND status != 'cancelled' $branch_filter_bills");
        $stmt->execute([$id]);
        $outstanding = $stmt->fetchColumn() ?: 0;

        if ($outstanding > 0) {
            flashMessage('danger', 'Cannot delete customer with outstanding balance: ' . formatCurrency($outstanding));
        } else {
            $stmt = $pdo->prepare("DELETE FROM credit_customers WHERE id = ?" . (!$isAdmin ? " AND branch_id = " . (int)$branch_id : ""));
            $stmt->execute([$id]);
            logAudit('delete_customer', 'customers', "Deleted customer ID: $id");
            flashMessage('success', 'Customer deleted successfully.');
        }
    }
    redirect('index.php');
}

// Summary Stats
$stats = [
    'total_customers' => 0,
    'total_outstanding' => 0,
    'collected_month' => 0,
    'with_dues' => 0
];

// 1. Total Customers
$stmt = $pdo->query("SELECT COUNT(*) FROM credit_customers WHERE 1=1 " . (!$isAdmin ? " AND branch_id = $branch_id" : ""));
$stats['total_customers'] = $stmt->fetchColumn();

// 2. Total Outstanding & Customers with Dues
$query_dues = "SELECT SUM(total_amount - paid_amount) as total_out, COUNT(DISTINCT customer_phone) as cust_count 
               FROM bills 
               WHERE bill_type = 'credit' AND status != 'cancelled' AND (total_amount - paid_amount) > 0 $branch_filter_bills";
$res_dues = $pdo->query($query_dues)->fetch();
$stats['total_outstanding'] = $res_dues['total_out'] ?: 0;
$stats['with_dues'] = $res_dues['cust_count'] ?: 0;

// 3. Collected This Month
$query_month = "SELECT SUM(amount) FROM credit_payments cp 
                JOIN credit_customers cc ON cp.credit_customer_id = cc.id 
                WHERE MONTH(cp.payment_date) = MONTH(CURDATE()) AND YEAR(cp.payment_date) = YEAR(CURDATE()) $branch_filter";
$stats['collected_month'] = $pdo->query($query_month)->fetchColumn() ?: 0;

// Main Query for Table
$query = "SELECT cc.*, 
          COALESCE(SUM(b.total_amount), 0) as total_credit,
          COALESCE(SUM(b.paid_amount), 0) as total_paid,
          COALESCE(SUM(b.total_amount - b.paid_amount), 0) as outstanding,
          (SELECT MAX(created_at) FROM (
              SELECT created_at, customer_phone as phone, NULL as cust_id FROM bills WHERE bill_type = 'credit' AND status != 'cancelled'
              UNION ALL
              SELECT created_at, NULL as phone, credit_customer_id as cust_id FROM credit_payments
          ) as t WHERE t.phone = cc.phone OR t.cust_id = cc.id) as last_transaction
          FROM credit_customers cc 
          LEFT JOIN bills b ON b.customer_phone = cc.phone AND b.bill_type = 'credit' AND b.status != 'cancelled' " . ($isAdmin ? "" : " AND b.branch_id = cc.branch_id") . "
          WHERE 1=1 $branch_filter 
          GROUP BY cc.id 
          ORDER BY cc.name ASC";

$customers = $pdo->query($query)->fetchAll();
$csrf_token = generateCSRFToken();

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="stat-grid mb-4">
    <div class="stat-card blue">
        <div class="stat-value"><?php echo $stats['total_customers']; ?></div>
        <div class="stat-label">Total Customers</div>
    </div>
    <div class="stat-card red">
        <div class="stat-value"><?php echo formatCurrency($stats['total_outstanding']); ?></div>
        <div class="stat-label">Total Outstanding</div>
    </div>
    <div class="stat-card green">
        <div class="stat-value"><?php echo formatCurrency($stats['collected_month']); ?></div>
        <div class="stat-label">Collected This Month</div>
    </div>
    <div class="stat-card orange">
        <div class="stat-value"><?php echo $stats['with_dues']; ?></div>
        <div class="stat-label">Customers with Dues</div>
    </div>
</div>

<div class="page-header">
    <h1><?php echo $pageTitle; ?></h1>
    <a href="add.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> Add Customer
    </a>
</div>

<div class="table-wrapper">
    <div class="table-toolbar">
        <input type="text" id="customerSearch" class="form-control" placeholder="Search by name or phone..." onkeyup="filterTable('customerSearch', 'customerTable')" style="max-width: 300px;">
    </div>
    <table class="data-table" id="customerTable">
        <thead>
            <tr>
                <th>Name</th>
                <th>Phone</th>
                <th>Reference</th>
                <th>Total Credit</th>
                <th>Total Paid</th>
                <th>Outstanding</th>
                <th>Last Activity</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($customers as $c): ?>
                <tr>
                    <td>
                        <div class="fw-600"><?php echo sanitize($c['name']); ?></div>
                        <div class="fs-11 text-muted truncate" style="max-width: 150px;"><?php echo sanitize($c['address']); ?></div>
                    </td>
                    <td><?php echo sanitize($c['phone']); ?></td>
                    <td>
                        <?php if ($c['reference_name']): ?>
                            <div class="fs-12"><?php echo sanitize($c['reference_name']); ?></div>
                            <div class="fs-10 text-muted"><?php echo ucfirst($c['reference_relation']); ?></div>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo formatCurrency($c['total_credit']); ?></td>
                    <td><?php echo formatCurrency($c['total_paid']); ?></td>
                    <td>
                        <?php if ($c['outstanding'] > 0): ?>
                            <span class="text-danger fw-700"><?php echo formatCurrency($c['outstanding']); ?></span>
                        <?php else: ?>
                            <span class="badge-pill badge-success">Clear</span>
                        <?php endif; ?>
                    </td>
                    <td class="fs-11">
                        <?php echo $c['last_transaction'] ? formatDate($c['last_transaction']) : 'No activity'; ?>
                    </td>
                    <td class="text-end">
                        <div class="d-flex justify-end gap-1">
                            <a href="ledger.php?id=<?php echo $c['id']; ?>" class="btn btn-ghost btn-icon btn-sm text-accent" title="Ledger"><i class="fas fa-book"></i></a>
                            <a href="collect_payment.php?id=<?php echo $c['id']; ?>" class="btn btn-ghost btn-icon btn-sm text-success" title="Collect Payment"><i class="fas fa-hand-holding-dollar"></i></a>
                            <a href="add.php?id=<?php echo $c['id']; ?>" class="btn btn-ghost btn-icon btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                            <form action="" method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" class="btn btn-ghost btn-icon btn-sm text-danger" 
                                        <?php echo $c['outstanding'] > 0 ? 'disabled' : ''; ?>
                                        data-confirm="Delete this customer?" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($customers)): ?>
                <tr><td colspan="8" class="text-center py-5 text-muted">No credit customers found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
