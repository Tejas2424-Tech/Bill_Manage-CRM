<?php
/**
 * Inventory Movement History
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$product_id = (int)($_GET['product_id'] ?? 0);
if (!$product_id) redirect('index.php');

$branch_id = $_SESSION['branch_id'];
$stmt = $pdo->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?" . (!isAdmin() ? " AND p.branch_id = " . (int)$branch_id : ""));
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product) {
    flashMessage('danger', 'Product not found or access denied.');
    redirect('index.php');
}

$pageTitle = 'Stock History';
$breadcrumb = '<a href="' . BASE_URL . '/modules/inventory/index.php">Inventory</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">History</span>';

// Pagination
$limit = 30;
$page = (int)($_GET['page'] ?? 1);
$offset = ($page - 1) * $limit;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_log WHERE product_id = ?");
$stmt->execute([$product_id]);
$total_rows = $stmt->fetchColumn();
$total_pages = ceil($total_rows / $limit);

$stmt = $pdo->prepare("SELECT l.*, u.name as user_name 
                     FROM inventory_log l 
                     LEFT JOIN users u ON l.created_by = u.id 
                     WHERE l.product_id = ? 
                     ORDER BY l.created_at DESC 
                     LIMIT ? OFFSET ?");
$stmt->execute([$product_id, $limit, $offset]);
$logs = $stmt->fetchAll();

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="row">
    <div class="col-md-4">
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">Product Details</h3>
                <a href="index.php" class="btn btn-outline btn-sm">Back</a>
            </div>
            <div class="mt-4 text-center">
                <?php if ($product['image']): ?>
                    <img src="<?php echo BASE_URL . '/assets/images/products/' . $product['image']; ?>" class="rounded shadow-sm mb-3" style="width: 100px; height: 100px; object-fit: cover;">
                <?php else: ?>
                    <div class="bg-variant rounded mx-auto d-flex align-center justify-center text-muted mb-3" style="width: 100px; height: 100px;">
                        <i class="fas fa-image fa-2x"></i>
                    </div>
                <?php endif; ?>
                <h2 class="fs-18 fw-700"><?php echo sanitize($product['name']); ?></h2>
                <div class="text-muted fs-13"><?php echo $product['sku']; ?> | <?php echo $product['category_name']; ?></div>
            </div>
            
            <hr class="border-secondary my-4">
            
            <div class="px-3">
                <div class="d-flex justify-between mb-2">
                    <span class="text-muted">Current Stock</span>
                    <span class="fw-700 fs-20 text-primary"><?php echo $product['quantity']; ?> <?php echo $product['unit']; ?></span>
                </div>
                <div class="d-flex justify-between mb-2">
                    <span class="text-muted">Purchase Price</span>
                    <span class="fw-600"><?php echo formatCurrency($product['purchase_price']); ?></span>
                </div>
                <div class="d-flex justify-between">
                    <span class="text-muted">Alert Level</span>
                    <span class="text-warning fw-600"><?php echo $product['alert_quantity']; ?></span>
                </div>
            </div>

            <div class="grid-2 mt-4 gap-2">
                <button onclick="openStockModal('in', <?php echo $product['id']; ?>, '<?php echo addslashes($product['name']); ?>', <?php echo $product['quantity']; ?>)" class="btn btn-success btn-sm w-100"><i class="fas fa-plus"></i> In</button>
                <button onclick="openStockModal('out', <?php echo $product['id']; ?>, '<?php echo addslashes($product['name']); ?>', <?php echo $product['quantity']; ?>)" class="btn btn-danger btn-sm w-100"><i class="fas fa-minus"></i> Out</button>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="table-wrapper">
            <div class="table-toolbar">
                <h3 class="card-title m-0">Movement Timeline</h3>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Type</th>
                        <th>Qty</th>
                        <th>User</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">No history found for this product.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td class="fs-12"><?php echo formatDateTime($l['created_at']); ?></td>
                                <td>
                                    <?php 
                                    $type_class = [
                                        'in' => 'badge-success',
                                        'out' => 'badge-danger',
                                        'adjustment' => 'badge-warning'
                                    ][$l['type']];
                                    ?>
                                    <span class="badge-pill <?php echo $type_class; ?>">
                                        <?php echo strtoupper($l['type']); ?>
                                    </span>
                                </td>
                                <td class="fw-600"><?php echo $l['quantity']; ?></td>
                                <td class="fs-12 text-secondary"><?php echo sanitize($l['user_name']); ?></td>
                                <td class="fs-12 text-muted truncate" style="max-width: 200px;" title="<?php echo sanitize($l['note']); ?>">
                                    <?php echo sanitize($l['note']) ?: '-'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1): ?>
                <div class="p-3 border-top d-flex justify-end gap-1">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?product_id=<?php echo $product_id; ?>&page=<?php echo $i; ?>" 
                           class="btn <?php echo $i == $page ? 'btn-primary' : 'btn-outline'; ?> btn-sm"><?php echo $i; ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal from index.php reused here via include logic if needed, but for now we'll just handle it in app.js or similar -->
<!-- For simplicity, I'll copy the modal here too -->
<div id="stockModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Stock In</h3>
            <button class="modal-close" onclick="closeModal('stockModal')">&times;</button>
        </div>
        <form action="" id="stockForm" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
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
