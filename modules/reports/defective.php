<?php
/**
 * Defective Replacement Report — Daily / Weekly / Monthly / Yearly / Custom + CSV.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle  = 'Defective Report';
$breadcrumb = '<a href="'.BASE_URL.'/modules/reports/index.php">Reports</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Defective Report</span>';

$isAdmin   = isAdmin();
$branch_id = (int)($_SESSION['branch_id'] ?? 0);

$range = $_GET['range'] ?? 'monthly';
[$date_from, $date_to] = resolveReportRange($range, $_GET['date_from'] ?? '', $_GET['date_to'] ?? '');

$where  = ["d.created_at BETWEEN ? AND ?"];
$params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];
if (!$isAdmin) { $where[] = "d.branch_id = ?"; $params[] = $branch_id; }
$where_sql = implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT d.*, u.name AS staff
                       FROM defective_replacements d LEFT JOIN users u ON d.created_by = u.id
                       WHERE $where_sql ORDER BY d.created_at DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (isset($_GET['export'])) {
    $headers = ['Date','Defective Product','Defect Reason','Final Sale Price','Replacement','Replacement Final','Staff'];
    $data = array_map(fn($r) => [
        $r['created_at'], $r['defective_product_name'], $r['defect_reason'], $r['final_sale_price'],
        $r['replacement_product_name'], $r['replacement_final_price'], $r['staff']
    ], $rows);
    exportCSV('defective_report_' . $date_from . '_to_' . $date_to . '.csv', $headers, $data);
}

$qs = http_build_query(['range'=>$range,'date_from'=>$date_from,'date_to'=>$date_to]);
include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Defective Replacement Report</h1>
        <div class="sub"><?php echo count($rows); ?> replacement<?php echo count($rows) != 1 ? 's' : ''; ?> · <?php echo formatDate($date_from); ?> – <?php echo formatDate($date_to); ?></div>
    </div>
    <div class="page-header-actions">
        <a href="?<?php echo $qs; ?>&export=1" class="btn btn-outline btn-sm"><i class="fas fa-file-csv"></i> Export</a>
    </div>
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
            <tr><th>Date</th><th>Defective Product</th><th>Defect Reason</th><th class="text-end">Final Sale Price</th><th>Replacement</th><th class="text-end">Repl. Final</th><th>Staff</th></tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="7" class="text-center py-5 text-muted">No defective replacements in this period.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td class="fs-12"><?php echo formatDateTime($r['created_at']); ?></td>
                    <td class="fw-600 fs-13"><?php echo sanitize($r['defective_product_name']); ?></td>
                    <td class="fs-13"><?php echo sanitize($r['defect_reason']); ?></td>
                    <td class="text-end"><?php echo formatCurrency($r['final_sale_price']); ?></td>
                    <td class="fs-13"><?php echo sanitize($r['replacement_product_name']); ?></td>
                    <td class="text-end fw-600"><?php echo formatCurrency($r['replacement_final_price']); ?></td>
                    <td class="fs-12"><?php echo sanitize($r['staff']); ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
