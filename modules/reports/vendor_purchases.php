<?php
/**
 * Purchase / Vendor Report
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle = 'Purchase Report';
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><a href="index.php">Reports</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Purchase Report</span>';

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

$vendor_filter   = (int)($_GET['vendor_id'] ?? 0);
$selected_branch = $isAdmin ? (int)($_GET['branch_id'] ?? 0) : (int)$branch_id;

// â”€â”€ Build WHERE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$params = [$date_from, $date_to];
$where  = ["p.purchase_date BETWEEN ? AND ?"];

if ($isAdmin && $selected_branch > 0) {
    $where[] = "p.branch_id = ?"; $params[] = $selected_branch;
} elseif (!$isAdmin) {
    $where[] = "p.branch_id = ?"; $params[] = $branch_id;
}

if ($vendor_filter > 0) {
    $where[] = "p.vendor_id = ?"; $params[] = $vendor_filter;
}

$where_sql = implode(' AND ', $where);

// â”€â”€ CSV Export â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
    } else {
        $stmt = $pdo->prepare("SELECT p.purchase_date, p.invoice_number, v.name as vendor_name,
            p.total_amount, p.note, u.name as created_by_name
            FROM purchases p
            LEFT JOIN vendors v ON p.vendor_id = v.id
            LEFT JOIN users u ON p.created_by = u.id
            WHERE $where_sql ORDER BY p.purchase_date DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $export = [];
        foreach ($rows as $r) {
            $export[] = [
                $r['purchase_date'], $r['invoice_number'] ?: 'â€”',
                $r['vendor_name'] ?: 'Walk-in',
                number_format($r['total_amount'], 2),
                $r['note'] ?: 'â€”', $r['created_by_name'] ?: 'â€”',
            ];
        }
        exportCSV("purchases_{$date_from}_to_{$date_to}.csv",
            ['Date', 'Invoice #', 'Vendor', 'Amount', 'Note', 'Added By'],
            $export);
    }
}

// â”€â”€ Stats â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$stmt = $pdo->prepare("SELECT COUNT(*) as total_orders, SUM(p.total_amount) as total_spend,
    AVG(p.total_amount) as avg_order,
    COUNT(DISTINCT p.vendor_id) as vendor_count
    FROM purchases p WHERE $where_sql");
$stmt->execute($params);
$stats = $stmt->fetch();

// â”€â”€ Top 5 vendors â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$stmt = $pdo->prepare("SELECT v.name, COUNT(p.id) as order_count, SUM(p.total_amount) as total_spend
    FROM purchases p
    JOIN vendors v ON p.vendor_id = v.id
    WHERE $where_sql
    GROUP BY p.vendor_id ORDER BY total_spend DESC LIMIT 5");
$stmt->execute($params);
$top_vendors = $stmt->fetchAll();

// â”€â”€ All purchases â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$stmt = $pdo->prepare("SELECT p.*, v.name as vendor_name, u.name as created_by_name
    FROM purchases p
    LEFT JOIN vendors v ON p.vendor_id = v.id
    LEFT JOIN users u ON p.created_by = u.id
    WHERE $where_sql ORDER BY p.purchase_date DESC");
$stmt->execute($params);
$purchases = $stmt->fetchAll();

// â”€â”€ Vendors for filter â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$vendor_where = $isAdmin ? "" : " AND branch_id = " . (int)$branch_id;
$vendors = $pdo->query("SELECT id, name FROM vendors WHERE status=1 $vendor_where ORDER BY name ASC")->fetchAll();

$csrf_token = generateCSRFToken();
include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Purchase Report</h1>
        <div class="sub">All purchase orders, total spend and vendor breakdown</div>
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
        <select name="vendor_id" class="form-control" style="width:180px;">
            <option value="0">All Vendors</option>
            <?php foreach ($vendors as $v): ?>
                <option value="<?php echo $v['id']; ?>" <?php echo $vendor_filter==$v['id'] ? 'selected':''; ?>><?php echo sanitize($v['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline"><i class="fas fa-filter"></i> Apply</button>
        <a href="vendor_purchases.php" class="btn btn-ghost">Clear</a>
    </form>
</div>

<!-- Stat Cards -->
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr);display:grid;gap:16px;margin-bottom:24px;">
    <div class="stat-card blue">
        <div class="stat-value"><?php echo number_format($stats['total_orders'] ?? 0); ?></div>
        <div class="stat-label">Total Orders</div>
    </div>
    <div class="stat-card red">
        <div class="stat-value"><?php echo formatCurrency($stats['total_spend'] ?? 0); ?></div>
        <div class="stat-label">Total Spend</div>
    </div>
    <div class="stat-card green">
        <div class="stat-value"><?php echo number_format($stats['vendor_count'] ?? 0); ?></div>
        <div class="stat-label">Active Vendors</div>
    </div>
    <div class="stat-card orange">
        <div class="stat-value"><?php echo formatCurrency($stats['avg_order'] ?? 0); ?></div>
        <div class="stat-label">Avg Order Value</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:300px 1fr;gap:20px;">
    <!-- Top 5 vendors -->
    <div class="table-wrapper" id="top-vendors">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-ranking-star" style="color:var(--warning);"></i> Top Vendors</h3>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Vendor</th>
                    <th class="text-end">Orders</th>
                    <th class="text-end">Total Spend</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($top_vendors)): ?>
                    <tr><td colspan="4"><div class="empty-state"><i class="fas fa-truck empty-icon"></i><h3>No data</h3></div></td></tr>
                <?php else: ?>
                    <?php foreach ($top_vendors as $i => $tv): ?>
                    <tr>
                        <td class="fw-700" style="color:var(--<?php echo ['warning','secondary','success','primary','danger'][$i] ?? 'on-surface-muted'; ?>);"><?php echo $i+1; ?></td>
                        <td class="fw-600 fs-13"><?php echo sanitize($tv['name']); ?></td>
                        <td class="text-end fs-13"><?php echo $tv['order_count']; ?></td>
                        <td class="text-end fw-600"><?php echo formatCurrency($tv['total_spend']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- All purchases -->
    <div class="table-wrapper">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-file-invoice-dollar" style="color:var(--primary);"></i> All Purchases
                <span class="badge-pill badge-success" style="margin-left:8px;font-size:11px;"><?php echo count($purchases); ?> orders</span>
            </h3>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Invoice #</th>
                    <th>Vendor</th>
                    <th class="text-end">Amount</th>
                    <th>Note</th>
                    <th>Added By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($purchases)): ?>
                    <tr><td colspan="6">
                        <div class="empty-state">
                            <i class="fas fa-file-invoice empty-icon"></i>
                            <h3>No purchases in this period</h3>
                        </div>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($purchases as $p): ?>
                    <tr>
                        <td class="fs-12 text-muted"><?php echo formatDate($p['purchase_date']); ?></td>
                        <td class="fw-600 fs-12"><?php echo sanitize($p['invoice_number'] ?: 'â€”'); ?></td>
                        <td class="fs-13"><?php echo sanitize($p['vendor_name'] ?: 'Walk-in'); ?></td>
                        <td class="text-end fw-600" style="color:var(--danger);"><?php echo formatCurrency($p['total_amount']); ?></td>
                        <td class="fs-12 text-muted"><?php echo sanitize($p['note'] ?: 'â€”'); ?></td>
                        <td class="fs-12"><?php echo sanitize($p['created_by_name'] ?: 'â€”'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>

