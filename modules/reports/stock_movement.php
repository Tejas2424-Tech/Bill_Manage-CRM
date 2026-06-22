<?php
/**
 * Stock Movement Report
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle = 'Stock Movement';
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><a href="index.php">Reports</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Stock Movement</span>';

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

$type_filter    = sanitize($_GET['type']    ?? '');
$product_search = sanitize($_GET['product'] ?? '');
$selected_branch = $isAdmin ? (int)($_GET['branch_id'] ?? 0) : (int)$branch_id;

// â”€â”€ Build WHERE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];
$where  = ["il.created_at BETWEEN ? AND ?"];

if ($isAdmin && $selected_branch > 0) {
    $where[]  = "il.branch_id = ?";
    $params[] = $selected_branch;
} elseif (!$isAdmin) {
    $where[]  = "il.branch_id = ?";
    $params[] = $branch_id;
}

if ($type_filter && in_array($type_filter, ['in','out','adjustment'])) {
    $where[]  = "il.type = ?";
    $params[] = $type_filter;
}

if ($product_search) {
    $where[]  = "(p.name LIKE ? OR p.sku LIKE ?)";
    $s = "%$product_search%";
    $params[] = $s; $params[] = $s;
}

$where_sql = implode(' AND ', $where);

// â”€â”€ CSV Export â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
    } else {
        $stmt = $pdo->prepare("SELECT il.created_at, p.name as product_name, p.sku,
            il.type, il.quantity, il.reference_type, il.reference_id, il.note, u.name as user_name
            FROM inventory_log il
            JOIN products p ON il.product_id = p.id
            LEFT JOIN users u ON il.created_by = u.id
            WHERE $where_sql ORDER BY il.created_at DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $export = [];
        foreach ($rows as $r) {
            $export[] = [
                formatDateTime($r['created_at']),
                $r['product_name'],
                $r['sku'],
                ucfirst($r['type']),
                $r['quantity'],
                $r['reference_type'] ? ucfirst($r['reference_type']) . ' #' . $r['reference_id'] : 'â€”',
                $r['note'] ?: 'â€”',
                $r['user_name'] ?: 'â€”',
            ];
        }
        exportCSV("stock_movement_{$date_from}_to_{$date_to}.csv",
            ['Date', 'Product', 'SKU', 'Type', 'Qty', 'Reference', 'Note', 'User'],
            $export);
    }
}

// â”€â”€ Stats â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$stmt = $pdo->prepare("SELECT
    SUM(CASE WHEN il.type='in'         THEN il.quantity ELSE 0 END) as total_in,
    SUM(CASE WHEN il.type='out'        THEN il.quantity ELSE 0 END) as total_out,
    SUM(CASE WHEN il.type='adjustment' THEN ABS(il.quantity) ELSE 0 END) as total_adj,
    COUNT(*) as total_entries
    FROM inventory_log il
    JOIN products p ON il.product_id = p.id
    WHERE $where_sql");
$stmt->execute($params);
$stats = $stmt->fetch();

// â”€â”€ Movement log â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$stmt = $pdo->prepare("SELECT il.*, p.name as product_name, p.sku, u.name as user_name
    FROM inventory_log il
    JOIN products p ON il.product_id = p.id
    LEFT JOIN users u ON il.created_by = u.id
    WHERE $where_sql
    ORDER BY il.created_at DESC LIMIT 500");
$stmt->execute($params);
$movements = $stmt->fetchAll();


$net_change = ($stats['total_in'] ?? 0) - ($stats['total_out'] ?? 0);

$csrf_token = generateCSRFToken();
include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Stock Movement</h1>
        <div class="sub">Every inventory log entry â€” stock in, out and adjustments</div>
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
        <select name="type" class="form-control" style="width:140px;">
            <option value="">All Types</option>
            <option value="in"         <?php echo $type_filter==='in'         ? 'selected':''; ?>>Stock In</option>
            <option value="out"        <?php echo $type_filter==='out'        ? 'selected':''; ?>>Stock Out</option>
            <option value="adjustment" <?php echo $type_filter==='adjustment' ? 'selected':''; ?>>Adjustment</option>
        </select>
        <input type="text" name="product" class="form-control" placeholder="Search productâ€¦"
               value="<?php echo $product_search; ?>" style="width:180px;">
        <button type="submit" class="btn btn-outline"><i class="fas fa-filter"></i> Apply</button>
        <a href="stock_movement.php" class="btn btn-ghost">Clear</a>
    </form>
</div>

<!-- Stat Cards -->
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr);display:grid;gap:16px;margin-bottom:24px;">
    <div class="stat-card green">
        <div class="stat-value"><?php echo number_format($stats['total_in'] ?? 0); ?></div>
        <div class="stat-label">Total In (units)</div>
    </div>
    <div class="stat-card red">
        <div class="stat-value"><?php echo number_format($stats['total_out'] ?? 0); ?></div>
        <div class="stat-label">Total Out (units)</div>
    </div>
    <div class="stat-card orange">
        <div class="stat-value"><?php echo number_format($stats['total_adj'] ?? 0); ?></div>
        <div class="stat-label">Adjustments (units)</div>
    </div>
    <div class="stat-card <?php echo $net_change >= 0 ? 'blue' : 'red'; ?>">
        <div class="stat-value"><?php echo ($net_change >= 0 ? '+' : '') . number_format($net_change); ?></div>
        <div class="stat-label">Net Change</div>
    </div>
</div>

<!-- Movement log -->
<div class="table-wrapper">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-arrows-up-down" style="color:var(--secondary);"></i> Movement Log
            <span class="badge-pill badge-success" style="margin-left:8px;font-size:11px;"><?php echo count($movements); ?> entries</span>
        </h3>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Product</th>
                <th style="width:100px;">Type</th>
                <th class="text-end" style="width:80px;">Qty</th>
                <th>Reference</th>
                <th>Note</th>
                <th>User</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($movements)): ?>
                <tr><td colspan="7">
                    <div class="empty-state">
                        <i class="fas fa-arrows-up-down empty-icon"></i>
                        <h3>No stock movements in this period</h3>
                    </div>
                </td></tr>
            <?php else: ?>
                <?php foreach ($movements as $m): ?>
                <tr>
                    <td class="fs-12 text-muted"><?php echo formatDateTime($m['created_at']); ?></td>
                    <td>
                        <div class="fw-600 fs-13"><?php echo sanitize($m['product_name']); ?></div>
                        <div class="fs-11 text-muted">SKU: <?php echo sanitize($m['sku']); ?></div>
                    </td>
                    <td>
                        <?php if ($m['type'] === 'in'): ?>
                            <span class="badge-pill badge-success"><i class="fas fa-arrow-down"></i> In</span>
                        <?php elseif ($m['type'] === 'out'): ?>
                            <span class="badge-pill badge-danger"><i class="fas fa-arrow-up"></i> Out</span>
                        <?php else: ?>
                            <span class="badge-pill badge-warning"><i class="fas fa-arrows-up-down"></i> Adjust</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end fw-600 <?php echo $m['type']==='in' ? 'text-success' : ($m['type']==='out' ? 'text-danger' : ''); ?>">
                        <?php echo ($m['type']==='in' ? '+' : ($m['type']==='out' ? '-' : 'Â±')) . abs($m['quantity']); ?>
                    </td>
                    <td class="fs-12">
                        <?php echo $m['reference_type'] ? ucfirst($m['reference_type']) . ' #' . $m['reference_id'] : 'â€”'; ?>
                    </td>
                    <td class="fs-12 text-muted"><?php echo sanitize($m['note'] ?: 'â€”'); ?></td>
                    <td class="fs-12"><?php echo sanitize($m['user_name'] ?: 'â€”'); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>

