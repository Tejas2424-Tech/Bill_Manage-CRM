<?php
/**
 * Billing History
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = 'Billing History';
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Billing History</span>';

$branch_id = $_SESSION['branch_id'];
$branch_filter = isAdmin() ? "" : " AND b.branch_id = " . (int)$branch_id;

// Filters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$bill_type = $_GET['bill_type'] ?? '';
$status = $_GET['status'] ?? '';

$params = [$date_from, $date_to];
$where = "DATE(b.created_at) BETWEEN ? AND ?";

if ($bill_type) {
    $where .= " AND b.bill_type = ?";
    $params[] = $bill_type;
}
if ($status) {
    $where .= " AND b.status = ?";
    $params[] = $status;
}

// Fetch Bills
$query = "SELECT b.*, u.name as cashier_name, (SELECT COUNT(*) FROM bill_items WHERE bill_id = b.id) as item_count 
          FROM bills b 
          JOIN users u ON b.created_by = u.id 
          WHERE $where $branch_filter 
          ORDER BY b.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$bills = $stmt->fetchAll();

// Summary Stats
$total_bills = count($bills);
$total_amount = array_sum(array_column($bills, 'total_amount'));
$total_discount = array_sum(array_column($bills, 'discount_amount'));

$csrf_token = generateCSRFToken();

include_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h1>Billing History</h1>
        <div class="sub">All bills in the selected date range</div>
    </div>
    <div class="page-header-actions">
        <a href="create.php" class="btn btn-primary"><i class="fas fa-plus"></i> New Bill</a>
    </div>
</div>

<!-- KPI Row -->
<div class="stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px;">
    <div class="stat-card blue">
        <div class="stat-top"><div class="stat-icon"><i class="fas fa-receipt"></i></div></div>
        <div class="stat-value"><?php echo $total_bills; ?></div>
        <div class="stat-label">Total Bills</div>
    </div>
    <div class="stat-card green">
        <div class="stat-top"><div class="stat-icon"><i class="fas fa-indian-rupee-sign"></i></div></div>
        <div class="stat-value"><?php echo formatCurrency($total_amount); ?></div>
        <div class="stat-label">Total Revenue</div>
    </div>
    <div class="stat-card orange">
        <div class="stat-top"><div class="stat-icon"><i class="fas fa-tag"></i></div></div>
        <div class="stat-value"><?php echo formatCurrency($total_discount); ?></div>
        <div class="stat-label">Total Discounts Given</div>
    </div>
</div>

<!-- Bills Table -->
<div class="table-wrapper">
    <div class="table-toolbar" style="flex-wrap:wrap;gap:10px;">
        <form action="" method="GET" style="display:flex;gap:8px;flex-wrap:wrap;flex:1;">
            <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>" style="width:150px;">
            <input type="date" name="date_to"   class="form-control" value="<?php echo $date_to; ?>"   style="width:150px;">
            <select name="bill_type" class="form-control" style="width:120px;">
                <option value="">All Types</option>
                <option value="cash"   <?php echo $bill_type == 'cash'   ? 'selected' : ''; ?>>Cash</option>
                <option value="credit" <?php echo $bill_type == 'credit' ? 'selected' : ''; ?>>Credit</option>
                <option value="online" <?php echo $bill_type == 'online' ? 'selected' : ''; ?>>Online</option>
                <option value="card"   <?php echo $bill_type == 'card'   ? 'selected' : ''; ?>>Card</option>
            </select>
            <select name="status" class="form-control" style="width:130px;">
                <option value="">All Status</option>
                <option value="paid"      <?php echo $status == 'paid'      ? 'selected' : ''; ?>>Paid</option>
                <option value="credit"    <?php echo $status == 'credit'    ? 'selected' : ''; ?>>Credit (Due)</option>
                <option value="cancelled" <?php echo $status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
            <button type="submit" class="btn btn-outline"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($bill_type || $status || $date_from != date('Y-m-01') || $date_to != date('Y-m-d')): ?>
                <a href="history.php" class="btn btn-ghost">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Bill #</th>
                <th>Customer</th>
                <th>Items</th>
                <th>Amount</th>
                <th>Type</th>
                <th>Status</th>
                <th>Cashier</th>
                <th>Date</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($bills)): ?>
                <tr><td colspan="9">
                    <div class="empty-state">
                        <i class="fas fa-receipt empty-icon"></i>
                        <h3>No bills found</h3>
                        <p>Try adjusting the date range or filters.</p>
                    </div>
                </td></tr>
            <?php else: ?>
                <?php foreach ($bills as $b): ?>
                    <tr style="<?php echo $b['status'] == 'cancelled' ? 'opacity:0.55;' : ''; ?>">
                        <td style="font-weight:700;color:var(--primary);"><?php echo $b['bill_number']; ?></td>
                        <td>
                            <div style="font-weight:600;"><?php echo $b['customer_name'] ? sanitize($b['customer_name']) : '<span style="color:var(--on-surface-subtle)">Walk-in</span>'; ?></div>
                            <?php if ($b['customer_phone']): ?>
                                <div style="font-size:11px;color:var(--on-surface-subtle);"><?php echo $b['customer_phone']; ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge-pill badge-muted"><?php echo $b['item_count']; ?> items</span></td>
                        <td style="font-weight:700;"><?php echo formatCurrency($b['total_amount']); ?></td>
                        <td>
                            <?php
                            $type_badge = [
                                'cash'   => ['badge-info',      'fa-money-bill-wave'],
                                'credit' => ['badge-warning',   'fa-hourglass-half'],
                                'online' => ['badge-success',   'fa-wifi'],
                                'card'   => ['badge-secondary', 'fa-credit-card'],
                            ];
                            [$tb_class, $tb_icon] = $type_badge[$b['bill_type']] ?? ['badge-info', 'fa-money-bill-wave'];
                            ?>
                            <span class="badge-pill <?php echo $tb_class; ?>">
                                <i class="fas <?php echo $tb_icon; ?>"></i>
                                <?php echo ucfirst($b['bill_type']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge-pill <?php
                                echo match($b['status']) {
                                    'paid'      => 'badge-success',
                                    'cancelled' => 'badge-danger',
                                    'partial'   => 'badge-warning',
                                    default     => 'badge-warning',
                                };
                            ?>"<?php echo ($b['status'] === 'cancelled' && !empty($b['cancel_reason'])) ? ' title="Reason: ' . sanitize($b['cancel_reason']) . '"' : ''; ?>>
                                <?php echo ucfirst($b['status']); ?>
                            </span>
                        </td>
                        <td style="font-size:12px;"><?php echo sanitize($b['cashier_name']); ?></td>
                        <td style="font-size:12px;color:var(--on-surface-muted);"><?php echo formatDate($b['created_at']); ?></td>
                        <td style="text-align:right;">
                            <div style="display:flex;justify-content:flex-end;gap:4px;">
                                <a href="invoice.php?id=<?php echo $b['id']; ?>" class="btn btn-ghost btn-icon btn-sm" title="A4 Invoice"><i class="fas fa-file-invoice"></i></a>
                                <a href="thermal.php?id=<?php echo $b['id']; ?>" class="btn btn-ghost btn-icon btn-sm" target="_blank" title="Thermal Print"><i class="fas fa-receipt"></i></a>
                                <?php if ($b['status'] != 'cancelled' && isBranchAdmin()): ?>
                                    <form action="cancel.php" method="POST" style="display:inline;" onsubmit="return askCancelReason(this);">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="bill_id" value="<?php echo $b['id']; ?>">
                                        <input type="hidden" name="cancel_reason" value="">
                                        <button type="submit" class="btn btn-ghost btn-icon btn-sm" style="color:var(--danger);" title="Cancel Bill"><i class="fas fa-ban"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="table-footer">
        <span>Showing <?php echo count($bills); ?> bill<?php echo count($bills) != 1 ? 's' : ''; ?></span>
        <span style="font-weight:600;">Total: <?php echo formatCurrency($total_amount); ?></span>
    </div>
</div>

<script>
/* Require a reason before cancelling (the prompt also serves as the confirmation). */
function askCancelReason(form) {
    const reason = prompt('Reason for cancelling this bill? (required)\nStock will be reversed.');
    if (reason === null) return false;                 // user dismissed the prompt
    if (!reason.trim()) { alert('A cancellation reason is required.'); return false; }
    form.cancel_reason.value = reason.trim();
    return true;
}
</script>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
