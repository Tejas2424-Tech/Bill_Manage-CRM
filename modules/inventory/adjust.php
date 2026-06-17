<?php
/**
 * Stock Adjustment Module
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$product_id = (int)($_GET['product_id'] ?? 0);
if (!$product_id) redirect('index.php');

$branch_id = $_SESSION['branch_id'];
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?" . (!isAdmin() ? " AND branch_id = " . (int)$branch_id : ""));
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product) {
    flashMessage('danger', 'Product not found or access denied.');
    redirect('index.php');
}

$pageTitle = 'Stock Adjustment';
$breadcrumb = '<a href="' . BASE_URL . '/modules/inventory/index.php">Inventory</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Adjust Stock</span>';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $physical_count = (int)$_POST['physical_count'];
    $note = sanitize($_POST['note'] ?? '');
    
    if (empty($note)) {
        $error = 'A note is required for stock adjustment audit.';
    } else {
        $current_qty = (int)$product['quantity'];
        $difference = $physical_count - $current_qty;
        
        if ($difference === 0) {
            flashMessage('info', 'No changes detected.');
            redirect('index.php');
        }

        // Log against the product's branch (superadmin has a NULL session branch).
        $branch_id = (int)$product['branch_id'];
        if (!$branch_id) {
            flashMessage('danger', 'Unable to determine branch for this product.');
            redirect('index.php');
        }

        try {
            $pdo->beginTransaction();

            // 1. Update Product Quantity
            $stmt = $pdo->prepare("UPDATE products SET quantity = ? WHERE id = ?");
            $stmt->execute([$physical_count, $product_id]);

            // 2. Log Inventory Movement
            $type = $difference > 0 ? 'adjustment' : 'adjustment'; // We use 'adjustment' type for both
            $stmt = $pdo->prepare("INSERT INTO inventory_log (branch_id, product_id, type, quantity, note, created_by) VALUES (?, ?, 'adjustment', ?, ?, ?)");
            $stmt->execute([$branch_id, $product_id, abs($difference), $note, $_SESSION['user_id']]);

            $pdo->commit();
            
            logAudit('stock_adjustment', 'inventory', "Adjusted stock for product ID: $product_id from $current_qty to $physical_count (Diff: $difference)");
            flashMessage('success', "Stock adjusted successfully.");
            redirect('index.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="form-card">
            <div class="card-header">
                <h3 class="card-title">Adjust Stock: <?php echo sanitize($product['name']); ?></h3>
                <a href="index.php" class="btn btn-outline btn-sm">Back</a>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger mt-3">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo $error; ?></div>
                </div>
            <?php endif; ?>

            <form action="" method="POST" class="mt-4">
                <div class="form-group mb-4">
                    <label class="form-label">Product Details</label>
                    <div class="p-3 bg-variant rounded border border-secondary">
                        <div class="d-flex justify-between mb-1">
                            <span class="text-muted fs-12">SKU:</span>
                            <span class="fw-500 fs-12"><?php echo $product['sku']; ?></span>
                        </div>
                        <div class="d-flex justify-between">
                            <span class="text-muted fs-12">System Quantity:</span>
                            <span class="fw-700 fs-14 text-primary"><?php echo $product['quantity']; ?> <?php echo $product['unit']; ?></span>
                        </div>
                    </div>
                </div>

                <div class="form-group mb-4">
                    <label class="form-label">Physical Count (New Quantity) <span class="req">*</span></label>
                    <input type="number" name="physical_count" id="physCount" class="form-control" value="<?php echo $product['quantity']; ?>" required>
                    <div class="form-hint mt-2" id="adjPreview">Adjustment: 0 (No change)</div>
                </div>

                <div class="form-group mb-4">
                    <label class="form-label">Reason for Adjustment <span class="req">*</span></label>
                    <textarea name="note" class="form-control" rows="3" placeholder="Explain why the physical count differs from system count..." required></textarea>
                </div>

                <div class="d-flex justify-end gap-2 mt-5">
                    <button type="reset" class="btn btn-outline">Reset</button>
                    <button type="submit" class="btn btn-primary">Update Physical Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const currentQty = <?php echo $product['quantity']; ?>;
    const physInput = document.getElementById('physCount');
    const preview = document.getElementById('adjPreview');

    physInput.addEventListener('input', function() {
        const val = parseInt(this.value) || 0;
        const diff = val - currentQty;
        if (diff === 0) {
            preview.innerText = "Adjustment: 0 (No change)";
            preview.className = "form-hint mt-2";
        } else if (diff > 0) {
            preview.innerText = `Adjustment: +${diff} (Add to stock)`;
            preview.className = "form-hint mt-2 text-success fw-600";
        } else {
            preview.innerText = `Adjustment: ${diff} (Remove from stock)`;
            preview.className = "form-hint mt-2 text-danger fw-600";
        }
    });
</script>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
