<?php
/**
 * Sales Report
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle = 'Sales Report';
$breadcrumb = '<a href="' . BASE_URL . '/modules/reports/index.php">Reports</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Sales Report</span>';

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
} elseif ($range === 'yearly') {
    $date_from = date('Y-01-01');
    $date_to = date('Y-m-d');
} elseif ($range === 'financial_year') {
    $fy_start = ((int)date('n') >= 4) ? (int)date('Y') : (int)date('Y') - 1;   // FY starts 1 April
    $date_from = $fy_start . '-04-01';
    $date_to = date('Y-m-d');
}

if (!$date_from) $date_from = date('Y-m-01');
if (!$date_to) $date_to = date('Y-m-d');

// Branch Filter for Superadmin
$selected_branch = $_GET['branch_id'] ?? ($isAdmin ? 'all' : $branch_id);
$branch_filter = "";
if ($selected_branch !== 'all') {
    $branch_filter = " AND b.branch_id = " . (int)$selected_branch;
}

// 1. Summary Stats
$query_summary = "SELECT COUNT(*) as total_bills, SUM(total_amount) as total_revenue, 
                  AVG(total_amount) as avg_bill,
                  (SELECT SUM(quantity) FROM bill_items bi JOIN bills b2 ON bi.bill_id = b2.id WHERE b2.created_at BETWEEN ? AND ? AND b2.status != 'cancelled' " . str_replace('b.', 'b2.', $branch_filter) . ") as total_items
                  FROM bills b 
                  WHERE b.created_at BETWEEN ? AND ? AND b.status != 'cancelled' $branch_filter";
$stmt = $pdo->prepare($query_summary);
$stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59', $date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$summary = $stmt->fetch();

// 2. Daily Trend for Chart
$query_trend = "SELECT DATE(b.created_at) as sale_date, SUM(total_amount) as revenue 
                FROM bills b 
                WHERE b.created_at BETWEEN ? AND ? AND b.status != 'cancelled' $branch_filter 
                GROUP BY DATE(b.created_at) ORDER BY sale_date ASC";
$stmt = $pdo->prepare($query_trend);
$stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$trend_data = $stmt->fetchAll();

// 3. Product-wise Performance
$query_products = "SELECT p.name, p.sku, SUM(bi.quantity) as qty_sold, SUM(bi.total) as revenue,
                   SUM((bi.selling_price - p.purchase_price) * bi.quantity) as profit
                   FROM bill_items bi 
                   JOIN bills b ON bi.bill_id = b.id 
                   JOIN products p ON bi.product_id = p.id 
                   WHERE b.created_at BETWEEN ? AND ? AND b.status != 'cancelled' $branch_filter 
                   GROUP BY bi.product_id ORDER BY revenue DESC LIMIT 10";
$stmt = $pdo->prepare($query_products);
$stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$product_stats = $stmt->fetchAll();

// 4. Category-wise Performance
$query_cats = "SELECT c.name as category, SUM(bi.total) as revenue,
               SUM((bi.selling_price - p.purchase_price) * bi.quantity) as profit
               FROM bill_items bi 
               JOIN bills b ON bi.bill_id = b.id 
               JOIN products p ON bi.product_id = p.id 
               JOIN categories c ON p.category_id = c.id
               WHERE b.created_at BETWEEN ? AND ? AND b.status != 'cancelled' $branch_filter 
               GROUP BY p.category_id ORDER BY revenue DESC";
$stmt = $pdo->prepare($query_cats);
$stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$category_stats = $stmt->fetchAll();

// Handle Export CSV
if (isset($_POST['export'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
    } else {
        $filename = "sales_report_{$date_from}_to_{$date_to}.csv";
        $headers = ['Product', 'SKU', 'Qty Sold', 'Revenue', 'Profit', 'Margin %'];
        $export_data = [];
        foreach ($product_stats as $ps) {
            $margin = $ps['revenue'] > 0 ? round(($ps['profit'] / $ps['revenue']) * 100, 2) : 0;
            $export_data[] = [
                $ps['name'],
                $ps['sku'],
                $ps['qty_sold'],
                $ps['revenue'],
                $ps['profit'],
                $margin . '%'
            ];
        }
        exportCSV($filename, $headers, $export_data);
    }
}

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header flex-wrap gap-3">
    <h1>Sales Performance</h1>
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
        <a href="?range=yearly" class="btn btn-outline btn-sm <?php echo $range === 'yearly' ? 'active' : ''; ?>">Yearly</a>
        <a href="?range=financial_year" class="btn btn-outline btn-sm <?php echo $range === 'financial_year' ? 'active' : ''; ?>">Financial Year</a>
    </div>

    <form action="" method="GET" class="d-flex gap-2 flex-grow-1">
        <input type="hidden" name="range" value="custom">
        <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>" style="max-width: 150px;">
        <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>" style="max-width: 150px;">
        
        <?php if ($isAdmin): ?>
            <select name="branch_id" class="form-control" style="max-width: 150px;">
                <option value="all">All Branches</option>
                <?php
                $branches = $pdo->query("SELECT id, name FROM branches WHERE status='active'")->fetchAll();
                foreach ($branches as $b) {
                    echo "<option value='{$b['id']}' " . ($selected_branch == $b['id'] ? 'selected' : '') . ">{$b['name']}</option>";
                }
                ?>
            </select>
        <?php endif; ?>
        
        <button type="submit" class="btn btn-primary btn-icon"><i class="fas fa-filter"></i></button>
    </form>
</div>

<div class="stat-grid mb-4">
    <div class="stat-card blue">
        <div class="stat-value"><?php echo formatCurrency($summary['total_revenue']); ?></div>
        <div class="stat-label">Total Revenue</div>
    </div>
    <div class="stat-card green">
        <div class="stat-value"><?php echo $summary['total_bills']; ?></div>
        <div class="stat-label">Total Bills</div>
    </div>
    <div class="stat-card purple">
        <div class="stat-value"><?php echo formatCurrency($summary['avg_bill']); ?></div>
        <div class="stat-label">Avg Bill Value</div>
    </div>
    <div class="stat-card orange">
        <div class="stat-value"><?php echo $summary['total_items'] ?: 0; ?></div>
        <div class="stat-label">Items Sold</div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><h3 class="card-title">Daily Revenue Trend</h3></div>
    <div style="height: 300px;">
        <canvas id="revenueTrendChart"></canvas>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="table-wrapper">
            <div class="card-header border-0 mb-3"><h3 class="card-title">Top 10 Products by Revenue</h3></div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Qty Sold</th>
                        <th>Revenue</th>
                        <th>Profit</th>
                        <th>Margin%</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($product_stats as $ps): 
                        $margin = $ps['revenue'] > 0 ? ($ps['profit'] / $ps['revenue']) * 100 : 0;
                    ?>
                        <tr>
                            <td>
                                <div class="fw-600"><?php echo sanitize($ps['name']); ?></div>
                                <div class="fs-11 text-muted">SKU: <?php echo $ps['sku']; ?></div>
                            </td>
                            <td><?php echo $ps['qty_sold']; ?></td>
                            <td class="fw-600"><?php echo formatCurrency($ps['revenue']); ?></td>
                            <td class="text-success"><?php echo formatCurrency($ps['profit']); ?></td>
                            <td>
                                <span class="badge-pill <?php echo $margin > 20 ? 'badge-success' : ($margin > 10 ? 'badge-info' : 'badge-warning'); ?>">
                                    <?php echo round($margin, 1); ?>%
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-md-4">
        <div class="table-wrapper h-100">
            <div class="card-header border-0 mb-3"><h3 class="card-title">Revenue by Category</h3></div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th class="text-end">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($category_stats as $cs): ?>
                        <tr>
                            <td class="fw-500"><?php echo sanitize($cs['category']); ?></td>
                            <td class="text-end fw-600"><?php echo formatCurrency($cs['revenue']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('revenueTrendChart').getContext('2d');
    const labels = <?php echo json_encode(array_column($trend_data, 'sale_date')); ?>;
    const values = <?php echo json_encode(array_column($trend_data, 'revenue')); ?>;
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels.map(d => new Date(d).toLocaleDateString('en-IN', {day:'2-digit', month:'short'})),
            datasets: [{
                label: 'Revenue (â‚¹)',
                data: values,
                borderColor: '#4d78ff',
                backgroundColor: 'rgba(77, 120, 255, 0.1)',
                fill: true,
                tension: 0.3,
                pointRadius: 4,
                pointBackgroundColor: '#4d78ff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#94a3b8' } },
                y: { grid: { color: 'rgba(255, 255, 255, 0.05)' }, ticks: { color: '#94a3b8' } }
            }
        }
    });
});
</script>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>

