<?php
/**
 * Customer Search — look up a customer by mobile number and show their
 * profile, previous bills, purchase history (and, once those modules exist,
 * exchange & defective history). Cashier-accessible per spec.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle  = 'Customer Search';
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Customer Search</span>';

$isAdmin   = isAdmin();
$branch_id = (int)($_SESSION['branch_id'] ?? 0);

/** True if a table exists in the current database (for forward-compatible sections). */
function tableExists(PDO $pdo, string $table): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $s->execute([$table]);
    return (bool)$s->fetchColumn();
}

$mobile     = trim($_GET['mobile'] ?? '');
$searched   = ($mobile !== '');
$customer   = null;
$bills      = [];
$items      = [];
$exchanges  = null;   // null = section hidden (table absent); array = rows
$defectives = null;
$kpi        = ['bills' => 0, 'spent' => 0, 'due' => 0];

if ($searched) {
    // Branch scope: non-admin restricted to their branch; superadmin sees all.
    $billScope = $isAdmin ? '' : ' AND branch_id = ' . $branch_id;
    $custScope = $isAdmin ? '' : ' AND branch_id = ' . $branch_id;

    // 1. Customer master record (for name / DOB / address).
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE mobile = ?$custScope ORDER BY id ASC LIMIT 1");
    $stmt->execute([$mobile]);
    $customer = $stmt->fetch();

    // 2. Previous bills (match by phone so legacy bills without customer_id are included).
    $stmt = $pdo->prepare("SELECT id, bill_number, customer_name, bill_type, status, total_amount, paid_amount, employee_name, created_at
                           FROM bills WHERE customer_phone = ?$billScope ORDER BY created_at DESC");
    $stmt->execute([$mobile]);
    $bills = $stmt->fetchAll();

    // 3. KPIs from those bills.
    foreach ($bills as $b) {
        if ($b['status'] === 'cancelled') continue;
        $kpi['bills']++;
        $kpi['spent'] += (float)$b['total_amount'];
        if ($b['bill_type'] === 'credit') {
            $kpi['due'] += (float)$b['total_amount'] - (float)$b['paid_amount'];
        }
    }

    // 4. Purchase history (line items across those bills).
    $stmt = $pdo->prepare("SELECT bi.product_name, bi.quantity, bi.selling_price, bi.discount, bi.total,
                                  b.bill_number, b.created_at
                           FROM bill_items bi
                           JOIN bills b ON bi.bill_id = b.id
                           WHERE b.customer_phone = ?$billScope AND b.status != 'cancelled'
                           ORDER BY b.created_at DESC, bi.id ASC");
    $stmt->execute([$mobile]);
    $items = $stmt->fetchAll();

    // 5. Exchange / Defective history — only when those modules (tables) exist.
    if ($customer && tableExists($pdo, 'exchanges')) {
        try {
            $scope = $isAdmin ? '' : ' AND branch_id = ' . $branch_id;
            $stmt = $pdo->prepare("SELECT * FROM exchanges WHERE customer_id = ?$scope ORDER BY created_at DESC");
            $stmt->execute([$customer['id']]);
            $exchanges = $stmt->fetchAll();
        } catch (Exception $e) { $exchanges = null; }
    }
    if ($customer && tableExists($pdo, 'defective_replacements')) {
        try {
            $scope = $isAdmin ? '' : ' AND branch_id = ' . $branch_id;
            $stmt = $pdo->prepare("SELECT * FROM defective_replacements WHERE customer_id = ?$scope ORDER BY created_at DESC");
            $stmt->execute([$customer['id']]);
            $defectives = $stmt->fetchAll();
        } catch (Exception $e) { $defectives = null; }
    }
}

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1><?php echo $pageTitle; ?></h1>
    <a href="<?php echo BASE_URL; ?>/modules/customers/index.php" class="btn btn-outline btn-sm">
        <i class="fas fa-users"></i> Credit Customers
    </a>
</div>

<div class="table-wrapper mb-4">
    <form action="" method="GET" style="display:flex;gap:10px;flex-wrap:wrap;padding:6px;">
        <div style="position:relative;flex:1;min-width:220px;max-width:360px;">
            <i class="fas fa-mobile-screen-button" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--on-surface-subtle);font-size:13px;pointer-events:none;"></i>
            <input type="text" name="mobile" class="form-control" placeholder="Enter mobile number…"
                   value="<?php echo sanitize($mobile); ?>" autofocus autocomplete="off" style="padding-left:36px;height:44px;">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-magnifying-glass"></i> Search</button>
        <?php if ($searched): ?>
            <a href="search.php" class="btn btn-ghost">Clear</a>
        <?php endif; ?>
    </form>
</div>

<?php if (!$searched): ?>
    <div class="table-wrapper">
        <div class="empty-state" style="padding:60px 20px;text-align:center;">
            <i class="fas fa-magnifying-glass empty-icon" style="font-size:36px;color:var(--border);display:block;margin-bottom:14px;"></i>
            <h3>Search a customer by mobile</h3>
            <p>Enter a mobile number above to see their profile, previous bills and purchase history.</p>
        </div>
    </div>

<?php elseif (empty($bills) && !$customer): ?>
    <div class="table-wrapper">
        <div class="empty-state" style="padding:60px 20px;text-align:center;">
            <i class="fas fa-user-slash empty-icon" style="font-size:36px;color:var(--border);display:block;margin-bottom:14px;"></i>
            <h3>No customer found</h3>
            <p>No customer or bills match the mobile number <strong><?php echo sanitize($mobile); ?></strong>.</p>
        </div>
    </div>

<?php else: ?>

    <!-- Customer details -->
    <div class="d-flex justify-between align-center mb-4" style="flex-wrap:wrap;gap:12px;">
        <div>
            <h1 class="mb-0"><?php echo sanitize($customer['name'] ?? ($bills[0]['customer_name'] ?? 'Customer')); ?></h1>
            <div class="text-muted fs-14">
                <i class="fas fa-phone" style="font-size:11px;"></i> <?php echo sanitize($mobile); ?>
                <?php if (!empty($customer['date_of_birth'])): ?>
                    &nbsp;|&nbsp; <i class="fas fa-cake-candles" style="font-size:11px;"></i> <?php echo formatDate($customer['date_of_birth']); ?>
                <?php endif; ?>
                <?php if (!empty($customer['address'])): ?>
                    &nbsp;|&nbsp; <i class="fas fa-location-dot" style="font-size:11px;"></i> <?php echo sanitize($customer['address']); ?>
                <?php endif; ?>
            </div>
            <?php if (!$customer): ?>
                <div class="fs-11 text-muted mt-1"><i class="fas fa-circle-info"></i> Legacy bills only — no saved customer profile for this number yet.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPIs -->
    <div class="stat-grid mb-5">
        <div class="stat-card blue">
            <div class="stat-value"><?php echo $kpi['bills']; ?></div>
            <div class="stat-label">Total Bills</div>
        </div>
        <div class="stat-card green">
            <div class="stat-value"><?php echo formatCurrency($kpi['spent']); ?></div>
            <div class="stat-label">Total Spent</div>
        </div>
        <div class="stat-card <?php echo $kpi['due'] > 0 ? 'red' : 'green'; ?>">
            <div class="stat-value"><?php echo formatCurrency($kpi['due']); ?></div>
            <div class="stat-label">Outstanding (Credit)</div>
        </div>
    </div>

    <!-- Previous bills -->
    <h3 class="pos-section-title mb-3"><i class="fas fa-file-invoice"></i> Previous Bills (<?php echo count($bills); ?>)</h3>
    <div class="table-wrapper mb-5">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Bill #</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Employee</th>
                    <th class="text-end">Amount</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($bills)): ?>
                    <tr><td colspan="7" class="text-center py-5 text-muted">No bills found for this number.</td></tr>
                <?php else: foreach ($bills as $b): ?>
                    <tr>
                        <td class="fw-600"><?php echo sanitize($b['bill_number']); ?></td>
                        <td class="fs-12"><?php echo formatDateTime($b['created_at']); ?></td>
                        <td><span class="badge-pill badge-info"><?php echo ucfirst($b['bill_type']); ?></span></td>
                        <td>
                            <span class="badge-pill <?php echo $b['status'] === 'cancelled' ? 'badge-danger' : ($b['status'] === 'credit' ? 'badge-warning' : 'badge-success'); ?>">
                                <?php echo ucfirst($b['status']); ?>
                            </span>
                        </td>
                        <td class="fs-12"><?php echo $b['employee_name'] ? sanitize($b['employee_name']) : '<span class="text-muted">-</span>'; ?></td>
                        <td class="text-end fw-600"><?php echo formatCurrency($b['total_amount']); ?></td>
                        <td class="text-end">
                            <a href="../billing/invoice.php?id=<?php echo $b['id']; ?>" class="btn btn-ghost btn-icon btn-sm text-accent" title="View Invoice"><i class="fas fa-eye"></i></a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Purchase history -->
    <h3 class="pos-section-title mb-3"><i class="fas fa-bag-shopping"></i> Products Purchased (<?php echo count($items); ?>)</h3>
    <div class="table-wrapper mb-5">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Bill #</th>
                    <th>Date</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Price</th>
                    <th class="text-end">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">No purchases found.</td></tr>
                <?php else: foreach ($items as $it): ?>
                    <tr>
                        <td class="fw-600"><?php echo sanitize($it['product_name']); ?></td>
                        <td class="fs-12"><?php echo sanitize($it['bill_number']); ?></td>
                        <td class="fs-12"><?php echo formatDate($it['created_at']); ?></td>
                        <td class="text-end"><?php echo (int)$it['quantity']; ?></td>
                        <td class="text-end"><?php echo formatCurrency($it['selling_price']); ?></td>
                        <td class="text-end fw-600"><?php echo formatCurrency($it['total']); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($exchanges !== null): ?>
    <!-- Exchange history (auto-enabled once the Exchange module exists) -->
    <h3 class="pos-section-title mb-3"><i class="fas fa-right-left"></i> Exchange History (<?php echo count($exchanges); ?>)</h3>
    <div class="table-wrapper mb-5">
        <table class="data-table">
            <thead><tr><th>Date</th><th>Old → New</th><th class="text-end">Return Value</th><th class="text-end">Difference Paid</th></tr></thead>
            <tbody>
                <?php if (empty($exchanges)): ?>
                    <tr><td colspan="4" class="text-center py-5 text-muted">No exchanges for this customer.</td></tr>
                <?php else: foreach ($exchanges as $ex): ?>
                    <tr>
                        <td class="fs-12"><?php echo formatDate($ex['created_at']); ?></td>
                        <td class="fs-12">#<?php echo (int)($ex['old_product_id'] ?? 0); ?> → #<?php echo (int)($ex['new_product_id'] ?? 0); ?></td>
                        <td class="text-end"><?php echo formatCurrency($ex['return_value'] ?? 0); ?></td>
                        <td class="text-end"><?php echo formatCurrency($ex['difference_paid'] ?? 0); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($defectives !== null): ?>
    <!-- Defective replacement history (auto-enabled once that module exists) -->
    <h3 class="pos-section-title mb-3"><i class="fas fa-triangle-exclamation"></i> Defective Replacement History (<?php echo count($defectives); ?>)</h3>
    <div class="table-wrapper mb-5">
        <table class="data-table">
            <thead><tr><th>Date</th><th>Defect Reason</th><th class="text-end">Final Sale Price</th></tr></thead>
            <tbody>
                <?php if (empty($defectives)): ?>
                    <tr><td colspan="3" class="text-center py-5 text-muted">No defective replacements for this customer.</td></tr>
                <?php else: foreach ($defectives as $d): ?>
                    <tr>
                        <td class="fs-12"><?php echo formatDate($d['created_at']); ?></td>
                        <td class="fs-12"><?php echo sanitize($d['defect_reason'] ?? ''); ?></td>
                        <td class="text-end"><?php echo formatCurrency($d['final_sale_price'] ?? 0); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

<?php endif; ?>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
