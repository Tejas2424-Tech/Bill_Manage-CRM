<?php
/**
 * Profit Report
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle = 'Profit Report';
$breadcrumb = '<a href="' . BASE_URL . '/modules/reports/index.php">Reports</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Profit Report</span>';

$branch_id = $_SESSION['branch_id'];
$isAdmin = isAdmin();

// Date Range logic
$range = $_GET['range'] ?? 'this_month';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

if ($range === 'today') {
    $date_from = $date_to = date('Y-m-d');
} elseif ($range === 'this_week') {
    $date_from = date('Y-m-d', strtotime('monday this week'));
    $date_to = date('Y-m-d');
} elseif ($range === 'this_month') {
    $date_from = date('Y-m-01');
    $date_to = date('Y-m-d');
}

if (!$date_from) $date_from = date('Y-m-01');
if (!$date_to) $date_to = date('Y-m-d');

// Branch Filter
$selected_branch = $_GET['branch_id'] ?? ($isAdmin ? 'all' : $branch_id);
$branch_filter = "";
if ($selected_branch !== 'all') {
    $branch_filter = " AND b.branch_id = " . (int)$selected_branch;
}

// 1. Gross Profit Calculation
$query_gross = "SELECT SUM((bi.selling_price - p.purchase_price) * bi.quantity) as gross_profit 
                FROM bill_items bi 
                JOIN bills b ON bi.bill_id = b.id 
                JOIN products p ON bi.product_id = p.id 
                WHERE b.created_at BETWEEN ? AND ? AND b.status != 'cancelled' $branch_filter";
$stmt = $pdo->prepare($query_gross);
$stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$gross_profit = $stmt->fetchColumn() ?: 0;

// 2. Net Profit Calculation
$branch_filter_expenses = ($selected_branch !== 'all') ? " AND branch_id = " . (int)$selected_branch : "";
$stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE expense_date BETWEEN ? AND ? $branch_filter_expenses");
$stmt->execute([$date_from, $date_to]);
$total_expenses = $stmt->fetchColumn() ?: 0;
$net_profit = $gross_profit - $total_expenses;

// 3. Top 10 Most Profitable Products
$query_top = "SELECT p.name, p.sku, SUM((bi.selling_price - p.purchase_price) * bi.quantity) as profit,
              SUM(bi.quantity) as qty, AVG(bi.selling_price - p.purchase_price) as avg_margin
              FROM bill_items bi 
              JOIN bills b ON bi.bill_id = b.id 
              JOIN products p ON bi.product_id = p.id 
              WHERE b.created_at BETWEEN ? AND ? AND b.status != 'cancelled' $branch_filter 
              GROUP BY bi.product_id ORDER BY profit DESC LIMIT 10";
$stmt = $pdo->prepare($query_top);
$stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$top_profitable = $stmt->fetchAll();

// 4. Low Margin / Loss Making Products
$branch_filter_products = ($selected_branch !== 'all') ? " AND p.branch_id = " . (int)$selected_branch : "";
$query_low = "SELECT p.name, p.sku, p.purchase_price, p.selling_price, (p.selling_price - p.purchase_price) as margin
              FROM products p
              WHERE p.status = 'active' $branch_filter_products
              AND (p.selling_price <= p.purchase_price OR p.selling_price = 0 OR (p.selling_price - p.purchase_price) / p.selling_price < 0.05)
              ORDER BY margin ASC LIMIT 10";
$stmt = $pdo->prepare($query_low);
$stmt->execute();
$low_margin = $stmt->fetchAll();

// 5. Expense Breakdown
$stmt = $pdo->prepare("SELECT category, SUM(amount) as total FROM expenses WHERE expense_date BETWEEN ? AND ? $branch_filter_expenses GROUP BY category ORDER BY total DESC");
$stmt->execute([$date_from, $date_to]);
$expense_breakdown = $stmt->fetchAll();

// Handle Export CSV
if (isset($_POST['export'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
    } else {
        $filename = "profit_report_{$date_from}_to_{$date_to}.csv";
        $headers = ['Product', 'SKU', 'Qty Sold', 'Total Profit', 'Avg Margin/Unit'];
        $export_data = [];
        foreach ($top_profitable as $tp) {
            $export_data[] = [$tp['name'], $tp['sku'], $tp['qty'], $tp['profit'], $tp['avg_margin']];
        }
        exportCSV($filename, $headers, $export_data);
    }
}

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header flex-wrap gap-3">
    <h1>Profit & Margin Analysis</h1>
    <div class="d-flex gap-2">
        <form action="" method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="export" value="1">
            <button type="submit" class="btn btn-outline btn-sm"><i class="fas fa-file-csv me-1"></i> Export CSV</button>
        </form>
    </div>
</div>

<div class="table-toolbar mb-4 flex-wrap gap-3">
    <div class="btn-group">
        <a href="?range=today" class="btn btn-outline btn-sm <?php echo $range === 'today' ? 'active' : ''; ?>">Today</a>
        <a href="?range=this_week" class="btn btn-outline btn-sm <?php echo $range === 'this_week' ? 'active' : ''; ?>">This Week</a>
        <a href="?range=this_month" class="btn btn-outline btn-sm <?php echo $range === 'this_month' ? 'active' : ''; ?>">This Month</a>
    </div>

    <form action="" method="GET" class="d-flex gap-2 flex-grow-1">
        <input type="hidden" name="range" value="custom">
        <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>" style="max-width: 150px;">
        <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>" style="max-width: 150px;">
        <button type="submit" class="btn btn-primary btn-icon"><i class="fas fa-filter"></i></button>
    </form>
</div>

<div class="stat-grid mb-5">
    <div class="stat-card blue">
        <div class="stat-value"><?php echo formatCurrency($gross_profit); ?></div>
        <div class="stat-label">Gross Profit (Sales Margin)</div>
    </div>
    <div class="stat-card red">
        <div class="stat-value"><?php echo formatCurrency($total_expenses); ?></div>
        <div class="stat-label">Total Expenses</div>
    </div>
    <div class="stat-card <?php echo $net_profit >= 0 ? 'green' : 'red'; ?> shadow-lg">
        <div class="stat-value fs-30"><?php echo formatCurrency($net_profit); ?></div>
        <div class="stat-label fw-700">NET PROFIT / LOSS</div>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="table-wrapper mb-4">
            <div class="card-header border-0 mb-3"><h3 class="card-title">Top 10 Profitable Products</h3></div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Qty Sold</th>
                        <th>Total Profit</th>
                        <th>Margin/Unit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_profitable as $tp): ?>
                        <tr>
                            <td><div class="fw-600"><?php echo sanitize($tp['name']); ?></div><div class="fs-11 text-muted"><?php echo $tp['sku']; ?></div></td>
                            <td><?php echo $tp['qty']; ?></td>
                            <td class="fw-700 text-success"><?php echo formatCurrency($tp['profit']); ?></td>
                            <td class="text-secondary"><?php echo formatCurrency($tp['avg_margin']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="table-wrapper border-danger">
            <div class="card-header border-0 mb-3"><h3 class="card-title text-danger">Low Margin / Loss Warning</h3></div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Purchase</th>
                        <th>Selling</th>
                        <th>Margin</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($low_margin as $lm): ?>
                        <tr class="<?php echo $lm['margin'] <= 0 ? 'bg-danger-subtle' : ''; ?>">
                            <td><div class="fw-600"><?php echo sanitize($lm['name']); ?></div></td>
                            <td><?php echo formatCurrency($lm['purchase_price']); ?></td>
                            <td><?php echo formatCurrency($lm['selling_price']); ?></td>
                            <td class="fw-700 <?php echo $lm['margin'] <= 0 ? 'text-danger' : 'text-warning'; ?>">
                                <?php echo formatCurrency($lm['margin']); ?>
                                <span class="fs-10">(<?php echo round(($lm['margin'] / $lm['selling_price']) * 100, 1); ?>%)</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Expense Breakdown</h3></div>
            <div class="p-3">
                <?php if (empty($expense_breakdown)): ?>
                    <p class="text-center py-5 text-muted">No expenses in this period.</p>
                <?php else: ?>
                    <?php foreach ($expense_breakdown as $eb): 
                        $perc = ($total_expenses > 0) ? ($eb['total'] / $total_expenses) * 100 : 0;
                    ?>
                        <div class="mb-4">
                            <div class="d-flex justify-between mb-1">
                                <span class="fs-13 fw-500"><?php echo ucfirst($eb['category']); ?></span>
                                <span class="fs-13 fw-700"><?php echo formatCurrency($eb['total']); ?></span>
                            </div>
                            <div class="progress" style="height: 6px; background: var(--border);">
                                <div class="progress-bar bg-accent" style="width: <?php echo $perc; ?>%"></div>
                            </div>
                            <div class="text-end fs-10 text-muted mt-1"><?php echo round($perc, 1); ?>% of total expenses</div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>

