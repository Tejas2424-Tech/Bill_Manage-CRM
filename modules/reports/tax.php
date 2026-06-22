<?php
/**
 * Tax (GST) Report
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle = 'Tax (GST) Report';
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><a href="index.php">Reports</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Tax Report</span>';

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

// â”€â”€ Branch filter â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$selected_branch = $isAdmin ? (int)($_GET['branch_id'] ?? 0) : (int)$branch_id;

$branch_params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];
$branch_where  = "";
if ($isAdmin && $selected_branch > 0) {
    $branch_where = " AND branch_id = ?";
    $branch_params[] = $selected_branch;
} elseif (!$isAdmin) {
    $branch_where = " AND branch_id = ?";
    $branch_params[] = $branch_id;
}

// â”€â”€ CSV Export â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
    } else {
        $stmt = $pdo->prepare("SELECT gst_percent, COUNT(*) as bill_count,
            SUM(subtotal) as taxable_amount, SUM(gst_amount) as gst_collected,
            SUM(total_amount) as total_with_tax
            FROM bills WHERE status != 'cancelled'
            AND created_at BETWEEN ? AND ?" . $branch_where . "
            GROUP BY gst_percent ORDER BY gst_percent ASC");
        $stmt->execute($branch_params);
        $rows = $stmt->fetchAll();
        $export = [];
        foreach ($rows as $r) {
            $export[] = [
                $r['gst_percent'] . '%',
                $r['bill_count'],
                number_format($r['taxable_amount'], 2),
                number_format($r['gst_collected'], 2),
                number_format($r['total_with_tax'], 2),
            ];
        }
        exportCSV("gst_report_{$date_from}_to_{$date_to}.csv",
            ['GST Rate', 'Bills', 'Taxable Amount', 'GST Collected', 'Total w/ Tax'],
            $export);
    }
}

// â”€â”€ Stats â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$stmt = $pdo->prepare("SELECT
    COUNT(*) as total_bills,
    SUM(CASE WHEN gst_amount > 0 THEN 1 ELSE 0 END) as taxed_bills,
    SUM(CASE WHEN gst_amount = 0 THEN 1 ELSE 0 END) as zero_tax_bills,
    SUM(subtotal) as total_taxable,
    SUM(gst_amount) as total_gst
    FROM bills WHERE status != 'cancelled'
    AND created_at BETWEEN ? AND ?" . $branch_where);
$stmt->execute($branch_params);
$stats = $stmt->fetch();

// â”€â”€ Rate breakdown â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$stmt = $pdo->prepare("SELECT gst_percent, COUNT(*) as bill_count,
    SUM(subtotal) as taxable_amount, SUM(gst_amount) as gst_collected,
    SUM(total_amount) as total_with_tax
    FROM bills WHERE status != 'cancelled'
    AND created_at BETWEEN ? AND ?" . $branch_where . "
    GROUP BY gst_percent ORDER BY gst_percent ASC");
$stmt->execute($branch_params);
$rate_breakdown = $stmt->fetchAll();

// â”€â”€ Detail bills (with GST only) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$stmt = $pdo->prepare("SELECT b.bill_number, b.customer_name, b.gst_percent, b.subtotal,
    b.gst_amount, b.total_amount, b.created_at, br.name as branch_name
    FROM bills b
    LEFT JOIN branches br ON b.branch_id = br.id
    WHERE b.status != 'cancelled' AND b.gst_amount > 0
    AND b.created_at BETWEEN ? AND ?" . $branch_where . "
    ORDER BY b.created_at DESC LIMIT 200");
$stmt->execute($branch_params);
$detail_bills = $stmt->fetchAll();

// â”€â”€ Branches for admin filter â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

$csrf_token = generateCSRFToken();
include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Tax (GST) Report</h1>
        <div class="sub">GST collected, rate-wise breakdown and taxable amounts</div>
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
                <a href="?range=<?php echo $k; ?><?php echo $isAdmin && $selected_branch ? '&branch_id='.$selected_branch : ''; ?>"
                   class="btn btn-sm <?php echo $range===$k ? 'btn-primary' : 'btn-outline'; ?>"><?php echo $v; ?></a>
            <?php endforeach; ?>
        </div>
        <?php if ($range === 'custom'): ?>
            <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>" style="width:150px;">
            <input type="date" name="date_to"   class="form-control" value="<?php echo $date_to;   ?>" style="width:150px;">
            <input type="hidden" name="range" value="custom">
        <?php endif; ?>
        <?php if ($range === 'custom' || ($isAdmin && $selected_branch)): ?>
            <button type="submit" class="btn btn-outline"><i class="fas fa-filter"></i> Apply</button>
        <?php endif; ?>
    </form>
</div>

<!-- Stat Cards -->
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr);display:grid;gap:16px;margin-bottom:24px;">
    <div class="stat-card blue">
        <div class="stat-value"><?php echo number_format($stats['taxed_bills'] ?? 0); ?></div>
        <div class="stat-label">Bills with GST</div>
    </div>
    <div class="stat-card green">
        <div class="stat-value"><?php echo formatCurrency($stats['total_taxable'] ?? 0); ?></div>
        <div class="stat-label">Total Taxable Amount</div>
    </div>
    <div class="stat-card orange">
        <div class="stat-value"><?php echo formatCurrency($stats['total_gst'] ?? 0); ?></div>
        <div class="stat-label">Total GST Collected</div>
    </div>
    <div class="stat-card red">
        <div class="stat-value"><?php echo number_format($stats['zero_tax_bills'] ?? 0); ?></div>
        <div class="stat-label">Bills Without GST</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1.5fr;gap:20px;">
    <!-- Rate breakdown -->
    <div class="table-wrapper">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-percent" style="color:var(--secondary);"></i> GST Rate Breakdown</h3>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>GST Rate</th>
                    <th class="text-end">Bills</th>
                    <th class="text-end">Taxable Amt</th>
                    <th class="text-end">GST Collected</th>
                    <th class="text-end">Total w/ Tax</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rate_breakdown)): ?>
                    <tr><td colspan="5"><div class="empty-state"><i class="fas fa-percent empty-icon"></i><h3>No tax data</h3></div></td></tr>
                <?php else: ?>
                    <?php foreach ($rate_breakdown as $r): ?>
                    <tr>
                        <td>
                            <span class="badge-pill <?php echo $r['gst_percent'] > 0 ? 'badge-success' : 'badge-warning'; ?>">
                                <?php echo $r['gst_percent']; ?>%
                            </span>
                        </td>
                        <td class="text-end fw-600"><?php echo number_format($r['bill_count']); ?></td>
                        <td class="text-end"><?php echo formatCurrency($r['taxable_amount']); ?></td>
                        <td class="text-end fw-600" style="color:var(--success);"><?php echo formatCurrency($r['gst_collected']); ?></td>
                        <td class="text-end"><?php echo formatCurrency($r['total_with_tax']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Detail bills -->
    <div class="table-wrapper">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-file-invoice" style="color:var(--primary);"></i> Taxed Bills (latest 200)</h3>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Bill #</th>
                    <th>Customer</th>
                    <th class="text-end">Rate</th>
                    <th class="text-end">Subtotal</th>
                    <th class="text-end">GST</th>
                    <th class="text-end">Total</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($detail_bills)): ?>
                    <tr><td colspan="7"><div class="empty-state"><i class="fas fa-file-invoice empty-icon"></i><h3>No taxed bills in this period</h3></div></td></tr>
                <?php else: ?>
                    <?php foreach ($detail_bills as $b): ?>
                    <tr>
                        <td class="fw-600 fs-12"><?php echo sanitize($b['bill_number']); ?></td>
                        <td class="fs-13"><?php echo sanitize($b['customer_name'] ?: 'â€”'); ?></td>
                        <td class="text-end"><span class="badge-pill badge-success"><?php echo $b['gst_percent']; ?>%</span></td>
                        <td class="text-end fs-13"><?php echo formatCurrency($b['subtotal']); ?></td>
                        <td class="text-end fw-600" style="color:var(--success);"><?php echo formatCurrency($b['gst_amount']); ?></td>
                        <td class="text-end fw-600"><?php echo formatCurrency($b['total_amount']); ?></td>
                        <td class="fs-12 text-muted"><?php echo formatDate($b['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>

