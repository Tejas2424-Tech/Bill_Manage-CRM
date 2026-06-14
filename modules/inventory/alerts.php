<?php
/**
 * Stock Alerts, Low Stock and Dead Stock Reporting
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = 'Stock Alerts';
$breadcrumb = '<a href="' . BASE_URL . '/modules/inventory/index.php">Inventory</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Alerts</span>';

$tab = $_GET['tab'] ?? 'low';
$branch_id = $_SESSION['branch_id'];
$branch_filter = isAdmin() ? "" : " AND branch_id = " . (int)$branch_id;

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $tab === 'dead') {
    $dead_days = (int)($_GET['days'] ?? 90);
    $query = "SELECT p.name, c.name as category, p.quantity, p.purchase_price, (p.quantity * p.purchase_price) as stock_value 
              FROM products p 
              LEFT JOIN categories c ON p.category_id = c.id 
              WHERE p.status = 'active' $branch_filter 
              AND p.id NOT IN (SELECT product_id FROM bill_items bi JOIN bills b ON bi.bill_id = b.id WHERE b.created_at >= DATE_SUB(NOW(), INTERVAL $dead_days DAY))";
    $rows = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    exportCSV('dead_stock_report.csv', ['Product Name', 'Category', 'Quantity', 'Purchase Price', 'Stock Value'], $rows);
}

// Handle Review Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'review') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("UPDATE products SET reviewed_at = NOW() WHERE id = ?" . $branch_filter);
        $stmt->execute([$id]);
        flashMessage('success', 'Product marked as reviewed. It will not appear in dead stock for 30 days.');
    }
    redirect("alerts.php?tab=dead&days=" . ($_POST['days'] ?? 90));
}

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="table-wrapper">
    <div class="table-toolbar">
        <div class="d-flex bg-secondary p-1 rounded">
            <a href="?tab=low" class="btn btn-sm <?php echo $tab === 'low' ? 'btn-primary' : 'btn-ghost'; ?>">Low Stock</a>
            <a href="?tab=dead" class="btn btn-sm <?php echo $tab === 'dead' ? 'btn-primary' : 'btn-ghost'; ?>">Dead Stock</a>
        </div>
        
        <?php if ($tab === 'dead'): ?>
            <div class="ms-auto d-flex align-center gap-3">
                <form action="" method="GET" class="d-flex align-center gap-2">
                    <input type="hidden" name="tab" value="dead">
                    <select name="days" class="form-control form-control-sm" onchange="this.form.submit()">
                        <option value="30" <?php echo ($_GET['days'] ?? '') == '30' ? 'selected' : ''; ?>>Last 30 Days</option>
                        <option value="60" <?php echo ($_GET['days'] ?? '') == '60' ? 'selected' : ''; ?>>Last 60 Days</option>
                        <option value="90" <?php echo ($_GET['days'] ?? '') == '90' || !isset($_GET['days']) ? 'selected' : ''; ?>>Last 90 Days</option>
                    </select>
                </form>
                <a href="?tab=dead&days=<?php echo $_GET['days'] ?? 90; ?>&export=csv" class="btn btn-outline btn-sm">
                    <i class="fas fa-download me-1"></i> Export CSV
                </a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($tab === 'low'): 
        $query = "SELECT p.*, c.name as category_name 
                  FROM products p 
                  LEFT JOIN categories c ON p.category_id = c.id 
                  WHERE p.status = 'active' AND p.quantity <= p.alert_quantity $branch_filter 
                  ORDER BY p.quantity ASC";
        $low_stock = $pdo->query($query)->fetchAll();
    ?>
        <div class="p-3 bg-secondary-subtle border-bottom border-secondary">
            <b class="text-warning"><?php echo count($low_stock); ?> products</b> need restocking.
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Current Qty</th>
                    <th>Alert Qty</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($low_stock as $p): ?>
                    <tr>
                        <td>
                            <div class="fw-600"><?php echo sanitize($p['name']); ?></div>
                            <div class="fs-11 text-muted">SKU: <?php echo $p['sku']; ?></div>
                        </td>
                        <td><span class="fs-13"><?php echo $p['category_name'] ?: 'N/A'; ?></span></td>
                        <td>
                            <span class="badge-pill <?php echo $p['quantity'] <= 0 ? 'badge-danger fw-700' : 'badge-warning'; ?>">
                                <?php echo $p['quantity']; ?> <?php echo $p['unit']; ?>
                            </span>
                        </td>
                        <td><?php echo $p['alert_quantity']; ?></td>
                        <td class="text-end">
                            <button onclick="openStockModal('in', <?php echo $p['id']; ?>, '<?php echo addslashes($p['name']); ?>', <?php echo $p['quantity']; ?>)" 
                                    class="btn btn-primary btn-sm">Quick Stock-In</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: 
        $dead_days = (int)($_GET['days'] ?? 90);
        $query = "SELECT p.*, c.name as category_name, 
                  (SELECT MAX(created_at) FROM bill_items bi JOIN bills b ON bi.bill_id = b.id WHERE bi.product_id = p.id) as last_sale 
                  FROM products p 
                  LEFT JOIN categories c ON p.category_id = c.id 
                  WHERE p.status = 'active' $branch_filter 
                  AND (p.reviewed_at IS NULL OR p.reviewed_at < DATE_SUB(NOW(), INTERVAL 30 DAY))
                  AND p.id NOT IN (SELECT product_id FROM bill_items bi JOIN bills b ON bi.bill_id = b.id WHERE b.created_at >= DATE_SUB(NOW(), INTERVAL $dead_days DAY)) 
                  ORDER BY (p.quantity * p.purchase_price) DESC";
        $dead_stock = $pdo->query($query)->fetchAll();
        $total_dead_value = array_sum(array_map(fn($p) => $p['quantity'] * $p['purchase_price'], $dead_stock));
    ?>
        <div class="p-3 bg-secondary-subtle border-bottom border-secondary d-flex justify-between align-center">
            <span>Products not sold in the last <b><?php echo $dead_days; ?> days</b>.</span>
            <span class="fw-700 text-danger">Total Dead Value: <?php echo formatCurrency($total_dead_value); ?></span>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Qty</th>
                    <th>Stock Value</th>
                    <th>Last Sale</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dead_stock as $p): ?>
                    <tr>
                        <td>
                            <div class="fw-600"><?php echo sanitize($p['name']); ?></div>
                            <div class="fs-11 text-muted"><?php echo $p['category_name']; ?></div>
                        </td>
                        <td><?php echo $p['quantity']; ?> <?php echo $p['unit']; ?></td>
                        <td class="fw-600"><?php echo formatCurrency($p['quantity'] * $p['purchase_price']); ?></td>
                        <td class="fs-12">
                            <?php if ($p['last_sale']): ?>
                                <?php echo formatDate($p['last_sale']); ?>
                                <div class="fs-10 text-muted"><?php 
                                    $diff = time() - strtotime($p['last_sale']);
                                    echo floor($diff / (60 * 60 * 24)) . ' days ago';
                                ?></div>
                            <?php else: ?>
                                <span class="text-danger">Never Sold</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <form action="" method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="review">
                                <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                <input type="hidden" name="days" value="<?php echo $dead_days; ?>">
                                <button type="submit" class="btn btn-ghost btn-sm text-accent" title="Mark as Reviewed">
                                    <i class="fas fa-check-double me-1"></i> Reviewed
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Reusing the Modal -->
<div id="stockModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Stock In</h3>
            <button class="modal-close" onclick="closeModal('stockModal')">&times;</button>
        </div>
        <form action="stock_in.php" id="stockForm" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="product_id" id="modalProdId">
            <input type="hidden" name="redirect_to" value="alerts.php">
            
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
                <button type="submit" class="btn btn-success">Confirm Stock In</button>
            </div>
        </form>
    </div>
</div>

<script>
let currentStock = 0;
function openStockModal(type, id, name, qty) {
    currentStock = qty;
    document.getElementById('modalProdId').value = id;
    document.getElementById('modalProdName').innerText = name;
    document.getElementById('modalQty').value = '';
    document.getElementById('modalStockPreview').innerText = `Current: ${qty} → After: ${qty}`;
    openModal('stockModal');
}
document.getElementById('modalQty').addEventListener('input', function() {
    const val = parseInt(this.value) || 0;
    document.getElementById('modalStockPreview').innerText = `Current: ${currentStock} → After: ${currentStock + val}`;
});
</script>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
