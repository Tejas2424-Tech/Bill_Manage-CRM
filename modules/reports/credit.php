<?php
/**
 * Credit Report
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle = 'Credit Report';
$breadcrumb = '<a href="' . BASE_URL . '/modules/reports/index.php">Reports</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Credit Report</span>';

$branch_id = $_SESSION['branch_id'];
$isAdmin = isAdmin();

// Filters
$show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';
$selected_branch = $_GET['branch_id'] ?? ($isAdmin ? 'all' : $branch_id);
$branch_filter = ($selected_branch !== 'all') ? " AND cc.branch_id = " . (int)$selected_branch : "";

// Fetch Credit Customers with Dues
$query = "SELECT cc.*, 
          COALESCE(SUM(b.total_amount), 0) as total_credit,
          COALESCE(SUM(b.paid_amount), 0) as total_paid,
          COALESCE(SUM(b.total_amount - b.paid_amount), 0) as outstanding,
          MAX(b.created_at) as last_bill_date,
          DATEDIFF(CURDATE(), MIN(CASE WHEN b.total_amount > b.paid_amount THEN b.created_at END)) as days_outstanding
          FROM credit_customers cc 
          LEFT JOIN bills b ON b.customer_phone = cc.phone AND b.bill_type = 'credit' AND b.status != 'cancelled' 
          WHERE 1=1 $branch_filter 
          GROUP BY cc.id " . ($show_all ? "" : " HAVING outstanding > 0 ") . "
          ORDER BY outstanding DESC";

$stmt = $pdo->prepare($query);
$stmt->execute();
$customers = $stmt->fetchAll();

// 1. Aged Analysis Calculation
$aged = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
$stats = ['total_outstanding' => 0, 'customers_with_dues' => 0];

foreach ($customers as $c) {
    if ($c['outstanding'] <= 0) continue;
    
    $stats['total_outstanding'] += $c['outstanding'];
    $stats['customers_with_dues']++;
    
    // We need per-bill aging for accurate aged analysis, but here we'll simplify by oldest bill
    $days = $c['days_outstanding'] ?: 0;
    if ($days <= 30) $aged['0-30'] += $c['outstanding'];
    elseif ($days <= 60) $aged['31-60'] += $c['outstanding'];
    elseif ($days <= 90) $aged['61-90'] += $c['outstanding'];
    else $aged['90+'] += $c['outstanding'];
}

// 2. Collection in Period
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$stmt = $pdo->prepare("SELECT SUM(amount) FROM credit_payments cp JOIN credit_customers cc ON cp.credit_customer_id = cc.id WHERE cp.payment_date BETWEEN ? AND ? $branch_filter");
$stmt->execute([$date_from, $date_to]);
$total_collected = $stmt->fetchColumn() ?: 0;

// Handle Export CSV
if (isset($_POST['export'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
    } else {
        $filename = "credit_report_" . date('Y-m-d') . ".csv";
        $headers = ['Customer', 'Phone', 'Reference', 'Total Credit', 'Paid', 'Outstanding', 'Days Outstanding'];
        $export_data = [];
        foreach ($customers as $c) {
            $export_data[] = [
                $c['name'],
                $c['phone'],
                $c['reference_name'],
                $c['total_credit'],
                $c['total_paid'],
                $c['outstanding'],
                $c['days_outstanding'] ?: 0
            ];
        }
        exportCSV($filename, $headers, $export_data);
    }
}

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header flex-wrap gap-3">
    <h1>Credit & Aged Analysis</h1>
    <div class="d-flex gap-2">
        <form action="" method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="export" value="1">
            <button type="submit" class="btn btn-outline btn-sm"><i class="fas fa-file-csv me-1"></i> Export CSV</button>
        </form>
    </div>
</div>

<div class="table-toolbar mb-4 flex-wrap gap-3">
    <form action="" method="GET" class="d-flex gap-2 flex-grow-1">
        <select name="show_all" class="form-control" style="max-width: 180px;" onchange="this.form.submit()">
            <option value="0" <?php echo !$show_all ? 'selected' : ''; ?>>Outstanding Only</option>
            <option value="1" <?php echo $show_all ? 'selected' : ''; ?>>Show All Customers</option>
        </select>
        
        <?php if ($isAdmin): ?>
            <select name="branch_id" class="form-control" style="max-width: 180px;" onchange="this.form.submit()">
                <option value="all">All Branches</option>
                <?php foreach ($pdo->query("SELECT id, name FROM branches WHERE status='active'")->fetchAll() as $b): ?>
                    <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>><?php echo $b['name']; ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <div class="d-flex gap-2 ms-auto align-center">
            <span class="fs-12 text-muted">Collection Period:</span>
            <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>" style="max-width: 140px;">
            <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>" style="max-width: 140px;">
            <button type="submit" class="btn btn-primary btn-icon"><i class="fas fa-filter"></i></button>
        </div>
    </form>
</div>

<div class="stat-grid mb-4">
    <div class="stat-card red">
        <div class="stat-value"><?php echo formatCurrency($stats['total_outstanding']); ?></div>
        <div class="stat-label">Total Outstanding</div>
    </div>
    <div class="stat-card orange">
        <div class="stat-value"><?php echo $stats['customers_with_dues']; ?></div>
        <div class="stat-label">Customers with Dues</div>
    </div>
    <div class="stat-card green">
        <div class="stat-value"><?php echo formatCurrency($total_collected); ?></div>
        <div class="stat-label">Collected in Period</div>
    </div>
</div>

<!-- Aged Analysis -->
<div class="card mb-4 bg-secondary-subtle">
    <div class="card-header border-0"><h3 class="card-title">Aged Receivables (By Oldest Due)</h3></div>
    <div class="p-3">
        <div class="row text-center">
            <div class="col-md-3 border-end border-secondary">
                <div class="fs-11 text-muted uppercase mb-1">0 - 30 Days</div>
                <div class="fs-18 fw-700 text-info"><?php echo formatCurrency($aged['0-30']); ?></div>
            </div>
            <div class="col-md-3 border-end border-secondary">
                <div class="fs-11 text-muted uppercase mb-1">31 - 60 Days</div>
                <div class="fs-18 fw-700 text-warning"><?php echo formatCurrency($aged['31-60']); ?></div>
            </div>
            <div class="col-md-3 border-end border-secondary">
                <div class="fs-11 text-muted uppercase mb-1">61 - 90 Days</div>
                <div class="fs-18 fw-700 text-orange"><?php echo formatCurrency($aged['61-90']); ?></div>
            </div>
            <div class="col-md-3">
                <div class="fs-11 text-muted uppercase mb-1">90+ Days</div>
                <div class="fs-18 fw-700 text-danger"><?php echo formatCurrency($aged['90+']); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Customer</th>
                <th>Reference</th>
                <th>Total Credit</th>
                <th>Total Paid</th>
                <th>Outstanding</th>
                <th>Days Overdue</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($customers as $c): ?>
                <tr>
                    <td>
                        <div class="fw-600"><?php echo sanitize($c['name']); ?></div>
                        <div class="fs-11 text-muted"><?php echo $c['phone']; ?></div>
                    </td>
                    <td>
                        <div class="fs-12"><?php echo sanitize($c['reference_name'] ?: '-'); ?></div>
                        <div class="fs-10 text-muted"><?php echo ucfirst($c['reference_relation'] ?? ''); ?></div>
                    </td>
                    <td><?php echo formatCurrency($c['total_credit']); ?></td>
                    <td><?php echo formatCurrency($c['total_paid']); ?></td>
                    <td class="fw-700 text-danger"><?php echo formatCurrency($c['outstanding']); ?></td>
                    <td>
                        <?php if ($c['outstanding'] > 0): ?>
                            <span class="badge-pill <?php echo $c['days_outstanding'] > 60 ? 'badge-danger' : ($c['days_outstanding'] > 30 ? 'badge-warning' : 'badge-info'); ?>">
                                <?php echo $c['days_outstanding']; ?> Days
                            </span>
                        <?php else: ?>
                            <span class="badge-pill badge-success">Clear</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a href="../customers/ledger.php?id=<?php echo $c['id']; ?>" class="btn btn-ghost btn-sm text-accent"><i class="fas fa-book me-1"></i> Ledger</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>

