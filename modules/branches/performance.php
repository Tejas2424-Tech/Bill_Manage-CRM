<?php
/**
 * Branch Performance Comparison - Superadmin Only
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireRole('superadmin');

$pageTitle = 'Branch Performance';
$breadcrumb = '<a href="' . BASE_URL . '/modules/branches/index.php">Branches</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Performance</span>';

// Fetch Branch Comparison Stats
$query = "SELECT b.id, b.name, b.code,
          (SELECT COALESCE(SUM(total_amount), 0) FROM bills WHERE branch_id = b.id AND DATE(created_at) = CURDATE() AND status != 'cancelled') as today_sales,
          (SELECT COALESCE(SUM(total_amount), 0) FROM bills WHERE branch_id = b.id AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status != 'cancelled') as month_sales,
          (SELECT COALESCE(SUM((bi.selling_price - p.purchase_price) * bi.quantity), 0) 
           FROM bill_items bi 
           JOIN bills bl ON bi.bill_id = bl.id 
           JOIN products p ON bi.product_id = p.id 
           WHERE bl.branch_id = b.id AND MONTH(bl.created_at) = MONTH(CURDATE()) AND YEAR(bl.created_at) = YEAR(CURDATE()) AND bl.status != 'cancelled') as month_profit,
          (SELECT COUNT(*) FROM products WHERE branch_id = b.id AND quantity <= alert_quantity AND status = 'active') as low_stock,
          (SELECT COUNT(*) FROM users WHERE branch_id = b.id AND status = 'active') as staff_count
          FROM branches b 
          WHERE b.status = 'active'
          ORDER BY month_sales DESC";
$comparison = $pdo->query($query)->fetchAll();

// Chart Data: Monthly Sales per Branch for last 6 months
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $months[] = [
        'm' => date('m', strtotime("-$i months")),
        'y' => date('Y', strtotime("-$i months")),
        'label' => date('M y', strtotime("-$i months"))
    ];
}

$chart_data = [];
foreach ($comparison as $b) {
    $branch_sales = [];
    foreach ($months as $m) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM bills 
                              WHERE branch_id = ? AND MONTH(created_at) = ? AND YEAR(created_at) = ? AND status != 'cancelled'");
        $stmt->execute([$b['id'], $m['m'], $m['y']]);
        $branch_sales[] = (float)$stmt->fetchColumn();
    }
    $chart_data[] = [
        'label' => $b['name'],
        'data' => $branch_sales
    ];
}

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>Branch Performance Comparison</h1>
    <a href="index.php" class="btn btn-outline btn-sm">Back to Branches</a>
</div>

<div class="card mb-5">
    <div class="card-header"><h3 class="card-title">Monthly Sales Trend (Last 6 Months)</h3></div>
    <div class="p-4" style="height: 350px;">
        <canvas id="branchComparisonChart"></canvas>
    </div>
</div>

<div class="table-wrapper">
    <div class="card-header border-0 mb-3">
        <h3 class="card-title">Branch-wise Performance Summary (Current Month)</h3>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Branch</th>
                <th>Today Sales</th>
                <th>Month Sales</th>
                <th>Month Profit</th>
                <th>Low Stock</th>
                <th>Staff</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($comparison as $row): ?>
                <tr>
                    <td>
                        <div class="fw-600"><?php echo sanitize($row['name']); ?></div>
                        <div class="fs-10 text-muted"><?php echo $row['code']; ?></div>
                    </td>
                    <td class="fw-600 text-primary"><?php echo formatCurrency($row['today_sales']); ?></td>
                    <td class="fw-700"><?php echo formatCurrency($row['month_sales']); ?></td>
                    <td class="text-success"><?php echo formatCurrency($row['month_profit']); ?></td>
                    <td>
                        <?php if ($row['low_stock'] > 0): ?>
                            <span class="badge-pill badge-danger"><?php echo $row['low_stock']; ?> Alerts</span>
                        <?php else: ?>
                            <span class="text-muted fs-12">None</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $row['staff_count']; ?></td>
                    <td class="text-end">
                        <a href="edit.php?id=<?php echo $row['id']; ?>" class="btn btn-ghost btn-icon btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('branchComparisonChart').getContext('2d');
    const labels = <?php echo json_encode(array_column($months, 'label')); ?>;
    const datasets = <?php echo json_encode($chart_data); ?>;
    
    // Assign distinct colors
    const colors = ['#4d78ff', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4'];
    datasets.forEach((ds, i) => {
        ds.borderColor = colors[i % colors.length];
        ds.backgroundColor = colors[i % colors.length] + '22'; // 13% opacity
        ds.borderWidth = 2;
        ds.tension = 0.3;
        ds.fill = true;
    });

    new Chart(ctx, {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { 
                    position: 'top',
                    labels: { color: '#94a3b8', font: { size: 11 } }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#94a3b8' } },
                y: { 
                    grid: { color: 'rgba(255, 255, 255, 0.05)' }, 
                    ticks: { 
                        color: '#94a3b8',
                        callback: function(value) { return '₹' + value.toLocaleString(); }
                    } 
                }
            }
        }
    });
});
</script>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
