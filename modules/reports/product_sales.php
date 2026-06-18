<?php
/**
 * Product Sales Report — product-wise quantity sold, revenue and profit.
 * Filters: Daily / Weekly / Monthly / Yearly / Financial Year / Custom (+ category) + CSV.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle  = 'Product Sales Report';
$breadcrumb = '<a href="'.BASE_URL.'/modules/reports/index.php">Reports</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Product Sales</span>';

$isAdmin   = isAdmin();
$branch_id = (int)($_SESSION['branch_id'] ?? 0);

$range  = $_GET['range'] ?? 'monthly';
$cat_id = (int)($_GET['category_id'] ?? 0);
[$date_from, $date_to] = resolveReportRange($range, $_GET['date_from'] ?? '', $_GET['date_to'] ?? '');

$scope  = $isAdmin ? '' : ' AND b.branch_id = ' . $branch_id;
$params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];
$catSql = '';
if ($cat_id) { $catSql = ' AND p.category_id = ?'; $params[] = $cat_id; }

$stmt = $pdo->prepare("SELECT bi.product_name, p.sku, c.name AS category,
                              SUM(bi.quantity) AS qty_sold,
                              SUM(bi.total) AS revenue,
                              SUM((bi.selling_price - COALESCE(p.purchase_price,0)) * bi.quantity) AS profit
                       FROM bill_items bi
                       JOIN bills b ON bi.bill_id = b.id
                       LEFT JOIN products p ON bi.product_id = p.id
                       LEFT JOIN categories c ON p.category_id = c.id
                       WHERE b.created_at BETWEEN ? AND ? AND b.status != 'cancelled' $scope $catSql
                       GROUP BY bi.product_name, p.sku, c.name
                       ORDER BY revenue DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$tot_qty = 0; $tot_rev = 0; $tot_profit = 0;
foreach ($rows as $r) { $tot_qty += (int)$r['qty_sold']; $tot_rev += (float)$r['revenue']; $tot_profit += (float)$r['profit']; }

if (isset($_GET['export'])) {
    $headers = ['Product','SKU','Category','Qty Sold','Revenue','Profit'];
    $data = array_map(fn($r) => [$r['product_name'], $r['sku'], $r['category'], $r['qty_sold'], $r['revenue'], $r['profit']], $rows);
    exportCSV('product_sales_' . $date_from . '_to_' . $date_to . '.csv', $headers, $data);
}

$categories = $pdo->query("SELECT id, name FROM categories WHERE status=1 ORDER BY name ASC")->fetchAll();
$qs = http_build_query(['range'=>$range,'date_from'=>$date_from,'date_to'=>$date_to,'category_id'=>$cat_id]);
include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Product Sales Report</h1>
        <div class="sub"><?php echo count($rows); ?> product<?php echo count($rows) != 1 ? 's' : ''; ?> · <?php echo formatDate($date_from); ?> – <?php echo formatDate($date_to); ?></div>
    </div>
    <div class="page-header-actions">
        <a href="?<?php echo $qs; ?>&export=1" class="btn btn-outline btn-sm"><i class="fas fa-file-csv"></i> Export</a>
    </div>
</div>

<div class="stat-grid mb-4" style="grid-template-columns:repeat(3,1fr);">
    <div class="stat-card blue"><div class="stat-value"><?php echo $tot_qty; ?></div><div class="stat-label">Units Sold</div></div>
    <div class="stat-card orange"><div class="stat-value"><?php echo formatCurrency($tot_rev); ?></div><div class="stat-label">Revenue</div></div>
    <div class="stat-card green"><div class="stat-value"><?php echo formatCurrency($tot_profit); ?></div><div class="stat-label">Profit</div></div>
</div>

<div class="table-wrapper">
    <div class="table-toolbar" style="flex-wrap:wrap;gap:10px;">
        <form action="" method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <select name="range" class="form-control" style="max-width:160px;" onchange="this.form.submit()">
                <?php foreach (reportRangeOptions() as $k=>$v): ?>
                    <option value="<?php echo $k; ?>" <?php echo $range === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                <?php endforeach; ?>
            </select>
            <select name="category_id" class="form-control" style="max-width:160px;" onchange="this.form.submit()">
                <option value="0">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $cat_id == $cat['id'] ? 'selected' : ''; ?>><?php echo sanitize($cat['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($range === 'custom'): ?>
                <input type="date" name="date_from" class="form-control" value="<?php echo sanitize($date_from); ?>" style="max-width:160px;">
                <input type="date" name="date_to" class="form-control" value="<?php echo sanitize($date_to); ?>" style="max-width:160px;">
                <button type="submit" class="btn btn-outline">Apply</button>
            <?php endif; ?>
        </form>
    </div>
    <table class="data-table">
        <thead>
            <tr><th>Product</th><th>Category</th><th class="text-end">Qty Sold</th><th class="text-end">Revenue</th><th class="text-end">Profit</th></tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="5" class="text-center py-5 text-muted">No sales in this period.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <div class="fw-600"><?php echo sanitize($r['product_name']); ?></div>
                        <?php if ($r['sku']): ?><div class="fs-11 text-muted">SKU: <?php echo sanitize($r['sku']); ?></div><?php endif; ?>
                    </td>
                    <td class="fs-13"><?php echo $r['category'] ? sanitize($r['category']) : 'Others'; ?></td>
                    <td class="text-end fw-600"><?php echo (int)$r['qty_sold']; ?></td>
                    <td class="text-end"><?php echo formatCurrency($r['revenue']); ?></td>
                    <td class="text-end" style="color:var(--success);"><?php echo formatCurrency($r['profit']); ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
