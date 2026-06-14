<?php
/**
 * Inventory Management Index
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = 'Inventory';
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Inventory</span>';

$branch_id = $_SESSION['branch_id'];
$branch_filter = isAdmin() ? "" : " AND branch_id = " . (int)$branch_id;

// 1. Stat Calculations
$stmt = $pdo->prepare("SELECT SUM(quantity * purchase_price) as total_value, COUNT(*) as total_products FROM products WHERE status='active' " . (isAdmin() ? "" : " AND branch_id = ?"));
if (!isAdmin()) $stmt->execute([$branch_id]); else $stmt->execute();
$stats = $stmt->fetch();
$total_stock_value = $stats['total_value'] ?? 0;
$products_in_stock = $stats['total_products'] ?? 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE status='active' AND quantity <= alert_quantity " . (isAdmin() ? "" : " AND branch_id = ?"));
if (!isAdmin()) $stmt->execute([$branch_id]); else $stmt->execute();
$low_stock_count = $stmt->fetchColumn();

// 2. Fetch Products for Inventory List
$search = sanitize($_GET['search'] ?? '');
$cat_id = (int)($_GET['category_id'] ?? 0);
$tab = $_GET['tab'] ?? 'all';

$params = [];
$where = ["p.status = 'active'"];
if (!isAdmin()) {
    $where[] = "p.branch_id = ?";
    $params[] = $branch_id;
}

if ($search) {
    $where[] = "(p.name LIKE ? OR p.sku LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($cat_id) {
    $where[] = "p.category_id = ?";
    $params[] = $cat_id;
}

if ($tab === 'low') {
    $where[] = "p.quantity <= p.alert_quantity";
} elseif ($tab === 'dead') {
    $dead_days = (int)getSettingValue('dead_stock_days') ?: 90;
    $where[] = "p.id NOT IN (SELECT product_id FROM bill_items bi JOIN bills b ON bi.bill_id = b.id WHERE b.created_at >= DATE_SUB(NOW(), INTERVAL $dead_days DAY))";
}

$where_sql = implode(" AND ", $where);
$query = "SELECT p.*, c.name as category_name, 
          (SELECT created_at FROM inventory_log WHERE product_id = p.id ORDER BY created_at DESC LIMIT 1) as last_movement 
          FROM products p 
          LEFT JOIN categories c ON p.category_id = c.id 
          WHERE $where_sql 
          ORDER BY p.name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $pdo->query("SELECT id, name FROM categories WHERE status=1 ORDER BY name ASC")->fetchAll();
$csrf_token = generateCSRFToken();

include_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h1>Inventory Overview</h1>
        <div class="sub">Stock levels, movements, and alerts</div>
    </div>
    <div class="page-header-actions">
        <a href="stock_in.php"  class="btn btn-success btn-sm"><i class="fas fa-plus-circle"></i> Stock In</a>
        <a href="stock_out.php" class="btn btn-outline btn-sm"><i class="fas fa-minus-circle"></i> Stock Out</a>
        <a href="history.php"   class="btn btn-ghost btn-sm"><i class="fas fa-history"></i> History</a>
    </div>
</div>

<!-- Stat Row -->
<div class="stat-grid" style="grid-template-columns:repeat(3,1fr);">
    <div class="stat-card orange">
        <div class="stat-top"><div class="stat-icon"><i class="fas fa-indian-rupee-sign"></i></div></div>
        <div class="stat-value"><?php echo formatCurrency($total_stock_value); ?></div>
        <div class="stat-label">Total Stock Value</div>
    </div>
    <div class="stat-card green">
        <div class="stat-top"><div class="stat-icon"><i class="fas fa-boxes-stacked"></i></div></div>
        <div class="stat-value"><?php echo $products_in_stock; ?></div>
        <div class="stat-label">Products In Stock</div>
    </div>
    <div class="stat-card red">
        <div class="stat-top"><div class="stat-icon"><i class="fas fa-triangle-exclamation"></i></div></div>
        <div class="stat-value"><?php echo $low_stock_count; ?></div>
        <div class="stat-label">Low Stock Items</div>
    </div>
</div>

<div class="table-wrapper">
    <!-- Tabs & Filters -->
    <div class="table-toolbar" style="flex-wrap:wrap;gap:10px;">
        <div class="tabs" style="margin-bottom:0;">
            <a href="?tab=all" class="tab-btn <?php echo $tab==='all'?'active':''; ?>" data-tab-group="inv">All Items</a>
            <a href="?tab=low" class="tab-btn <?php echo $tab==='low'?'active':''; ?>" data-tab-group="inv"><i class="fas fa-triangle-exclamation" style="color:var(--warning);"></i> Low Stock</a>
            <a href="?tab=dead" class="tab-btn <?php echo $tab==='dead'?'active':''; ?>" data-tab-group="inv"><i class="fas fa-box-archive" style="color:var(--purple);"></i> Dead Stock</a>
        </div>

        <form action="" method="GET" class="d-flex gap-2 flex-grow-1">
            <input type="hidden" name="tab" value="<?php echo $tab; ?>">
            <input type="text" name="search" class="form-control" placeholder="Search product..." value="<?php echo $search; ?>" style="max-width: 200px;">
            <select name="category_id" class="form-control" style="max-width: 150px;">
                <option value="">Category</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $cat_id == $cat['id'] ? 'selected' : ''; ?>><?php echo $cat['name']; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline btn-icon"><i class="fas fa-search"></i></button>
        </form>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>Category</th>
                <th>Current Qty</th>
                <th>Alert Qty</th>
                <th>Last Movement</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td>
                        <div class="fw-600"><?php echo sanitize($p['name']); ?></div>
                        <div class="fs-11 text-muted">SKU: <?php echo $p['sku']; ?></div>
                    </td>
                    <td><span class="fs-13"><?php echo $p['category_name'] ?: 'N/A'; ?></span></td>
                    <td>
                        <?php 
                        $qty = $p['quantity'];
                        $badge = $qty <= 0 ? 'badge-danger fw-700' : ($qty <= $p['alert_quantity'] ? 'badge-warning' : 'badge-success');
                        ?>
                        <span class="badge-pill <?php echo $badge; ?>"><?php echo $qty; ?> <?php echo $p['unit']; ?></span>
                    </td>
                    <td>
                        <div class="d-flex align-center gap-1">
                            <?php echo $p['alert_quantity']; ?>
                            <?php if ($qty <= $p['alert_quantity']): ?>
                                <i class="fas fa-triangle-exclamation text-warning" title="Below alert level"></i>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="fs-12 text-muted"><?php echo $p['last_movement'] ? formatDateTime($p['last_movement']) : 'No movement'; ?></td>
                    <td class="text-end">
                        <div class="d-flex justify-end gap-1">
                            <button onclick="openStockModal('in', <?php echo $p['id']; ?>, '<?php echo addslashes($p['name']); ?>', <?php echo $p['quantity']; ?>)" class="btn btn-ghost btn-icon btn-sm text-success" title="Stock In"><i class="fas fa-plus-circle"></i></button>
                            <button onclick="openStockModal('out', <?php echo $p['id']; ?>, '<?php echo addslashes($p['name']); ?>', <?php echo $p['quantity']; ?>)" class="btn btn-ghost btn-icon btn-sm text-danger" title="Stock Out"><i class="fas fa-minus-circle"></i></button>
                            <a href="adjust.php?product_id=<?php echo $p['id']; ?>" class="btn btn-ghost btn-icon btn-sm text-warning" title="Adjust"><i class="fas fa-sliders"></i></a>
                            <a href="history.php?product_id=<?php echo $p['id']; ?>" class="btn btn-ghost btn-icon btn-sm text-info" title="History"><i class="fas fa-history"></i></a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Quick Stock Modal -->
<div id="stockModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Stock In</h3>
            <button class="modal-close" onclick="closeModal('stockModal')">&times;</button>
        </div>
        <form action="" id="stockForm" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="product_id" id="modalProdId">
            <input type="hidden" name="action_type" id="modalActionType">
            
            <div class="mb-3">
                <label class="form-label">Product</label>
                <div id="modalProdName" class="fw-600 fs-16 text-primary"></div>
            </div>

            <div class="form-group mb-3">
                <label class="form-label">Quantity*</label>
                <input type="number" name="quantity" id="modalQty" class="form-control" required min="1">
                <div class="form-hint" id="modalStockPreview">Current: 0 → After: 0</div>
            </div>

            <div class="form-group mb-3">
                <label class="form-label">Note / Reference</label>
                <textarea name="note" class="form-control" rows="2" placeholder="Reason for stock movement..."></textarea>
            </div>

            <div class="d-flex justify-end gap-2 mt-4">
                <button type="button" class="btn btn-outline" onclick="closeModal('stockModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="modalSubmitBtn">Confirm</button>
            </div>
        </form>
    </div>
</div>

<script>
let currentStock = 0;
let actionType = 'in';

function openStockModal(type, id, name, qty) {
    actionType = type;
    currentStock = qty;
    document.getElementById('modalTitle').innerText = type === 'in' ? 'Stock In' : 'Stock Out';
    document.getElementById('modalActionType').value = type;
    document.getElementById('modalProdId').value = id;
    document.getElementById('modalProdName').innerText = name;
    document.getElementById('modalQty').value = '';
    document.getElementById('modalStockPreview').innerText = `Current: ${qty} → After: ${qty}`;
    document.getElementById('modalSubmitBtn').className = type === 'in' ? 'btn btn-success' : 'btn btn-danger';
    
    // Update action URL
    document.getElementById('stockForm').action = type === 'in' ? 'stock_in.php' : 'stock_out.php';
    
    openModal('stockModal');
}

document.getElementById('modalQty').addEventListener('input', function() {
    const val = parseInt(this.value) || 0;
    const newQty = actionType === 'in' ? (currentStock + val) : (currentStock - val);
    document.getElementById('modalStockPreview').innerText = `Current: ${currentStock} → After: ${newQty}`;
});
</script>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
