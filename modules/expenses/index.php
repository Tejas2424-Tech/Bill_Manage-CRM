<?php
/**
 * Expense Management & Profit Analysis
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle = 'Expenses';
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Expenses</span>';

$branch_id = $_SESSION['branch_id'];
$isAdmin = isAdmin();
$branch_filter = $isAdmin ? "" : " AND branch_id = " . (int)$branch_id;
$branch_filter_expenses = $isAdmin ? "" : " AND e.branch_id = " . (int)$branch_id;
$branch_filter_bills = $isAdmin ? "" : " AND b.branch_id = " . (int)$branch_id;

// Date range filters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// 1. Total Expenses for period
$stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses e WHERE e.expense_date BETWEEN ? AND ? " . (isAdmin() ? "" : " AND e.branch_id = ?"));
$exec_params = [$date_from, $date_to];
if (!isAdmin()) $exec_params[] = $branch_id;
$stmt->execute($exec_params);
$total_expenses = $stmt->fetchColumn() ?: 0;

// 2. Gross Profit for period (Selling Price - Purchase Price)
$query_gross = "SELECT SUM((bi.selling_price - p.purchase_price) * bi.quantity) 
                FROM bill_items bi 
                JOIN bills b ON bi.bill_id = b.id 
                JOIN products p ON bi.product_id = p.id 
                WHERE b.created_at BETWEEN ? AND ? AND b.status != 'cancelled' " . (isAdmin() ? "" : " AND b.branch_id = ?");
$stmt = $pdo->prepare($query_gross);
$exec_params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];
if (!isAdmin()) $exec_params[] = $branch_id;
$stmt->execute($exec_params);
$gross_profit = $stmt->fetchColumn() ?: 0;

// 3. Net Profit
$net_profit = $gross_profit - $total_expenses;

// 4. Expenses by Category (for Chart)
$stmt = $pdo->prepare("SELECT category, SUM(amount) as total 
                     FROM expenses e 
                     WHERE e.expense_date BETWEEN ? AND ? " . (isAdmin() ? "" : " AND e.branch_id = ?") . " 
                     GROUP BY category");
$exec_params = [$date_from, $date_to];
if (!isAdmin()) $exec_params[] = $branch_id;
$stmt->execute($exec_params);
$by_category = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Last Month Comparison
$last_month_start = date('Y-m-d', strtotime('first day of last month'));
$last_month_end = date('Y-m-d', strtotime('last day of last month'));
$stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses e WHERE e.expense_date BETWEEN ? AND ? " . (isAdmin() ? "" : " AND e.branch_id = ?"));
$exec_params = [$last_month_start, $last_month_end];
if (!isAdmin()) $exec_params[] = $branch_id;
$stmt->execute($exec_params);
$last_month_expenses = $stmt->fetchColumn() ?: 0;

// 6. Fetch Expenses Table
$stmt = $pdo->prepare("SELECT e.*, u.name as creator_name 
                     FROM expenses e 
                     JOIN users u ON e.created_by = u.id 
                     WHERE e.expense_date BETWEEN ? AND ? " . (isAdmin() ? "" : " AND e.branch_id = ?") . " 
                     ORDER BY e.expense_date DESC, e.created_at DESC");
$exec_params = [$date_from, $date_to];
if (!isAdmin()) $exec_params[] = $branch_id;
$stmt->execute($exec_params);
$expenses_list = $stmt->fetchAll();

$csrf_token = generateCSRFToken();

// Category Icons Mapping
$cat_icons = [
    'rent' => ['icon' => 'fa-building', 'color' => 'blue'],
    'salary' => ['icon' => 'fa-users', 'color' => 'purple'],
    'electricity' => ['icon' => 'fa-bolt', 'color' => 'warning'],
    'maintenance' => ['icon' => 'fa-wrench', 'color' => 'secondary'],
    'transportation' => ['icon' => 'fa-truck', 'color' => 'info'],
    'internet' => ['icon' => 'fa-wifi', 'color' => 'accent'],
    'miscellaneous' => ['icon' => 'fa-ellipsis', 'color' => 'dark']
];

include_once __DIR__ . '/../../includes/header.php';
?>

<style>
    .profit-formula {
        display: flex;
        align-items: center;
        gap: 16px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 20px 24px;
        margin-bottom: 24px;
        box-shadow: var(--shadow-sm);
    }
    .profit-block { text-align: center; flex: 1; }
    .profit-block .value { font-size: 20px; font-weight: 700; margin-bottom: 2px; }
    .profit-block .label { font-size: 11px; color: var(--on-surface-subtle); text-transform: uppercase; letter-spacing: 0.5px; }
    .profit-op { font-size: 24px; color: var(--on-surface-subtle); font-weight: 300; }
    .net-profit-val.positive { color: #10b981; }
    .net-profit-val.negative { color: #ef4444; }
    
    .expense-chart-container {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 20px;
        height: 100%;
    }
</style>

<div class="page-header">
    <h1>Expense Management</h1>
    <div class="d-flex gap-2">
        <a href="add.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add Expense
        </a>
    </div>
</div>

<div class="table-toolbar mb-4">
    <form action="" method="GET" class="d-flex gap-2 flex-wrap">
        <div class="form-group mb-0">
            <label class="fs-11 text-muted mb-1 d-block">From Date</label>
            <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
        </div>
        <div class="form-group mb-0">
            <label class="fs-11 text-muted mb-1 d-block">To Date</label>
            <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
        </div>
        <div class="d-flex align-end">
            <button type="submit" class="btn btn-outline btn-icon" title="Filter"><i class="fas fa-filter"></i></button>
            <a href="index.php" class="btn btn-ghost btn-icon ms-1" title="Reset"><i class="fas fa-sync-alt"></i></a>
        </div>
    </form>
</div>

<!-- Net Profit Formula Card -->
<div class="profit-formula">
    <div class="profit-block">
        <div class="value text-primary"><?php echo formatCurrency($gross_profit); ?></div>
        <div class="label">Gross Profit</div>
    </div>
    <div class="profit-op">âˆ’</div>
    <div class="profit-block">
        <div class="value text-danger"><?php echo formatCurrency($total_expenses); ?></div>
        <div class="label">Total Expenses</div>
    </div>
    <div class="profit-op">=</div>
    <div class="profit-block">
        <div class="value net-profit-val <?php echo $net_profit >= 0 ? 'positive' : 'negative'; ?>">
            <?php echo formatCurrency($net_profit); ?>
        </div>
        <div class="label">Net Profit / Loss</div>
    </div>
</div>

<div class="row mb-5">
    <div class="col-md-7">
        <div class="table-wrapper h-100">
            <div class="card-header border-0 mb-3">
                <h3 class="card-title">Recent Expenses</h3>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($expenses_list)): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">No expenses recorded for this period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($expenses_list as $e): 
                            $cat = $cat_icons[$e['category']] ?? $cat_icons['miscellaneous'];
                        ?>
                            <tr>
                                <td class="fs-12"><?php echo formatDate($e['expense_date']); ?></td>
                                <td>
                                    <span class="badge-pill badge-<?php echo $cat['color']; ?> d-inline-flex align-center gap-1">
                                        <i class="fas <?php echo $cat['icon']; ?> fs-10"></i>
                                        <?php echo ucfirst($e['category']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fs-13 fw-500"><?php echo sanitize($e['description'] ?: 'No description'); ?></div>
                                    <div class="fs-10 text-muted">By: <?php echo sanitize($e['creator_name']); ?></div>
                                </td>
                                <td class="fw-700 text-danger"><?php echo formatCurrency($e['amount']); ?></td>
                                <td class="text-end">
                                    <div class="d-flex justify-end gap-1">
                                        <a href="edit.php?id=<?php echo $e['id']; ?>" class="btn btn-ghost btn-icon btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                                        <form action="delete.php" method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="id" value="<?php echo $e['id']; ?>">
                                            <button type="submit" class="btn btn-ghost btn-icon btn-sm text-danger" data-confirm="Delete this expense record?" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-md-5">
        <div class="expense-chart-container">
            <h3 class="card-title mb-4">Expenses by Category</h3>
            <?php if (empty($by_category)): ?>
                <div class="text-center py-5 text-muted">No data for chart</div>
            <?php else: ?>
                <canvas id="expenseChart"></canvas>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($by_category)): ?>
    const ctx = document.getElementById('expenseChart').getContext('2d');
    const data = <?php echo json_encode($by_category); ?>;
    
    new Chart(ctx, {
        type: 'horizontalBar',
        data: {
            labels: data.map(item => item.category.charAt(0).toUpperCase() + item.category.slice(1)),
            datasets: [{
                label: 'Amount (â‚¹)',
                data: data.map(item => item.total),
                backgroundColor: 'rgba(52, 152, 219, 0.6)',
                borderColor: 'rgba(52, 152, 219, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            legend: { display: false },
            scales: {
                xAxes: [{
                    ticks: { beginAtZero: true, fontColor: '#94a3b8' },
                    gridLines: { color: 'rgba(255, 255, 255, 0.05)' }
                }],
                yAxes: [{
                    ticks: { fontColor: '#94a3b8' },
                    gridLines: { display: false }
                }]
            }
        }
    });
    <?php endif; ?>
});
</script>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>

