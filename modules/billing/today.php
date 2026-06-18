<?php
/**
 * Today's Sales — quick at-a-glance figures for the current day:
 * total bills, total sales, cash vs online (and card/credit) collection,
 * and category-wise sales. Cashier-accessible; branch-scoped for non-admins.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle  = "Today's Sales";
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Today\'s Sales</span>';

$isAdmin   = isAdmin();
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$scope     = $isAdmin ? '' : ' AND b.branch_id = ' . $branch_id;

$start = date('Y-m-d') . ' 00:00:00';
$end   = date('Y-m-d') . ' 23:59:59';

// 1. Summary
$stmt = $pdo->prepare("SELECT COUNT(*) AS bills, COALESCE(SUM(total_amount),0) AS sales
                       FROM bills b WHERE b.created_at BETWEEN ? AND ? AND b.status != 'cancelled' $scope");
$stmt->execute([$start, $end]);
$summary = $stmt->fetch();

// 2. By payment mode
$stmt = $pdo->prepare("SELECT bill_type, COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS amt
                       FROM bills b WHERE b.created_at BETWEEN ? AND ? AND b.status != 'cancelled' $scope
                       GROUP BY bill_type");
$stmt->execute([$start, $end]);
$modes = ['cash' => ['cnt'=>0,'amt'=>0], 'online' => ['cnt'=>0,'amt'=>0], 'card' => ['cnt'=>0,'amt'=>0], 'credit' => ['cnt'=>0,'amt'=>0]];
foreach ($stmt->fetchAll() as $r) {
    if (isset($modes[$r['bill_type']])) $modes[$r['bill_type']] = ['cnt'=>(int)$r['cnt'], 'amt'=>(float)$r['amt']];
}

// 3. Category-wise (NULL category / deleted product → "Others")
$stmt = $pdo->prepare("SELECT COALESCE(c.name,'Others') AS category, COALESCE(SUM(bi.quantity),0) AS qty, COALESCE(SUM(bi.total),0) AS revenue
                       FROM bill_items bi
                       JOIN bills b ON bi.bill_id = b.id
                       LEFT JOIN products p ON bi.product_id = p.id
                       LEFT JOIN categories c ON p.category_id = c.id
                       WHERE b.created_at BETWEEN ? AND ? AND b.status != 'cancelled' $scope
                       GROUP BY COALESCE(c.name,'Others') ORDER BY revenue DESC");
$stmt->execute([$start, $end]);
$categories = $stmt->fetchAll();

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Today's Sales</h1>
        <div class="sub"><?php echo date('l, d M Y'); ?></div>
    </div>
    <div class="page-header-actions">
        <a href="history.php" class="btn btn-outline btn-sm"><i class="fas fa-clock-rotate-left"></i> Bill History</a>
    </div>
</div>

<!-- Headline KPIs -->
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr);">
    <div class="stat-card blue">
        <div class="stat-top"><div class="stat-icon"><i class="fas fa-file-invoice"></i></div></div>
        <div class="stat-value"><?php echo (int)$summary['bills']; ?></div>
        <div class="stat-label">Total Bills</div>
    </div>
    <div class="stat-card green">
        <div class="stat-top"><div class="stat-icon"><i class="fas fa-indian-rupee-sign"></i></div></div>
        <div class="stat-value"><?php echo formatCurrency($summary['sales']); ?></div>
        <div class="stat-label">Total Sales</div>
    </div>
    <div class="stat-card orange">
        <div class="stat-top"><div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div></div>
        <div class="stat-value"><?php echo formatCurrency($modes['cash']['amt']); ?></div>
        <div class="stat-label">Cash Collection (<?php echo $modes['cash']['cnt']; ?>)</div>
    </div>
    <div class="stat-card purple">
        <div class="stat-top"><div class="stat-icon"><i class="fas fa-wifi"></i></div></div>
        <div class="stat-value"><?php echo formatCurrency($modes['online']['amt']); ?></div>
        <div class="stat-label">Online Collection (<?php echo $modes['online']['cnt']; ?>)</div>
    </div>
</div>

<div class="row" style="display:flex;gap:20px;flex-wrap:wrap;margin-top:4px;">
    <!-- Payment modes breakdown -->
    <div class="table-wrapper" style="flex:1;min-width:320px;">
        <div style="padding:14px 16px;border-bottom:1.5px solid var(--border);font-weight:700;">Payment Modes</div>
        <table class="data-table">
            <thead><tr><th>Mode</th><th class="text-end">Bills</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
                <?php
                $labels = ['cash'=>'Cash','online'=>'Online','card'=>'Card','credit'=>'Credit'];
                foreach ($labels as $key => $label): ?>
                    <tr>
                        <td class="fw-600"><?php echo $label; ?></td>
                        <td class="text-end"><?php echo $modes[$key]['cnt']; ?></td>
                        <td class="text-end fw-600"><?php echo formatCurrency($modes[$key]['amt']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Category-wise -->
    <div class="table-wrapper" style="flex:1.4;min-width:360px;">
        <div style="padding:14px 16px;border-bottom:1.5px solid var(--border);font-weight:700;">Category-wise Sales</div>
        <table class="data-table">
            <thead><tr><th>Category</th><th class="text-end">Qty Sold</th><th class="text-end">Revenue</th></tr></thead>
            <tbody>
                <?php if (empty($categories)): ?>
                    <tr><td colspan="3" class="text-center py-5 text-muted">No sales yet today.</td></tr>
                <?php else: foreach ($categories as $cat): ?>
                    <tr>
                        <td class="fw-600"><?php echo sanitize($cat['category']); ?></td>
                        <td class="text-end"><?php echo (int)$cat['qty']; ?></td>
                        <td class="text-end fw-600"><?php echo formatCurrency($cat['revenue']); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
