<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../../includes/functions.php';

$pageTitle  = 'Dashboard';
$breadcrumb = '<span class="current">Overview</span>';

$branch_id = $_SESSION['branch_id'];

$selected_branch_filter = isAdmin() ? (int)($_GET['branch_id'] ?? 0) : (int)$branch_id;
$b_filter_active = !isAdmin() || $selected_branch_filter > 0;
$b_filter_sql    = $b_filter_active ? " AND branch_id = ?" : "";
$b_filter_param  = $b_filter_active ? ($selected_branch_filter ?: $branch_id) : null;

$branch_filter       = isAdmin() ? "" : " AND branch_id = " . (int)$branch_id;

// Qualified sibling filters for queries that JOIN both bills (b) and products (p),
// where a bare branch_id would be ambiguous. Filters on the bill's (sale's) branch.
$b_filter_sql_b      = $b_filter_active ? " AND b.branch_id = ?" : "";
$branch_filter_b     = isAdmin() ? "" : " AND b.branch_id = " . (int)$branch_id;

$exec_b = function($sql) use ($pdo, $b_filter_active, $b_filter_param) {
    $stmt = $pdo->prepare($sql);
    if ($b_filter_active) $stmt->execute([$b_filter_param]); else $stmt->execute();
    return $stmt;
};

$today_sales     = $exec_b("SELECT SUM(total_amount) as total FROM bills WHERE DATE(created_at) = CURDATE() AND status != 'cancelled' $b_filter_sql")->fetch()['total'] ?? 0;
$today_profit    = $exec_b("SELECT SUM((bi.selling_price - p.purchase_price) * bi.quantity) as profit FROM bill_items bi JOIN bills b ON bi.bill_id = b.id JOIN products p ON bi.product_id = p.id WHERE DATE(b.created_at) = CURDATE() AND b.status != 'cancelled' $b_filter_sql_b")->fetch()['profit'] ?? 0;
$monthly_sales   = $exec_b("SELECT SUM(total_amount) as total FROM bills WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status != 'cancelled' $b_filter_sql")->fetch()['total'] ?? 0;
$pending_credits = $exec_b("SELECT SUM(total_amount - paid_amount) as total FROM bills WHERE bill_type = 'credit' AND status = 'credit' $b_filter_sql")->fetch()['total'] ?? 0;
$total_products  = $exec_b("SELECT COUNT(*) as total FROM products WHERE status = 'active' $b_filter_sql")->fetch()['total'] ?? 0;
$low_stock_count = $exec_b("SELECT COUNT(*) as total FROM products WHERE status = 'active' AND quantity <= alert_quantity $b_filter_sql")->fetch()['total'] ?? 0;
$dead_stock_count = $exec_b("SELECT COUNT(*) as total FROM products p WHERE p.status = 'active' AND p.quantity > 0 $b_filter_sql AND p.id NOT IN (SELECT bi.product_id FROM bill_items bi JOIN bills b ON bi.bill_id = b.id WHERE b.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) AND b.status != 'cancelled')")->fetch()['total'] ?? 0;
$defective_units  = $exec_b("SELECT COALESCE(SUM(defective_quantity),0) as total FROM products WHERE status = 'active' $b_filter_sql")->fetch()['total'] ?? 0;
$birthday_today   = $exec_b("SELECT COUNT(*) as total FROM customers WHERE date_of_birth IS NOT NULL AND MONTH(date_of_birth) = MONTH(CURDATE()) AND DAY(date_of_birth) = DAY(CURDATE()) $b_filter_sql")->fetch()['total'] ?? 0;

$recent_bills_stmt = $pdo->prepare("SELECT * FROM bills WHERE 1=1 $b_filter_sql ORDER BY created_at DESC LIMIT 8");
if ($b_filter_active) $recent_bills_stmt->execute([$b_filter_param]); else $recent_bills_stmt->execute();
$recent_bills = $recent_bills_stmt->fetchAll();

$low_stock_products = $exec_b("SELECT * FROM products WHERE status = 'active' AND quantity <= alert_quantity $b_filter_sql ORDER BY quantity ASC LIMIT 5")->fetchAll();

