<?php
/**
 * Reports Hub
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle = 'Reports';
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Reports</span>';

$branch_id = $_SESSION['branch_id'];
$isAdmin   = isAdmin();
$bFilter   = $isAdmin ? "" : " AND branch_id = " . (int)$branch_id;

// 1. Monthly Revenue
$stmt = $pdo->prepare("SELECT SUM(total_amount) FROM bills WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE()) AND status != 'cancelled'" . ($isAdmin ? "" : " AND branch_id = ?"));
if (!$isAdmin) $stmt->execute([$branch_id]); else $stmt->execute();
$monthly_sales = $stmt->fetchColumn() ?: 0;

// 2. Monthly Profit
$stmt = $pdo->prepare("SELECT SUM((bi.selling_price - p.purchase_price) * bi.quantity)
    FROM bill_items bi
    JOIN bills b ON bi.bill_id = b.id
    JOIN products p ON bi.product_id = p.id
    WHERE MONTH(b.created_at)=MONTH(CURDATE()) AND YEAR(b.created_at)=YEAR(CURDATE())
    AND b.status != 'cancelled'" . ($isAdmin ? "" : " AND b.branch_id = ?"));
if (!$isAdmin) $stmt->execute([$branch_id]); else $stmt->execute();
$monthly_profit = $stmt->fetchColumn() ?: 0;

// 3. Low Stock
$stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE quantity <= alert_quantity AND status = 'active'" . ($isAdmin ? "" : " AND branch_id = ?"));
if (!$isAdmin) $stmt->execute([$branch_id]); else $stmt->execute();
$low_stock_count = $stmt->fetchColumn() ?: 0;

// 4. Total Outstanding credit
$stmt = $pdo->prepare("SELECT SUM(total_amount - paid_amount) FROM bills WHERE bill_type='credit' AND status IN ('credit','partial')" . ($isAdmin ? "" : " AND branch_id = ?"));
if (!$isAdmin) $stmt->execute([$branch_id]); else $stmt->execute();
$total_outstanding = $stmt->fetchColumn() ?: 0;

include_once __DIR__ . '/../../includes/header.php';
?>

<style>
.report-card {
    transition: all 0.2s ease;
    border: 1.5px solid var(--border);
    text-decoration: none;
    display: block;
    padding: 24px;
    color: var(--on-surface);
}
.report-card:hover {
    transform: translateY(-3px);
    border-color: var(--primary);
    box-shadow: var(--shadow-lg);
}
.report-icon {
    width: 52px; height: 52px;
    display: flex; align-items: center; justify-content: center;
    border-radius: var(--radius-lg); font-size: 20px; margin-bottom: 14px;
}
.bg-blue-subtle   { background: var(--secondary-light); color: var(--secondary); }
.bg-green-subtle  { background: var(--success-light);   color: var(--success); }
.bg-orange-subtle { background: var(--primary-light);   color: var(--primary); }
.bg-red-subtle    { background: var(--danger-light);    color: var(--danger); }
.bg-purple-subtle { background: #ede9fe; color: #7c3aed; }
.bg-teal-subtle   { background: #ccfbf1; color: #0f766e; }
.bg-rose-subtle   { background: #ffe4e6; color: #e11d48; }
.bg-amber-subtle  { background: #fef3c7; color: #d97706; }

.hub-section { margin-top: 32px; }
.hub-section-title {
    font-size: 12px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 1px; color: var(--on-surface-subtle);
    margin-bottom: 14px; display: flex; align-items: center; gap: 10px;
}
.hub-section-title::after {
    content: ''; flex: 1; height: 1px; background: var(--border);
}
.sub-report-card {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 16px; border: 1.5px solid var(--border);
    border-radius: var(--radius); text-decoration: none;
    color: var(--on-surface); background: var(--surface);
    transition: all 0.15s ease;
}
.sub-report-card:hover {
    border-color: var(--primary);
    background: var(--primary-light);
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.sub-icon {
    width: 36px; height: 36px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; flex-shrink: 0;
}
.sub-info { flex: 1; min-width: 0; }
.sub-title { font-size: 13px; font-weight: 600; line-height: 1.3; }
.sub-desc  { font-size: 11px; color: var(--on-surface-muted); margin-top: 2px; line-height: 1.4; }
.grid-3 { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; }
</style>

<div class="page-header">
    <div class="page-header-left">
        <h1>Reports &amp; Analytics</h1>
        <div class="sub">Analyze your business performance with detailed insights</div>
    </div>
</div>

<!-- â”€â”€ Top 4 Live Stat Cards â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div class="grid-4">
    <a href="sales.php" class="card report-card">
        <div class="report-icon bg-blue-subtle"><i class="fas fa-chart-line"></i></div>
        <div style="font-size:16px;font-weight:700;color:var(--on-surface);margin-bottom:6px;">Sales Report</div>
        <div style="font-size:13px;color:var(--on-surface-muted);line-height:1.5;margin-bottom:16px;">Revenue trends, top products, and category analysis.</div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding-top:14px;border-top:1.5px solid var(--border);">
            <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:var(--on-surface-subtle);">This Month</span>
            <span style="font-weight:700;font-size:14px;color:var(--secondary);"><?php echo formatCurrency($monthly_sales); ?></span>
        </div>
    </a>

    <a href="profit.php" class="card report-card">
        <div class="report-icon bg-green-subtle"><i class="fas fa-hand-holding-dollar"></i></div>
        <div style="font-size:16px;font-weight:700;color:var(--on-surface);margin-bottom:6px;">Profit Report</div>
        <div style="font-size:13px;color:var(--on-surface-muted);line-height:1.5;margin-bottom:16px;">Gross vs Net profit, expense analysis, and margins.</div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding-top:14px;border-top:1.5px solid var(--border);">
            <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:var(--on-surface-subtle);">Est. Profit</span>
            <span style="font-weight:700;font-size:14px;color:var(--success);"><?php echo formatCurrency($monthly_profit); ?></span>
        </div>
    </a>

    <a href="inventory.php" class="card report-card">
        <div class="report-icon bg-orange-subtle"><i class="fas fa-boxes-stacked"></i></div>
        <div style="font-size:16px;font-weight:700;color:var(--on-surface);margin-bottom:6px;">Inventory Report</div>
        <div style="font-size:13px;color:var(--on-surface-muted);line-height:1.5;margin-bottom:16px;">Stock valuation, low stock alerts, and dead stock analysis.</div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding-top:14px;border-top:1.5px solid var(--border);">
            <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:var(--on-surface-subtle);">Low Stock</span>
            <span style="font-weight:700;font-size:14px;color:var(--warning);"><?php echo $low_stock_count; ?> items</span>
        </div>
    </a>

    <a href="credit.php" class="card report-card">
        <div class="report-icon bg-red-subtle"><i class="fas fa-user-clock"></i></div>
        <div style="font-size:16px;font-weight:700;color:var(--on-surface);margin-bottom:6px;">Credit (Khata) Report</div>
        <div style="font-size:13px;color:var(--on-surface-muted);line-height:1.5;margin-bottom:16px;">Outstanding dues, aging analysis, and collection performance.</div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding-top:14px;border-top:1.5px solid var(--border);">
            <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:var(--on-surface-subtle);">Outstanding</span>
            <span style="font-weight:700;font-size:14px;color:var(--danger);"><?php echo formatCurrency($total_outstanding); ?></span>
        </div>
    </a>
</div>

<!-- â”€â”€ Sales & Financials â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div class="hub-section">
    <div class="hub-section-title"><i class="fas fa-chart-bar" style="color:var(--secondary);"></i> Sales &amp; Financials</div>
    <div class="grid-4">
        <a href="sales.php" class="sub-report-card">
            <div class="sub-icon bg-blue-subtle"><i class="fas fa-chart-line"></i></div>
            <div class="sub-info">
                <div class="sub-title">Sales Report</div>
                <div class="sub-desc">Revenue, bills count, top products &amp; daily trends</div>
            </div>
        </a>
        <a href="profit.php" class="sub-report-card">
            <div class="sub-icon bg-green-subtle"><i class="fas fa-hand-holding-dollar"></i></div>
            <div class="sub-info">
                <div class="sub-title">Profit &amp; Loss</div>
                <div class="sub-desc">Gross profit, expenses &amp; top profitable products</div>
            </div>
        </a>
        <a href="tax.php" class="sub-report-card">
            <div class="sub-icon bg-purple-subtle"><i class="fas fa-percent"></i></div>
            <div class="sub-info">
                <div class="sub-title">Tax (GST) Report</div>
                <div class="sub-desc">GST collected, rate-wise breakdown &amp; taxable amounts</div>
            </div>
        </a>
        <a href="credit.php" class="sub-report-card">
            <div class="sub-icon bg-red-subtle"><i class="fas fa-user-clock"></i></div>
            <div class="sub-info">
                <div class="sub-title">Credit &amp; Aged Analysis</div>
                <div class="sub-desc">Outstanding dues &amp; 0-30/31-60/61-90/90+ day buckets</div>
            </div>
        </a>
    </div>
</div>

<!-- ── Garment Reports ─────────────────────────────────────── -->
<div class="hub-section">
    <div class="hub-section-title"><i class="fas fa-shirt" style="color:var(--primary);"></i> Garment Reports</div>
    <div class="grid-4">
        <a href="product_sales.php" class="sub-report-card">
            <div class="sub-icon bg-blue-subtle"><i class="fas fa-tags"></i></div>
            <div class="sub-info">
                <div class="sub-title">Product Sales</div>
                <div class="sub-desc">Qty sold, revenue &amp; profit per product</div>
            </div>
        </a>
        <a href="discount.php" class="sub-report-card">
            <div class="sub-icon bg-rose-subtle"><i class="fas fa-percent"></i></div>
            <div class="sub-info">
                <div class="sub-title">Discount Report</div>
                <div class="sub-desc">Bill &amp; line-item discounts given over a period</div>
            </div>
        </a>
        <a href="exchange.php" class="sub-report-card">
            <div class="sub-icon bg-orange-subtle"><i class="fas fa-right-left"></i></div>
            <div class="sub-info">
                <div class="sub-title">Exchange Report</div>
                <div class="sub-desc">Exchanges, return value &amp; difference collected</div>
            </div>
        </a>
        <a href="defective.php" class="sub-report-card">
            <div class="sub-icon bg-red-subtle"><i class="fas fa-triangle-exclamation"></i></div>
            <div class="sub-info">
                <div class="sub-title">Defective Report</div>
                <div class="sub-desc">Defective replacements &amp; reasons</div>
            </div>
        </a>
        <a href="<?php echo BASE_URL; ?>/modules/alteration/index.php" class="sub-report-card">
            <div class="sub-icon bg-teal-subtle"><i class="fas fa-scissors"></i></div>
            <div class="sub-info">
                <div class="sub-title">Alteration Report</div>
                <div class="sub-desc">Alteration jobs, charges &amp; status by period</div>
            </div>
        </a>
    </div>
</div>

<!-- â”€â”€ Inventory & Stock â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div class="hub-section">
    <div class="hub-section-title"><i class="fas fa-boxes-stacked" style="color:var(--primary);"></i> Inventory &amp; Stock</div>
    <div class="grid-4">
        <a href="inventory.php" class="sub-report-card">
            <div class="sub-icon bg-orange-subtle"><i class="fas fa-boxes-stacked"></i></div>
            <div class="sub-info">
                <div class="sub-title">Stock Status</div>
                <div class="sub-desc">Full product list with current qty &amp; stock value</div>
            </div>
        </a>
        <a href="stock_movement.php" class="sub-report-card">
            <div class="sub-icon bg-teal-subtle"><i class="fas fa-arrows-up-down"></i></div>
            <div class="sub-info">
                <div class="sub-title">Stock Movement</div>
                <div class="sub-desc">Every inventory log entry â€” in, out &amp; adjustments</div>
            </div>
        </a>
        <a href="inventory.php?filter=low_stock" class="sub-report-card">
            <div class="sub-icon bg-amber-subtle"><i class="fas fa-triangle-exclamation"></i></div>
            <div class="sub-info">
                <div class="sub-title">Low Stock Alerts</div>
                <div class="sub-desc">Items below their minimum alert quantity</div>
            </div>
        </a>
        <a href="inventory.php?filter=dead_stock" class="sub-report-card">
            <div class="sub-icon bg-red-subtle"><i class="fas fa-box-archive"></i></div>
            <div class="sub-info">
                <div class="sub-title">Dead Stock Report</div>
                <div class="sub-desc">Items with zero sales in the last 90 days</div>
            </div>
        </a>
    </div>
</div>

<!-- â”€â”€ Vendors & Purchases â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div class="hub-section">
    <div class="hub-section-title"><i class="fas fa-truck" style="color:var(--success);"></i> Vendors &amp; Purchases</div>
    <div class="grid-3">
        <a href="vendor_purchases.php" class="sub-report-card">
            <div class="sub-icon bg-green-subtle"><i class="fas fa-file-invoice-dollar"></i></div>
            <div class="sub-info">
                <div class="sub-title">Purchase Report</div>
                <div class="sub-desc">All purchase orders, total spend &amp; vendor breakdown</div>
            </div>
        </a>
        <a href="vendor_purchases.php#top-vendors" class="sub-report-card">
            <div class="sub-icon bg-teal-subtle"><i class="fas fa-ranking-star"></i></div>
            <div class="sub-info">
                <div class="sub-title">Top Vendors</div>
                <div class="sub-desc">Top 5 vendors ranked by total spend</div>
            </div>
        </a>
        <a href="<?php echo BASE_URL; ?>/modules/vendors/index.php" class="sub-report-card">
            <div class="sub-icon bg-blue-subtle"><i class="fas fa-address-book"></i></div>
            <div class="sub-info">
                <div class="sub-title">Vendor Directory</div>
                <div class="sub-desc">Full vendor list with contact &amp; payment details</div>
            </div>
        </a>
    </div>
</div>

<!-- â”€â”€ Staff & Operations â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div class="hub-section" style="margin-bottom:32px;">
    <div class="hub-section-title"><i class="fas fa-users" style="color:var(--info);"></i> Staff &amp; Operations</div>
    <div class="grid-3">
        <a href="cashier_performance.php" class="sub-report-card">
            <div class="sub-icon bg-blue-subtle"><i class="fas fa-cash-register"></i></div>
            <div class="sub-info">
                <div class="sub-title">Cashier Performance</div>
                <div class="sub-desc">Per-staff bill count, sales &amp; avg bill value</div>
            </div>
        </a>
        <a href="expenses_report.php" class="sub-report-card">
            <div class="sub-icon bg-amber-subtle"><i class="fas fa-receipt"></i></div>
            <div class="sub-info">
                <div class="sub-title">Expense Report</div>
                <div class="sub-desc">Expenses by category with totals &amp; line-item detail</div>
            </div>
        </a>
        <a href="<?php echo BASE_URL; ?>/modules/settings/audit_log.php" class="sub-report-card">
            <div class="sub-icon bg-rose-subtle"><i class="fas fa-shield-halved"></i></div>
            <div class="sub-info">
                <div class="sub-title">Audit Logs</div>
                <div class="sub-desc">Who did what and when â€” full activity log</div>
            </div>
        </a>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>

