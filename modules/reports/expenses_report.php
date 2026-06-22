<?php
/**
 * Expense Report
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle = 'Expense Report';
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><a href="index.php">Reports</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Expense Report</span>';

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

$category_filter = sanitize($_GET['category'] ?? '');
$selected_branch = $isAdmin ? (int)($_GET['branch_id'] ?? 0) : (int)$branch_id;

$valid_categories = ['rent','salary','electricity','maintenance','transportation','internet','miscellaneous'];

// â”€â”€ Build WHERE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$params = [$date_from, $date_to];
$where  = ["e.expense_date BETWEEN ? AND ?"];

if ($isAdmin && $selected_branch > 0) {
    $where[] = "e.branch_id = ?"; $params[] = $selected_branch;
} elseif (!$isAdmin) {
    $where[] = "e.branch_id = ?"; $params[] = $branch_id;
}

if ($category_filter && in_array($category_filter, $valid_categories)) {
    $where[] = "e.category = ?"; $params[] = $category_filter;
}

$where_sql = implode(' AND ', $where);

// â”€â”€ CSV Export â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
    } else {
        $stmt = $pdo->prepare("SELECT e.expense_date, e.category, e.amount, e.description,
            b.name as branch_name, u.name as created_by_name
            FROM expenses e
            LEFT JOIN users u ON e.created_by = u.id
            LEFT JOIN branches b ON e.branch_id = b.id
            WHERE $where_sql ORDER BY e.expense_date DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $export = [];
        foreach ($rows as $r) {
            $export[] = [
                $r['expense_date'], ucfirst($r['category']),
                number_format($r['amount'], 2), $r['description'] ?: 'â€”',
                $r['branch_name'] ?: 'â€”', $r['created_by_name'] ?: 'â€”',
            ];
        }
        exportCSV("expenses_{$date_from}_to_{$date_to}.csv",
            ['Date', 'Category', 'Amount', 'Description', 'Branch', 'Added By'],
            $export);
    }
}

// â”€â”€ Stats â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$stmt = $pdo->prepare("SELECT SUM(e.amount) as total, COUNT(*) as count,
    MAX(e.amount) as largest,
    DATEDIFF(MAX(e.expense_date), MIN(e.expense_date)) + 1 as days
    FROM expenses e WHERE $where_sql");
$stmt->execute($params);
$stats = $stmt->fetch();

$days     = max((int)($stats['days'] ?? 1), 1);
$daily_avg = ($stats['total'] ?? 0) / $days;

// â”€â”€ Category breakdown â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$cat_params = $params;
$stmt = $pdo->prepare("SELECT e.category, SUM(e.amount) as total, COUNT(*) as count
    FROM expenses e WHERE $where_sql
    GROUP BY e.category ORDER BY total DESC");
$stmt->execute($cat_params);
$category_data = $stmt->fetchAll();

// â”€â”€ Find largest category â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$largest_cat = !empty($category_data) ? ucfirst($category_data[0]['category']) : 'â€”';

// â”€â”€ Detail table â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$stmt = $pdo->prepare("SELECT e.*, u.name as created_by_name, b.name as branch_name
    FROM expenses e
    LEFT JOIN users u ON e.created_by = u.id
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE $where_sql ORDER BY e.expense_date DESC");
$stmt->execute($params);
$expenses = $stmt->fetchAll();


$cat_badge = [
    'rent'           => 'badge-danger',
    'salary'         => 'badge-success',
    'electricity'    => 'badge-warning',
    'maintenance'    => 'badge-warning',
    'transportation' => 'badge-success',
    'internet'       => 'badge-success',
    'miscellaneous'  => 'badge-warning',
];

$csrf_token = generateCSRFToken();
include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Expense Report</h1>
        <div class="sub">Expenses grouped by category with totals and line-item detail</div>
    </div>
    <div class="page-header-actions">
        <a href="index.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> All Reports</a>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="export" value="1">
            <button type="submit" class="btn btn-outline btn-sm"><i class="fas fa-download"></i> Export CSV</button>
        </form>
    </div>
</div>

<!-- Filters -->
<div class="table-wrapper" style="padding:16px 20px;margin-bottom:20px;">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <div class="btn-group">
            <?php foreach (['today'=>'Today','this_week'=>'This Week','this_month'=>'This Month','custom'=>'Custom'] as $k=>$v): ?>
                <a href="?range=<?php echo $k; ?>"
                   class="btn btn-sm <?php echo $range===$k ? 'btn-primary' : 'btn-outline'; ?>"><?php echo $v; ?></a>
            <?php endforeach; ?>
        </div>
        <?php if ($range === 'custom'): ?>
            <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>" style="width:150px;">
            <input type="date" name="date_to"   class="form-control" value="<?php echo $date_to;   ?>" style="width:150px;">
            <input type="hidden" name="range" value="custom">
        <?php endif; ?>
        <select name="category" class="form-control" style="width:170px;">
            <option value="">All Categories</option>
            <?php foreach ($valid_categories as $cat): ?>
                <option value="<?php echo $cat; ?>" <?php echo $category_filter===$cat ? 'selected':''; ?>><?php echo ucfirst($cat); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline"><i class="fas fa-filter"></i> Apply</button>
        <a href="expenses_report.php" class="btn btn-ghost">Clear</a>
    </form>
</div>

<!-- Stat Cards -->
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr);display:grid;gap:16px;margin-bottom:24px;">
    <div class="stat-card red">
        <div class="stat-value"><?php echo formatCurrency($stats['total'] ?? 0); ?></div>
        <div class="stat-label">Total Expenses</div>
    </div>
    <div class="stat-card orange">
        <div class="stat-value"><?php echo $largest_cat; ?></div>
        <div class="stat-label">Largest Category</div>
    </div>
    <div class="stat-card blue">
        <div class="stat-value"><?php echo number_format($stats['count'] ?? 0); ?></div>
        <div class="stat-label">Expense Entries</div>
    </div>
    <div class="stat-card green">
        <div class="stat-value"><?php echo formatCurrency($daily_avg); ?></div>
        <div class="stat-label">Daily Average</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:320px 1fr;gap:20px;">
    <!-- Category breakdown -->
    <div class="table-wrapper">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-chart-pie" style="color:var(--primary);"></i> By Category</h3>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th class="text-end">Count</th>
                    <th class="text-end">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($category_data)): ?>
                    <tr><td colspan="3"><div class="empty-state"><i class="fas fa-receipt empty-icon"></i><h3>No data</h3></div></td></tr>
                <?php else: ?>
                    <?php foreach ($category_data as $c): ?>
                    <tr>
                        <td>
                            <span class="badge-pill <?php echo $cat_badge[$c['category']] ?? 'badge-warning'; ?>">
                                <?php echo ucfirst($c['category']); ?>
                            </span>
                        </td>
                        <td class="text-end fs-13"><?php echo $c['count']; ?></td>
                        <td class="text-end fw-600"><?php echo formatCurrency($c['total']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Detail table -->
    <div class="table-wrapper">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-list" style="color:var(--on-surface-muted);"></i> All Expenses</h3>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th class="text-end">Amount</th>
                    <?php if ($isAdmin): ?><th>Branch</th><?php endif; ?>
                    <th>Added By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($expenses)): ?>
                    <tr><td colspan="<?php echo $isAdmin ? 6 : 5; ?>">
                        <div class="empty-state">
                            <i class="fas fa-receipt empty-icon"></i>
                            <h3>No expenses in this period</h3>
                        </div>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($expenses as $e): ?>
                    <tr>
                        <td class="fs-12 text-muted"><?php echo formatDate($e['expense_date']); ?></td>
                        <td><span class="badge-pill <?php echo $cat_badge[$e['category']] ?? 'badge-warning'; ?>"><?php echo ucfirst($e['category']); ?></span></td>
                        <td class="fs-13"><?php echo sanitize($e['description'] ?: 'â€”'); ?></td>
                        <td class="text-end fw-600" style="color:var(--danger);"><?php echo formatCurrency($e['amount']); ?></td>
                        <?php if ($isAdmin): ?><td class="fs-12 text-muted"><?php echo sanitize($e['branch_name'] ?: 'â€”'); ?></td><?php endif; ?>
                        <td class="fs-12"><?php echo sanitize($e['created_by_name'] ?: 'â€”'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>

