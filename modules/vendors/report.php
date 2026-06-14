<?php
/**
 * Vendor Monthly / Yearly Purchase Report
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle = 'Vendor Report';
$breadcrumb = '<a href="' . BASE_URL . '/modules/vendors/index.php">Vendors</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Vendor Report</span>';

$branch_id = $_SESSION['branch_id'];

// Fetch vendors list for filter dropdown
$vendors_query = isAdmin()
    ? "SELECT id, name FROM vendors WHERE status=1 ORDER BY name ASC"
    : "SELECT id, name FROM vendors WHERE status=1 AND branch_id = $branch_id ORDER BY name ASC";
$all_vendors = $pdo->query($vendors_query)->fetchAll();

// Filters
$selected_vendor = (int)($_GET['vendor_id'] ?? ($all_vendors[0]['id'] ?? 0));
$selected_year   = (int)($_GET['year'] ?? date('Y'));
$selected_month  = (int)($_GET['month'] ?? 0); // 0 = all months

// Validate vendor ownership for non-admins
$vendor = null;
if ($selected_vendor) {
    $stmt = $pdo->prepare("SELECT * FROM vendors WHERE id = ?" . (!isAdmin() ? " AND branch_id = ?" : ""));
    $params = [$selected_vendor];
    if (!isAdmin()) $params[] = $branch_id;
    $stmt->execute($params);
    $vendor = $stmt->fetch();
}

// Handle CSV export
if (isset($_GET['export']) && $vendor) {
    $detail_params = [$selected_vendor, $selected_year, $selected_month, $selected_month];
    $detail_query  = "SELECT p.purchase_date, p.invoice_number, p.total_amount, p.note, u.name as staff_name
                      FROM purchases p
                      LEFT JOIN users u ON p.created_by = u.id
                      WHERE p.vendor_id = ? AND YEAR(p.purchase_date) = ?
                        AND (? = 0 OR MONTH(p.purchase_date) = ?)
                      ORDER BY p.purchase_date DESC";
    $stmt = $pdo->prepare($detail_query);
    $stmt->execute($detail_params);
    $rows = $stmt->fetchAll();

    $export_rows = [];
    foreach ($rows as $r) {
        $export_rows[] = [
            formatDate($r['purchase_date']),
            $r['invoice_number'] ?: 'N/A',
            $r['total_amount'],
            $r['staff_name'] ?? '',
            $r['note'] ?? '',
        ];
    }
    $month_label = $selected_month ? date('F', mktime(0,0,0,$selected_month,1)) . '_' : '';
    exportCSV("vendor_report_{$vendor['name']}_{$month_label}{$selected_year}.csv",
        ['Date', 'Invoice No', 'Amount', 'Recorded By', 'Note'],
        $export_rows);
}

// Monthly breakdown
$monthly_data = [];
$yearly_total = 0;
if ($vendor) {
    $stmt = $pdo->prepare(
        "SELECT MONTH(purchase_date) as month, COUNT(*) as total_purchases, SUM(total_amount) as total
         FROM purchases
         WHERE vendor_id = ? AND YEAR(purchase_date) = ?
         GROUP BY MONTH(purchase_date)
         ORDER BY month ASC"
    );
    $stmt->execute([$selected_vendor, $selected_year]);
    $monthly_data = $stmt->fetchAll();
    $yearly_total = array_sum(array_column($monthly_data, 'total'));
}

// Purchase detail list
$detail_rows = [];
if ($vendor) {
    $stmt = $pdo->prepare(
        "SELECT p.id, p.purchase_date, p.invoice_number, p.total_amount, p.note, u.name as staff_name
         FROM purchases p
         LEFT JOIN users u ON p.created_by = u.id
         WHERE p.vendor_id = ? AND YEAR(p.purchase_date) = ?
           AND (? = 0 OR MONTH(p.purchase_date) = ?)
         ORDER BY p.purchase_date DESC"
    );
    $stmt->execute([$selected_vendor, $selected_year, $selected_month, $selected_month]);
    $detail_rows = $stmt->fetchAll();
}

// Year options (earliest purchase to current year)
$year_range_stmt = $pdo->query("SELECT MIN(YEAR(purchase_date)) as min_y, MAX(YEAR(purchase_date)) as max_y FROM purchases");
$yr = $year_range_stmt->fetch();
$min_year = $yr['min_y'] ?? date('Y');
$max_year = max($yr['max_y'] ?? date('Y'), date('Y'));

$months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>Vendor Purchase Report</h1>
    <?php if ($vendor && !empty($detail_rows)): ?>
        <a href="?vendor_id=<?php echo $selected_vendor; ?>&year=<?php echo $selected_year; ?>&month=<?php echo $selected_month; ?>&export=1"
           class="btn btn-outline btn-sm">
            <i class="fas fa-download me-1"></i> Export CSV
        </a>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="form-card mb-4">
    <form method="GET" class="d-flex flex-wrap gap-3 align-center">
        <div class="form-group mb-0">
            <label class="form-label fs-11">Vendor</label>
            <select name="vendor_id" class="form-control" onchange="this.form.submit()">
                <?php foreach ($all_vendors as $v): ?>
                    <option value="<?php echo $v['id']; ?>" <?php echo $selected_vendor == $v['id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($v['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group mb-0">
            <label class="form-label fs-11">Year</label>
            <select name="year" class="form-control" onchange="this.form.submit()">
                <?php for ($y = $max_year; $y >= $min_year; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $selected_year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="form-group mb-0">
            <label class="form-label fs-11">Month</label>
            <select name="month" class="form-control" onchange="this.form.submit()">
                <option value="0" <?php echo $selected_month == 0 ? 'selected' : ''; ?>>All Months</option>
                <?php foreach ($months as $mi => $mn): ?>
                    <option value="<?php echo $mi+1; ?>" <?php echo $selected_month == ($mi+1) ? 'selected' : ''; ?>><?php echo $mn; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" name="vendor_id" value="<?php echo $selected_vendor; ?>">
    </form>
</div>

<?php if (!$vendor): ?>
    <div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>No vendor selected or vendor not found.</div>
<?php else: ?>

<!-- Vendor Info Banner -->
<div class="form-card mb-4 p-4">
    <div class="d-flex justify-between align-center">
        <div>
            <div class="fw-700 fs-18 text-primary"><?php echo sanitize($vendor['name']); ?></div>
            <div class="text-muted fs-13 mt-1">
                <i class="fas fa-phone me-1"></i><?php echo $vendor['phone']; ?>
                <?php if ($vendor['gst_number']): ?> &nbsp;|&nbsp; GST: <?php echo $vendor['gst_number']; ?><?php endif; ?>
            </div>
        </div>
        <div class="text-end">
            <div class="fs-11 text-muted text-uppercase">Yearly Total (<?php echo $selected_year; ?>)</div>
            <div class="fs-24 fw-800 text-success"><?php echo formatCurrency($yearly_total); ?></div>
            <div class="fs-12 text-muted"><?php echo count($detail_rows); ?> purchase<?php echo count($detail_rows) != 1 ? 's' : ''; ?> <?php echo $selected_month ? 'this month' : 'this year'; ?></div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Monthly Breakdown -->
    <div class="col-md-4">
        <div class="table-wrapper">
            <div class="table-toolbar">
                <h3 class="card-title m-0">Monthly Breakdown — <?php echo $selected_year; ?></h3>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Orders</th>
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monthly_data)): ?>
                        <tr><td colspan="3" class="text-center py-4 text-muted">No purchases in <?php echo $selected_year; ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($monthly_data as $md): ?>
                            <tr class="<?php echo $selected_month == $md['month'] ? 'bg-hover' : ''; ?>">
                                <td>
                                    <a href="?vendor_id=<?php echo $selected_vendor; ?>&year=<?php echo $selected_year; ?>&month=<?php echo $md['month']; ?>"
                                       class="fw-600 <?php echo $selected_month == $md['month'] ? 'text-primary' : ''; ?>">
                                        <?php echo $months[$md['month']-1]; ?>
                                    </a>
                                </td>
                                <td><?php echo $md['total_purchases']; ?></td>
                                <td class="text-end fw-600"><?php echo formatCurrency($md['total']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="bg-secondary">
                            <td class="fw-700">Total</td>
                            <td class="fw-700"><?php echo array_sum(array_column($monthly_data, 'total_purchases')); ?></td>
                            <td class="text-end fw-800 text-success"><?php echo formatCurrency($yearly_total); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Purchase Detail List -->
    <div class="col-md-8">
        <div class="table-wrapper">
            <div class="table-toolbar">
                <h3 class="card-title m-0">
                    Purchase Details
                    <?php if ($selected_month): ?>
                        — <?php echo $months[$selected_month-1] . ' ' . $selected_year; ?>
                        <a href="?vendor_id=<?php echo $selected_vendor; ?>&year=<?php echo $selected_year; ?>" class="btn btn-ghost btn-sm ms-2">Clear Month</a>
                    <?php else: ?>
                        — All of <?php echo $selected_year; ?>
                    <?php endif; ?>
                </h3>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Invoice No</th>
                        <th>Amount</th>
                        <th>Recorded By</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($detail_rows)): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">No purchases found for this period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($detail_rows as $r): ?>
                            <tr>
                                <td><?php echo formatDate($r['purchase_date']); ?></td>
                                <td>
                                    <span class="fw-600"><?php echo $r['invoice_number'] ?: '<span class="text-muted">Direct</span>'; ?></span>
                                    <?php if ($r['note']): ?>
                                        <div class="fs-11 text-muted truncate" style="max-width:200px;"><?php echo sanitize($r['note']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-600 text-success"><?php echo formatCurrency($r['total_amount']); ?></td>
                                <td class="fs-13"><?php echo sanitize($r['staff_name'] ?? 'N/A'); ?></td>
                                <td class="text-end">
                                    <a href="<?php echo BASE_URL; ?>/modules/purchase/view.php?id=<?php echo $r['id']; ?>"
                                       class="btn btn-ghost btn-icon btn-sm" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
