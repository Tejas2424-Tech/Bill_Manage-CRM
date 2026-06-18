<?php
/**
 * Exchange Report — Daily / Weekly / Monthly / Yearly / Custom, with CSV export.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle  = 'Exchange Report';
$breadcrumb = '<a href="'.BASE_URL.'/modules/reports/index.php">Reports</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Exchange Report</span>';

$isAdmin   = isAdmin();
$branch_id = (int)($_SESSION['branch_id'] ?? 0);

$range = $_GET['range'] ?? 'monthly';
[$date_from, $date_to] = resolveReportRange($range, $_GET['date_from'] ?? '', $_GET['date_to'] ?? '');

$where  = ["e.created_at BETWEEN ? AND ?"];
$params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];
if (!$isAdmin) { $where[] = "e.branch_id = ?"; $params[] = $branch_id; }
$where_sql = implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT e.*, u.name AS staff
                       FROM exchanges e LEFT JOIN users u ON e.created_by = u.id
                       WHERE $where_sql ORDER BY e.created_at DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$total_return = 0; $total_diff = 0;
foreach ($rows as $r) { $total_return += (float)$r['return_value']; $total_diff += (float)$r['difference_paid']; }

if (isset($_GET['export'])) {
    $headers = ['Date','Old Product','Return Value','New Product','New Final','Difference Paid','Payment','Staff'];
    $data = array_map(fn($r) => [
        $r['created_at'], $r['old_product_name'], $r['return_value'], $r['new_product_name'],
        $r['new_final_price'], $r['difference_paid'], ucfirst((string)$r['payment_mode']), $r['staff']
    ], $rows);
    exportCSV('exchange_report_' . $date_from . '_to_' . $date_to . '.csv', $headers, $data);
}

$qs = http_build_query(['range'=>$range,'date_from'=>$date_from,'date_to'=>$date_to]);
include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Exchange Report</h1>
        <div class="sub"><?php echo count($rows); ?> exchange<?php echo count($rows) != 1 ? 's' : ''; ?> · <?php echo formatDate($date_from); ?> – <?php echo formatDate($date_to); ?></div>
    </div>
    <div class="page-header-actions">
        <a href="?<?php echo $qs; ?>&export=1" class="btn btn-outline btn-sm"><i class="fas fa-file-csv"></i> Export</a>
    </div>
</div>

<div class="stat-grid mb-4" style="grid-template-columns:repeat(3,1fr);">
    <div class="stat-card blue"><div class="stat-value"><?php echo count($rows); ?></div><div class="stat-label">Exchanges</div></div>
    <div class="stat-card orange"><div class="stat-value"><?php echo formatCurrency($total_return); ?></div><div class="stat-label">Total Return Value</div></div>
    <div class="stat-card green"><div class="stat-value"><?php echo formatCurrency($total_diff); ?></div><div class="stat-label">Difference Collected</div></div>
</div>

<div class="table-wrapper">
    <div class="table-toolbar" style="flex-wrap:wrap;gap:10px;">
        <form action="" method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <select name="range" class="form-control" style="max-width:140px;" onchange="this.form.submit()">
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
            <tr><th>Date</th><th>Old → New</th><th class="text-end">Return Value</th><th class="text-end">New Final</th><th class="text-end">Difference</th><th>Payment</th><th>Staff</th></tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="7" class="text-center py-5 text-muted">No exchanges in this period.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td class="fs-12"><?php echo formatDateTime($r['created_at']); ?></td>
                    <td class="fs-13"><span class="fw-600"><?php echo sanitize($r['old_product_name']); ?></span> <i class="fas fa-arrow-right fs-10 text-muted"></i> <?php echo sanitize($r['new_product_name']); ?></td>
                    <td class="text-end"><?php echo formatCurrency($r['return_value']); ?></td>
                    <td class="text-end"><?php echo formatCurrency($r['new_final_price']); ?></td>
                    <td class="text-end fw-600"><?php echo formatCurrency($r['difference_paid']); ?></td>
                    <td><?php echo $r['payment_mode'] ? '<span class="badge-pill badge-info">'.ucfirst($r['payment_mode']).'</span>' : '<span class="text-muted">-</span>'; ?></td>
                    <td class="fs-12"><?php echo sanitize($r['staff']); ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