$chart_labels = []; $chart_values = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('D', strtotime($date));
    $stmt = $pdo->prepare("SELECT SUM(total_amount) as total FROM bills WHERE DATE(created_at) = ? AND status != 'cancelled' $b_filter_sql");
    if ($b_filter_active) $stmt->execute([$date, $b_filter_param]); else $stmt->execute([$date]);
    $chart_values[] = (float)($stmt->fetch()['total'] ?? 0);
}

$top_stmt = $pdo->prepare("SELECT p.name, SUM(bi.quantity) as total_sold FROM bill_items bi JOIN products p ON bi.product_id = p.id JOIN bills b ON bi.bill_id = b.id WHERE MONTH(b.created_at) = MONTH(CURDATE()) AND YEAR(b.created_at) = YEAR(CURDATE()) AND b.status != 'cancelled' $b_filter_sql_b GROUP BY bi.product_id ORDER BY total_sold DESC LIMIT 5");
if ($b_filter_active) $top_stmt->execute([$b_filter_param]); else $top_stmt->execute();
$top_products = $top_stmt->fetchAll();

$branch_performance = [];
if (isAdmin()) {
    $stmt = $pdo->query("SELECT b.name, COALESCE(SUM(bi.total_amount),0) as today_sales FROM branches b LEFT JOIN bills bi ON b.id = bi.branch_id AND DATE(bi.created_at) = CURDATE() AND bi.status != 'cancelled' GROUP BY b.id");
    $branch_performance = $stmt->fetchAll();
}

$monthly_gross    = $pdo->query("SELECT SUM((bi.selling_price - p.purchase_price) * bi.quantity) as profit FROM bill_items bi JOIN bills b ON bi.bill_id = b.id JOIN products p ON bi.product_id = p.id WHERE MONTH(b.created_at) = MONTH(CURDATE()) AND YEAR(b.created_at) = YEAR(CURDATE()) AND b.status != 'cancelled' $branch_filter_b")->fetch()['profit'] ?? 0;
$monthly_expenses = $pdo->query("SELECT SUM(amount) as total FROM expenses WHERE MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE()) $branch_filter")->fetch()['total'] ?? 0;
$monthly_net      = $monthly_gross - $monthly_expenses;

include_once __DIR__ . '/../../includes/header.php';
?>

<!-- Alert Banners -->
<?php if ($low_stock_count > 0): ?>
<div class="alert alert-warning" style="margin-bottom:16px;">
    <i class="fas fa-triangle-exclamation"></i>
    <div>
        <strong><?php echo $low_stock_count; ?> products</strong> are at or below reorder level.
        <a href="<?php echo BASE_URL; ?>/modules/inventory/alerts.php" style="margin-left:8px;font-weight:600;color:#92400E;text-decoration:underline;">View Alerts →</a>
    </div>
</div>
<?php endif; ?>
<?php if ($dead_stock_count > 0): ?>
<div class="alert" style="background:var(--purple-light);border:1.5px solid #DDD6FE;color:#6D28D9;margin-bottom:16px;padding:12px 16px;border-radius:8px;display:flex;align-items:flex-start;gap:10px;font-size:13px;">
    <i class="fas fa-box-archive"></i>
    <div>
        <strong><?php echo $dead_stock_count; ?> products</strong> haven't sold in the last 90 days.
        <a href="<?php echo BASE_URL; ?>/modules/inventory/alerts.php?tab=dead" style="margin-left:8px;font-weight:600;color:#6D28D9;text-decoration:underline;">View Dead Stock →</a>
    </div>
</div>
<?php endif; ?>

<?php if ($birthday_today > 0): ?>
<div class="alert" style="background:var(--purple-light);border:1.5px solid #DDD6FE;color:#6D28D9;margin-bottom:16px;padding:12px 16px;border-radius:8px;display:flex;align-items:flex-start;gap:10px;font-size:13px;">
    <i class="fas fa-cake-candles"></i>
    <div>
        <strong><?php echo $birthday_today; ?> customer<?php echo $birthday_today != 1 ? 's' : ''; ?></strong> have a birthday today.
        <a href="<?php echo BASE_URL; ?>/modules/birthday/index.php" style="margin-left:8px;font-weight:600;color:#6D28D9;text-decoration:underline;">View Birthday List →</a>
    </div>
