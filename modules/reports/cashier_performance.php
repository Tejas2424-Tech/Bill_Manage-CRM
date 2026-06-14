<?php
/**
 * Cashier Performance Report
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle = 'Cashier Performance';
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><a href="index.php">Reports</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Cashier Performance</span>';

$branch_id = $_SESSION['branch_id'];
$isAdmin   = isAdmin();

// â”€â”€ Date range â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$range = $_GET['range'] ?? 'this_month';
$today = date('Y-m-d');
switch ($range) {
    case 'today':     $date_from = $today; $date_to = $today; break;
    case 'this_week': $date_from = date('Y-m-d', strtotime('monday this week')); $date_to = $today; break;
    case 'custom':
        $date_from = sanitize($_GET['date_from'] ?? date('Y-m-01'));
        $date_to   = sanitize($_GET['date_to']   ?? $today);
        break;
    default:
        $range = 'this_month';
        $date_from = date('Y-m-01');
        $date_to   = $today;
}

$selected_branch = $isAdmin ? (int)($_GET['branch_id'] ?? 0) : (int)$branch_id;

// â”€â”€ Build WHERE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];
$where  = ["b.status != 'cancelled'", "b.created_at BETWEEN ? AND ?"];

if ($isAdmin && $selected_branch > 0) {
    $where[] = "b.branch_id = ?"; $params[] = $selected_branch;
} elseif (!$isAdmin) {
    $where[] = "b.branch_id = ?"; $params[] = $branch_id;
}

$where_sql = implode(' AND ', $where);

// â”€â”€ Stats â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$stmt = $pdo->prepare("SELECT COUNT(*) as total_bills, SUM(total_amount) as total_revenue,
    AVG(total_amount) as avg_bill, COUNT(DISTINCT created_by) as active_cashiers
    FROM bills b WHERE $where_sql");
$stmt->execute($params);
$stats = $stmt->fetch();

// â”€â”€ Per-staff summary â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$stmt = $pdo->prepare("SELECT u.id, u.name, u.role,
    COUNT(b.id) as bill_count,
    SUM(b.total_amount) as total_sales,
    AVG(b.total_amount) as avg_bill,
    MIN(b.created_at) as first_bill,
    MAX(b.created_at) as last_bill
    FROM bills b
    JOIN users u ON b.created_by = u.id
    WHERE $where_sql
    GROUP BY b.created_by
    ORDER BY total_sales DESC");
$stmt->execute($params);
$staff_data = $stmt->fetchAll();

// â”€â”€ Daily trend â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$trend_params = $params;
$stmt = $pdo->prepare("SELECT DATE(b.created_at) as sale_date, SUM(b.total_amount) as revenue, COUNT(*) as bills
    FROM bills b WHERE $where_sql
    GROUP BY DATE(b.created_at) ORDER BY sale_date ASC");
$stmt->execute($trend_params);
$trend_data = $stmt->fetchAll();

$branches = $isAdmin ? $pdo->query("SELECT id, name FROM branches ORDER BY name ASC")->fetchAll() : [];

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Cashier Performance</h1>
        <div class="sub">Per-staff bill count, sales and average bill value</div>
    </div>
    <div class="page-header-actions">
        <a href="index.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> All Reports</a>
    </div>
</div>

<!-- Filters -->
<div class="table-wrapper" style="padding:16px 20px;margin-bottom:20px;">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <div class="btn-group">
            <?php foreach (['today'=>'Today','this_week'=>'This Week','this_month'=>'This Month','custom'=>'Custom'] as $k=>$v): ?>
                <a href="?range=<?php echo $k; ?><?php echo $isAdmin && $selected_branch ? '&branch_id='.$selected_branch : ''; ?>"
                   class="btn btn-sm <?php echo $range===$k ? 'btn-primary' : 'btn-outline'; ?>"><?php echo $v; ?></a>
            <?php endforeach; ?>
        </div>
        <?php if ($range === 'custom'): ?>
            <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>" style="width:150px;">
            <input type="date" name="date_to"   class="form-control" value="<?php echo $date_to;   ?>" style="width:150px;">
            <input type="hidden" name="range" value="custom">
        <?php endif; ?>
        <?php if ($isAdmin): ?>
            <select name="branch_id" class="form-control" style="width:160px;">
                <option value="0">All Branches</option>
                <?php foreach ($branches as $br): ?>
                    <option value="<?php echo $br['id']; ?>" <?php echo $selected_branch==$br['id'] ? 'selected':''; ?>><?php echo sanitize($br['name']); ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <?php if ($range === 'custom'): ?>
            <button type="submit" class="btn btn-outline"><i class="fas fa-filter"></i> Apply</button>
        <?php endif; ?>
    </form>
</div>

<!-- Stat Cards -->
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr);display:grid;gap:16px;margin-bottom:24px;">
    <div class="stat-card blue">
        <div class="stat-value"><?php echo number_format($stats['total_bills'] ?? 0); ?></div>
        <div class="stat-label">Total Bills</div>
    </div>
    <div class="stat-card green">
        <div class="stat-value"><?php echo formatCurrency($stats['total_revenue'] ?? 0); ?></div>
        <div class="stat-label">Total Revenue</div>
    </div>
    <div class="stat-card orange">
        <div class="stat-value"><?php echo formatCurrency($stats['avg_bill'] ?? 0); ?></div>
        <div class="stat-label">Avg Bill Value</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['active_cashiers'] ?? 0); ?></div>
        <div class="stat-label">Active Cashiers</div>
    </div>
</div>

<!-- Chart + Staff table side by side -->
<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:20px;margin-bottom:24px;">
    <!-- Daily trend chart -->
    <div class="table-wrapper" style="padding:20px;">
        <div class="card-header" style="padding:0 0 16px 0;margin-bottom:16px;border-bottom:1px solid var(--border);">
            <h3 class="card-title"><i class="fas fa-chart-line" style="color:var(--secondary);"></i> Daily Sales Trend</h3>
        </div>
        <?php if (empty($trend_data)): ?>
            <div class="empty-state" style="padding:32px;">
                <i class="fas fa-chart-line empty-icon"></i><h3>No data</h3>
            </div>
        <?php else: ?>
            <canvas id="trendChart" style="max-height:260px;"></canvas>
        <?php endif; ?>
    </div>

    <!-- Staff performance table -->
    <div class="table-wrapper">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-cash-register" style="color:var(--primary);"></i> Staff Performance</h3>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Staff</th>
                    <th class="text-end">Bills</th>
                    <th class="text-end">Total Sales</th>
                    <th class="text-end">Avg Bill</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staff_data)): ?>
                    <tr><td colspan="4"><div class="empty-state"><i class="fas fa-users empty-icon"></i><h3>No data</h3></div></td></tr>
                <?php else: ?>
                    <?php foreach ($staff_data as $s): ?>
                    <tr>
                        <td>
                            <div class="fw-600 fs-13"><?php echo sanitize($s['name']); ?></div>
                            <div class="fs-11 text-muted"><?php echo ucfirst($s['role']); ?></div>
                        </td>
                        <td class="text-end fw-600"><?php echo number_format($s['bill_count']); ?></td>
                        <td class="text-end fw-600" style="color:var(--success);"><?php echo formatCurrency($s['total_sales']); ?></td>
                        <td class="text-end fs-13"><?php echo formatCurrency($s['avg_bill']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Full detail with first/last bill dates -->
<?php if (!empty($staff_data)): ?>
<div class="table-wrapper">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-table" style="color:var(--on-surface-muted);"></i> Detailed Breakdown</h3>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Staff Member</th>
                <th>Role</th>
                <th class="text-end">Bills</th>
                <th class="text-end">Total Sales</th>
                <th class="text-end">Avg Bill</th>
                <th>First Bill</th>
                <th>Last Bill</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($staff_data as $i => $s): ?>
            <tr>
                <td class="text-muted fs-12"><?php echo $i + 1; ?></td>
                <td class="fw-600"><?php echo sanitize($s['name']); ?></td>
                <td><span class="badge-pill badge-success"><?php echo ucfirst($s['role']); ?></span></td>
                <td class="text-end fw-600"><?php echo number_format($s['bill_count']); ?></td>
                <td class="text-end fw-600" style="color:var(--success);"><?php echo formatCurrency($s['total_sales']); ?></td>
                <td class="text-end"><?php echo formatCurrency($s['avg_bill']); ?></td>
                <td class="fs-12 text-muted"><?php echo formatDate($s['first_bill']); ?></td>
                <td class="fs-12 text-muted"><?php echo formatDate($s['last_bill']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if (!empty($trend_data)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const labels = <?php echo json_encode(array_map(function($d){ return date('d M', strtotime($d['sale_date'])); }, $trend_data)); ?>;
    const values = <?php echo json_encode(array_column($trend_data, 'revenue')); ?>;
    new Chart(document.getElementById('trendChart').getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Revenue (<?php echo CURRENCY; ?>)',
                data: values,
                borderColor: '#6366F1',
                backgroundColor: 'rgba(99,102,241,0.1)',
                fill: true, tension: 0.3, pointRadius: 3
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: '#f1f5f9' },
                     ticks: { callback: v => '<?php echo CURRENCY; ?>' + v.toLocaleString() } },
                x: { grid: { display: false } }
            }
        }
    });
});
</script>
<?php endif; ?>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>

