<?php
/**
 * Discount Report — bill-level + line-item discounts given over a period.
 * Filters: Daily / Weekly / Monthly / Yearly / Financial Year / Custom + CSV.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle  = 'Discount Report';
$breadcrumb = '<a href="'.BASE_URL.'/modules/reports/index.php">Reports</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Discount Report</span>';

$isAdmin   = isAdmin();
$branch_id = (int)($_SESSION['branch_id'] ?? 0);

$range = $_GET['range'] ?? 'monthly';
[$date_from, $date_to] = resolveReportRange($range, $_GET['date_from'] ?? '', $_GET['date_to'] ?? '');

$scope = $isAdmin ? '' : ' AND b.branch_id = ' . $branch_id;
$params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];

// Bills that carry a discount (line discounts roll up into bill_items.discount).
$stmt = $pdo->prepare("SELECT b.id, b.bill_number, b.created_at, b.customer_name, b.subtotal, b.total_amount,
                              b.discount_amount AS bill_discount, b.discount_percent,
                              COALESCE((SELECT SUM(bi.discount) FROM bill_items bi WHERE bi.bill_id = b.id), 0) AS item_discount
                       FROM bills b
                       WHERE b.created_at BETWEEN ? AND ? AND b.status != 'cancelled' $scope
                       HAVING (bill_discount + item_discount) > 0
                       ORDER BY b.created_at DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$tot_bill_disc = 0; $tot_item_disc = 0; $tot_sales = 0;
foreach ($rows as $r) { $tot_bill_disc += (float)$r['bill_discount']; $tot_item_disc += (float)$r['item_discount']; $tot_sales += (float)$r['total_amount']; }
$tot_disc = $tot_bill_disc + $tot_item_disc;

if (isset($_GET['export'])) {
    $headers = ['Date','Bill No','Customer','Subtotal','Item Discount','Bill Discount','Total Discount','Net Total'];
    $data = array_map(fn($r) => [
        $r['created_at'], $r['bill_number'], $r['customer_name'], $r['subtotal'],
        $r['item_discount'], $r['bill_discount'], (float)$r['item_discount'] + (float)$r['bill_discount'], $r['total_amount']
    ], $rows);
    exportCSV('discount_report_' . $date_from . '_to_' . $date_to . '.csv', $headers, $data);
}

$qs = http_build_query(['range'=>$range,'date_from'=>$date_from,'date_to'=>$date_to]);
include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Discount Report</h1>
        <div class="sub"><?php echo count($rows); ?> discounted bill<?php echo count($rows) != 1 ? 's' : ''; ?> · <?php echo formatDate($date_from); ?> – <?php echo formatDate($date_to); ?></div>
    </div>
    <div class="page-header-actions">
        <a href="?<?php echo $qs; ?>&export=1" class="btn btn-outline btn-sm"><i class="fas fa-file-csv"></i> Export</a>
    </div>
</div>

<div class="stat-grid mb-4" style="grid-template-columns:repeat(3,1fr);">
    <div class="stat-card red"><div class="stat-value"><?php echo formatCurrency($tot_disc); ?></div><div class="stat-label">Total Discount</div></div>
    <div class="stat-card orange"><div class="stat-value"><?php echo formatCurrency($tot_item_disc); ?></div><div class="stat-label">Line-Item Discounts</div></div>
    <div class="stat-card blue"><div class="stat-value"><?php echo formatCurrency($tot_bill_disc); ?></div><div class="stat-label">Bill-Level Discounts</div></div>
</div>

<div class="table-wrapper">
    <div class="table-toolbar" style="flex-wrap:wrap;gap:10px;">
        <form action="" method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <select name="range" class="form-control" style="max-width:160px;" onchange="this.form.submit()">
                <?php foreach (reportRangeOptions() as $k=>$v): ?>
                    <option value="<?php echo $k; ?>" <?php echo $range === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
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
            <tr><th>Date</th><th>Bill No</th><th>Customer</th><th class="text-end">Subtotal</th><th class="text-end">Item Disc</th><th class="text-end">Bill Disc</th><th class="text-end">Total Disc</th><th class="text-end">Net</th></tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="8" class="text-center py-5 text-muted">No discounted bills in this period.</td></tr>
            <?php else: foreach ($rows as $r): $td = (float)$r['item_discount'] + (float)$r['bill_discount']; ?>
                <tr>
                    <td class="fs-12"><?php echo formatDateTime($r['created_at']); ?></td>
                    <td class="fw-600"><?php echo sanitize($r['bill_number']); ?></td>
                    <td class="fs-13"><?php echo $r['customer_name'] ? sanitize($r['customer_name']) : '<span class="text-muted">Walk-in</span>'; ?></td>
                    <td class="text-end"><?php echo formatCurrency($r['subtotal']); ?></td>
                    <td class="text-end"><?php echo formatCurrency($r['item_discount']); ?></td>
                    <td class="text-end"><?php echo formatCurrency($r['bill_discount']); ?></td>
                    <td class="text-end fw-600 text-danger"><?php echo formatCurrency($td); ?></td>
                    <td class="text-end"><?php echo formatCurrency($r['total_amount']); ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