</div>
<?php endif; ?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h1>Dashboard</h1>
        <div class="sub">Welcome back, <?php echo sanitize($user['name'] ?? 'User'); ?> — <?php echo date('l, d F Y'); ?></div>
    </div>
    <div class="page-header-actions">
        <?php if (isAdmin()): ?>
            <?php $branches = $pdo->query("SELECT id, name FROM branches WHERE status='active' ORDER BY name ASC")->fetchAll(); ?>
            <form method="GET">
                <select name="branch_id" class="form-control" style="width:180px;" onchange="this.form.submit()">
                    <option value="0" <?php echo $selected_branch_filter == 0 ? 'selected' : ''; ?>>All Branches</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch_filter == $b['id'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($b['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <a href="<?php echo BASE_URL; ?>/modules/billing/create.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> New Bill
        </a>
    </div>
</div>

<!-- Quick Actions (non-admin) -->
<?php if (!isAdmin()): ?>
<div class="grid-4" style="margin-bottom:20px;">
    <?php
    $icon_styles = [
        'orange' => 'background:#FFF7ED;color:var(--primary)',
        'green'  => 'background:var(--success-light);color:var(--success)',
        'blue'   => 'background:var(--secondary-light);color:var(--secondary)',
        'purple' => 'background:var(--purple-light);color:var(--purple)',
        'red'    => 'background:var(--danger-light);color:var(--danger)',
    ];
    if (isCashier()) {
        // Cashier dashboard actions (spec). Exchange/Defective appear once those modules exist.
        $qa = [
            ['url' => BASE_URL.'/modules/billing/create.php',   'icon' => 'fa-cash-register',     'label' => 'New Bill',        'color' => 'orange'],
            ['url' => BASE_URL.'/modules/customers/search.php', 'icon' => 'fa-magnifying-glass',  'label' => 'Customer Search', 'color' => 'blue'],
            ['url' => BASE_URL.'/modules/billing/today.php',    'icon' => 'fa-calendar-day',      'label' => "Today's Sales",   'color' => 'green'],
            ['url' => BASE_URL.'/modules/birthday/index.php',   'icon' => 'fa-cake-candles',      'label' => 'Birthday List',   'color' => 'purple'],
        ];
        if (file_exists(__DIR__.'/../exchange/index.php')) {
            $qa[] = ['url' => BASE_URL.'/modules/exchange/index.php', 'icon' => 'fa-right-left', 'label' => 'Exchange / Replace', 'color' => 'orange'];
        }
    } else {
        // Staff / branch admin actions (unchanged).
        $qa = [
            ['url' => BASE_URL.'/modules/billing/create.php',    'icon' => 'fa-cash-register', 'label' => 'New Bill',    'color' => 'orange'],
            ['url' => BASE_URL.'/modules/inventory/stock_in.php', 'icon' => 'fa-plus-circle',   'label' => 'Stock In',   'color' => 'green'],
            ['url' => BASE_URL.'/modules/products/add.php',       'icon' => 'fa-box',            'label' => 'Add Product','color' => 'blue'],
            ['url' => BASE_URL.'/modules/expenses/add.php',       'icon' => 'fa-receipt',        'label' => 'Add Expense','color' => 'purple'],
        ];
    }
    foreach ($qa as $q): ?>
        <a href="<?php echo $q['url']; ?>" style="background:var(--surface);border:1.5px solid var(--border);border-radius:12px;padding:18px 16px;text-align:center;text-decoration:none;display:block;transition:all 0.2s;box-shadow:var(--shadow-sm);"
           onmouseover="this.style.borderColor='var(--primary)';this.style.transform='translateY(-2px)';this.style.boxShadow='var(--shadow-md)'"
           onmouseout="this.style.borderColor='var(--border)';this.style.transform='';this.style.boxShadow='var(--shadow-sm)'">
            <div style="width:44px;height:44px;border-radius:10px;margin:0 auto 10px;display:flex;align-items:center;justify-content:center;font-size:18px;<?php echo $icon_styles[$q['color']]; ?>">
                <i class="fas <?php echo $q['icon']; ?>"></i>
            </div>
            <div style="font-size:13px;font-weight:600;color:var(--on-surface);"><?php echo $q['label']; ?></div>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- KPI Stats -->
<div class="stat-grid" style="margin-bottom:20px;">
    <div class="stat-card orange">
        <div class="stat-top">
            <div class="stat-icon"><i class="fas fa-indian-rupee-sign"></i></div>
            <span class="badge-pill badge-primary" style="font-size:10px;">Today</span>
        </div>
        <div class="stat-value"><?php echo formatCurrency($today_sales); ?></div>
        <div class="stat-label">Today's Sales</div>
    </div>
    <div class="stat-card green">
        <div class="stat-top">
            <div class="stat-icon"><i class="fas fa-arrow-trend-up"></i></div>
            <span class="badge-pill badge-success" style="font-size:10px;">Today</span>
        </div>
        <div class="stat-value"><?php echo formatCurrency($today_profit); ?></div>
        <div class="stat-label">Today's Profit</div>
    </div>
    <div class="stat-card blue">
        <div class="stat-top">
            <div class="stat-icon"><i class="fas fa-calendar-days"></i></div>
            <span class="badge-pill badge-secondary" style="font-size:10px;"><?php echo date('M Y'); ?></span>
        </div>
        <div class="stat-value"><?php echo formatCurrency($monthly_sales); ?></div>
        <div class="stat-label">Monthly Sales</div>
    </div>
    <div class="stat-card red">
        <div class="stat-top">
            <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
            <?php if ($pending_credits > 0): ?>
                <span class="badge-pill badge-danger" style="font-size:10px;">Due</span>
            <?php endif; ?>
        </div>
        <div class="stat-value"><?php echo formatCurrency($pending_credits); ?></div>
        <div class="stat-label">Pending Credits</div>
    </div>
</div>

<!-- Main Content Grid -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:20px;">

    <!-- Sales Chart -->
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">Sales This Week</div>
                <div class="card-subtitle">Daily revenue for the last 7 days</div>
            </div>
            <a href="<?php echo BASE_URL; ?>/modules/reports/sales.php" class="btn btn-outline btn-sm">
                <i class="fas fa-arrow-right"></i> Full Report
            </a>
        </div>
        <div style="height:260px;">
            <canvas id="salesChart"></canvas>
        </div>
    </div>

    <!-- Inventory & Top Products -->
    <div style="display:flex;flex-direction:column;gap:16px;">

        <!-- Inventory Status -->
        <div class="card" style="flex:0 0 auto;">
            <div class="card-header" style="margin-bottom:12px;">
                <div class="card-title">Inventory Status</div>
                <a href="<?php echo BASE_URL; ?>/modules/inventory/index.php" class="btn btn-ghost btn-sm">View</a>
            </div>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:13px;color:var(--on-surface-muted);">Total Active Products</span>
                    <strong style="font-size:14px;"><?php echo $total_products; ?></strong>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:13px;color:var(--on-surface-muted);">Low Stock</span>
                    <a href="<?php echo BASE_URL; ?>/modules/inventory/alerts.php" class="badge-pill <?php echo $low_stock_count > 0 ? 'badge-danger' : 'badge-success'; ?>" style="text-decoration:none;">
                        <?php if ($low_stock_count > 0): ?><i class="fas fa-triangle-exclamation" style="font-size:10px;"></i><?php endif; ?>
                        <?php echo $low_stock_count; ?> items
                    </a>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:13px;color:var(--on-surface-muted);">Dead Stock</span>
                    <a href="<?php echo BASE_URL; ?>/modules/inventory/alerts.php?tab=dead" class="badge-pill <?php echo $dead_stock_count > 0 ? 'badge-warning' : 'badge-success'; ?>" style="text-decoration:none;">
                        <?php if ($dead_stock_count > 0): ?><i class="fas fa-box-archive" style="font-size:10px;"></i><?php endif; ?>
                        <?php echo $dead_stock_count; ?> items
                    </a>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:13px;color:var(--on-surface-muted);">Defective Stock</span>
                    <a href="<?php echo BASE_URL; ?>/modules/inventory/stock_check.php" class="badge-pill <?php echo $defective_units > 0 ? 'badge-danger' : 'badge-success'; ?>" style="text-decoration:none;">
                        <?php if ($defective_units > 0): ?><i class="fas fa-ban" style="font-size:10px;"></i><?php endif; ?>
                        <?php echo $defective_units; ?> units
                    </a>
                </div>
            </div>
        </div>

        <!-- Top Products -->
        <div class="card" style="flex:1;">
            <div class="card-header" style="margin-bottom:12px;">
                <div class="card-title">Top Products <span style="font-size:11px;color:var(--on-surface-subtle);font-weight:400;">(this month)</span></div>
            </div>
            <?php if (empty($top_products)): ?>
                <p style="font-size:13px;color:var(--on-surface-subtle);text-align:center;padding:16px 0;">No sales data yet</p>
            <?php else: ?>
                <?php foreach ($top_products as $i => $tp): ?>
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                        <span style="width:20px;height:20px;border-radius:50%;background:var(--primary-light);color:var(--primary);font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?php echo $i+1; ?></span>
                        <span style="flex:1;font-size:13px;color:var(--on-surface);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo sanitize($tp['name']); ?></span>
                        <span style="font-size:12px;font-weight:600;color:var(--primary);"><?php echo $tp['total_sold']; ?> sold</span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Bottom Grid: Recent Bills + Low Stock -->
<div style="display:grid;grid-template-columns:3fr 2fr;gap:20px;margin-bottom:20px;">

    <!-- Recent Bills -->
    <div class="table-wrapper">
        <div class="table-toolbar">
            <span class="card-title">Recent Transactions</span>
            <a href="<?php echo BASE_URL; ?>/modules/billing/history.php" class="btn btn-ghost btn-sm" style="margin-left:auto;">View All <i class="fas fa-arrow-right"></i></a>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Bill #</th>
                    <th>Customer</th>
                    <th>Amount</th>
                    <th>Type</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_bills)): ?>
                    <tr><td colspan="5"><div class="empty-state" style="padding:32px;"><i class="fas fa-receipt empty-icon"></i><h3>No bills yet</h3><p>Create your first bill to see it here.</p></div></td></tr>
                <?php else: ?>
                    <?php foreach ($recent_bills as $bill): ?>
                        <tr>
                            <td><span style="font-weight:600;color:var(--primary);"><?php echo $bill['bill_number']; ?></span></td>
                            <td><?php echo $bill['customer_name'] ?: '<span style="color:var(--on-surface-subtle)">Walk-in</span>'; ?></td>
                            <td><strong><?php echo formatCurrency($bill['total_amount']); ?></strong></td>
                            <td>
                                <?php
                                $type_badge_map = [
                                    'cash'   => 'badge-info',
                                    'credit' => 'badge-warning',
                                    'online' => 'badge-success',
                                    'card'   => 'badge-secondary',
                                ];
                                $tb = $type_badge_map[$bill['bill_type']] ?? 'badge-info';
                                ?>
                                <span class="badge-pill <?php echo $tb; ?>">
                                    <?php echo ucfirst($bill['bill_type']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge-pill <?php
                                    echo match($bill['status']) {
                                        'paid'      => 'badge-success',
                                        'cancelled' => 'badge-danger',
                                        'partial'   => 'badge-warning',
                                        default     => 'badge-warning',
                                    };
                                ?>">
                                    <?php echo ucfirst($bill['status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Low Stock Alerts -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fas fa-triangle-exclamation" style="color:var(--warning);margin-right:6px;"></i>Low Stock</div>
            <a href="<?php echo BASE_URL; ?>/modules/inventory/alerts.php" class="btn btn-ghost btn-sm">View All</a>
        </div>
        <?php if (empty($low_stock_products)): ?>
            <div class="empty-state" style="padding:24px;">
                <i class="fas fa-check-circle empty-icon" style="color:var(--success);"></i>
                <h3>All Good!</h3>
                <p>No products are low on stock.</p>
            </div>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:8px;">
                <?php foreach ($low_stock_products as $p): ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:var(--surface-variant);border-radius:8px;">
                        <div>
                            <div style="font-size:13px;font-weight:500;color:var(--on-surface);"><?php echo sanitize($p['name']); ?></div>
                            <div style="font-size:11px;color:var(--on-surface-subtle);">Alert: <?php echo $p['alert_quantity']; ?> <?php echo $p['unit']; ?></div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:14px;font-weight:700;color:<?php echo $p['quantity'] <= 0 ? 'var(--danger)' : 'var(--warning)'; ?>">
                                <?php echo $p['quantity']; ?>
                            </div>
                            <a href="<?php echo BASE_URL; ?>/modules/inventory/stock_in.php?product_id=<?php echo $p['id']; ?>" style="font-size:11px;color:var(--primary);font-weight:600;">Stock In</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Net Profit Widget -->
<div class="card" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;">
    <div style="display:flex;align-items:center;gap:32px;flex-wrap:wrap;">
        <div>
            <div style="font-size:11px;color:var(--on-surface-subtle);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;">Monthly Gross Profit</div>
            <div style="font-size:20px;font-weight:700;color:var(--primary);"><?php echo formatCurrency($monthly_gross); ?></div>
        </div>
        <div style="width:1px;height:40px;background:var(--border);"></div>
        <div>
            <div style="font-size:11px;color:var(--on-surface-subtle);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;">Monthly Expenses</div>
            <div style="font-size:20px;font-weight:700;color:var(--danger);"><?php echo formatCurrency($monthly_expenses); ?></div>
        </div>
    </div>
    <div style="text-align:right;">
        <div style="font-size:11px;color:var(--on-surface-subtle);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;">Net Result — <?php echo date('M Y'); ?></div>
        <div style="font-size:26px;font-weight:800;color:<?php echo $monthly_net >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
            <?php echo ($monthly_net >= 0 ? '' : '-') . formatCurrency(abs($monthly_net)); ?>
        </div>
    </div>
</div>

<!-- Branch Performance (Superadmin) -->
<?php if (isAdmin() && !empty($branch_performance)): ?>
<div style="margin-top:20px;">
    <div style="font-size:14px;font-weight:600;color:var(--on-surface);margin-bottom:12px;">Branch Performance Today</div>
    <div class="grid-4">
        <?php foreach ($branch_performance as $bp): ?>
            <div class="stat-card" style="padding:16px;">
                <div style="font-size:11px;color:var(--on-surface-subtle);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;"><?php echo sanitize($bp['name']); ?></div>
                <div style="font-size:18px;font-weight:700;color:var(--on-surface);"><?php echo formatCurrency($bp['today_sales']); ?></div>
                <div style="font-size:11px;color:var(--on-surface-subtle);margin-top:2px;">Today's sales</div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Trigger notification generation silently
    fetch('<?php echo BASE_URL; ?>/modules/notifications/generate.php');

    const ctx = document.getElementById('salesChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [{
                label: 'Daily Sales (₹)',
                data: <?php echo json_encode($chart_values); ?>,
                backgroundColor: 'rgba(249,115,22,0.15)',
                borderColor: '#F97316',
                borderWidth: 2,
                borderRadius: 6,
                hoverBackgroundColor: 'rgba(249,115,22,0.35)',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1E293B',
                    titleColor: '#F1F5F9',
                    bodyColor: '#CBD5E1',
                    borderColor: '#334155',
                    borderWidth: 1,
                    padding: 12,
                    displayColors: false,
                    callbacks: {
                        label: ctx => '₹ ' + ctx.parsed.y.toLocaleString('en-IN', {minimumFractionDigits: 2})
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: '#94A3B8', font: { size: 12, family: 'Inter' } }
                },
                y: {
                    grid: { color: '#F1F5F9', drawBorder: false },
                    ticks: {
                        color: '#94A3B8',
                        font: { size: 11, family: 'Inter' },
                        callback: v => v >= 1000 ? '₹' + (v/1000).toFixed(1) + 'k' : '₹' + v
                    }
                }
            }
        }
    });
});
</script>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
