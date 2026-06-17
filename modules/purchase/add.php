<?php
/**
 * Add New Purchase Entry
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

if (isAdmin()) {
    // Superadmin picks the target branch (via the selector / ?branch_id on reload / POST).
    $branch_id = (int)($_POST['branch_id'] ?? $_GET['branch_id'] ?? 0);
} else {
    $branch_id = (int)$_SESSION['branch_id'];
}
$branches = isAdmin() ? $pdo->query("SELECT id, name FROM branches WHERE status='active' ORDER BY name ASC")->fetchAll() : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
        redirect('add.php');
    }

    // A purchase belongs to exactly one branch; ensure it is valid (superadmin must pick one).
    $bok = $pdo->prepare("SELECT 1 FROM branches WHERE id = ? AND status = 'active'");
    $bok->execute([$branch_id]);
    if (!$bok->fetchColumn()) {
        flashMessage('danger', 'Please select a valid branch for this purchase.');
        redirect('add.php');
    }

    $vendor_id = $_POST['vendor_id'] ?: null;
    $invoice_number = sanitize($_POST['invoice_number'] ?? '');
    $purchase_date = $_POST['purchase_date'] ?: date('Y-m-d');
    $note = sanitize($_POST['note'] ?? '');
    
    $item_product_ids = $_POST['product_id'] ?? [];
    $item_quantities = $_POST['quantity'] ?? [];
    $item_prices = $_POST['purchase_price'] ?? [];

    if (empty($item_product_ids)) {
        flashMessage('danger', 'Please add at least one item.');
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Calculate Grand Total
            $grand_total = 0;
            foreach ($item_prices as $idx => $price) {
                $grand_total += (float)$price * (int)$item_quantities[$idx];
            }

            // 2. Insert Purchase
            $stmt = $pdo->prepare("INSERT INTO purchases (branch_id, vendor_id, invoice_number, total_amount, purchase_date, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$branch_id, $vendor_id, $invoice_number, $grand_total, $purchase_date, $note, $_SESSION['user_id']]);
            $purchase_id = $pdo->lastInsertId();

            // 3. Insert Items and Update Stock
            foreach ($item_product_ids as $idx => $prod_id) {
                $qty = (int)$item_quantities[$idx];
                $price = (float)$item_prices[$idx];
                $total = $qty * $price;

                // Item must belong to the purchase's branch (guards tampered/cross-branch ids).
                $chk = $pdo->prepare("SELECT 1 FROM products WHERE id = ? AND branch_id = ?");
                $chk->execute([$prod_id, $branch_id]);
                if (!$chk->fetchColumn()) {
                    throw new Exception('Product does not belong to the selected branch.');
                }

                $stmt = $pdo->prepare("INSERT INTO purchase_items (purchase_id, product_id, quantity, purchase_price, total) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$purchase_id, $prod_id, $qty, $price, $total]);

                // Update Product
                $stmt = $pdo->prepare("UPDATE products SET quantity = quantity + ?, purchase_price = ? WHERE id = ?");
                $stmt->execute([$qty, $price, $prod_id]);

                // Log Inventory
                $stmt = $pdo->prepare("INSERT INTO inventory_log (branch_id, product_id, type, quantity, reference_type, reference_id, created_by) VALUES (?, ?, 'in', ?, 'purchase', ?, ?)");
                $stmt->execute([$branch_id, $prod_id, $qty, $purchase_id, $_SESSION['user_id']]);
            }

            $pdo->commit();
            logAudit('add_purchase', 'purchase', "New purchase entry created: $invoice_number (Total: ₹$grand_total)");
            flashMessage('success', 'Purchase record added and stock updated.');
            redirect('index.php');

        } catch (Exception $e) {
            $pdo->rollBack();
            flashMessage('danger', 'Error: ' . $e->getMessage());
        }
    }
}

$vendors = $pdo->query("SELECT id, name FROM vendors WHERE status=1 ORDER BY name ASC")->fetchAll();
if ($branch_id) {
    $pstmt = $pdo->prepare("SELECT id, name, sku, purchase_price FROM products WHERE branch_id = ? AND status='active' ORDER BY name ASC");
    $pstmt->execute([$branch_id]);
    $products = $pstmt->fetchAll();
} else {
    $products = []; // superadmin hasn't chosen a branch yet — render empty, no crash
}

$pageTitle = 'New Purchase';
include_once __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-11">
        <form action="" method="POST" id="purchaseForm">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            
            <div class="form-card">
                <div class="card-header">
                    <h3 class="card-title">Purchase Information</h3>
                    <a href="index.php" class="btn btn-outline btn-sm">Back</a>
                </div>

<?php if (isAdmin()): ?>
                <div class="form-group mt-4">
                    <label class="form-label">Branch <span class="req">*</span></label>
                    <select name="branch_id" class="form-control" required onchange="location.href='add.php?branch_id=' + this.value">
                        <option value="">Select Branch</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo (int)$b['id']; ?>" <?php echo $branch_id == $b['id'] ? 'selected' : ''; ?>><?php echo sanitize($b['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint">Select a branch to load its products.</p>
                </div>
<?php endif; ?>

                <div class="form-row-3 mt-4">
                    <div class="form-group">
                        <label class="form-label">Vendor*</label>
                        <select name="vendor_id" class="form-control" required>
                            <option value="">Select Vendor</option>
                            <?php foreach ($vendors as $v): ?>
                                <option value="<?php echo $v['id']; ?>"><?php echo $v['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Invoice Number</label>
                        <input type="text" name="invoice_number" class="form-control" placeholder="Optional">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Purchase Date*</label>
                        <input type="date" name="purchase_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <hr class="border-secondary my-5">

                <div class="d-flex justify-between align-center mb-3">
                    <h3 class="card-title">Purchase Items</h3>
                    <button type="button" class="btn btn-outline btn-sm text-accent" onclick="addRow()">
                        <i class="fas fa-plus me-1"></i> Add Item
                    </button>
                </div>

                <table class="data-table" id="itemsTable">
                    <thead>
                        <tr>
                            <th style="width: 45%;">Product</th>
                            <th style="width: 15%;">Quantity</th>
                            <th style="width: 15%;">Unit Price (₹)</th>
                            <th style="width: 15%;">Total</th>
                            <th style="width: 10%;" class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                        <!-- JS Rows -->
                    </tbody>
                    <tfoot>
                        <tr class="bg-secondary">
                            <td colspan="3" class="text-end fw-600 py-3">GRAND TOTAL:</td>
                            <td class="fw-700 text-success py-3 fs-16" id="grandTotal">₹0.00</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>

                <div class="form-group mt-5">
                    <label class="form-label">Internal Note</label>
                    <textarea name="note" class="form-control" rows="2" placeholder="Optional notes for this purchase..."></textarea>
                </div>

                <div class="d-flex justify-end gap-3 mt-5 pt-4 border-top">
                    <button type="reset" class="btn btn-outline">Reset Form</button>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-check-circle me-1"></i> Complete Purchase
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
const products = <?php echo json_encode($products); ?>;

function addRow() {
    const tbody = document.getElementById('itemsBody');
    const row = document.createElement('tr');
    
    row.innerHTML = `
        <td>
            <select name="product_id[]" class="form-control prod-select" required onchange="onProdChange(this)">
                <option value="">Select Product</option>
                ${products.map(p => `<option value="${p.id}" data-price="${p.purchase_price}">${p.name} (${p.sku})</option>`).join('')}
            </select>
        </td>
        <td>
            <input type="number" name="quantity[]" class="form-control qty-input" value="50" min="1" required oninput="recalcRow(this)" style="background:white;">
        </td>
        <td>
            <input type="number" step="0.01" name="purchase_price[]" class="form-control price-input" value="0" required oninput="recalcRow(this)">
        </td>
        <td class="row-total fw-600">₹0.00</td>
        <td class="text-end">
            <button type="button" class="btn btn-ghost btn-sm text-danger" onclick="removeRow(this)"><i class="fas fa-times"></i></button>
        </td>
    `;
    
    tbody.appendChild(row);
}

function removeRow(btn) {
    if (document.querySelectorAll('#itemsBody tr').length > 1) {
        btn.closest('tr').remove();
        recalcGrand();
    }
}

function onProdChange(select) {
    const option = select.options[select.selectedIndex];
    const price = option.getAttribute('data-price') || 0;
    const row = select.closest('tr');
    row.querySelector('.price-input').value = price;
    recalcRow(select);
}

function recalcRow(el) {
    const row = el.closest('tr');
    const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
    const price = parseFloat(row.querySelector('.price-input').value) || 0;
    const total = qty * price;
    row.querySelector('.row-total').textContent = '₹' + total.toFixed(2);
    recalcGrand();
}

function recalcGrand() {
    let grand = 0;
    document.querySelectorAll('.row-total').forEach(td => {
        grand += parseFloat(td.textContent.replace('₹', '')) || 0;
    });
    document.getElementById('grandTotal').textContent = '₹' + grand.toFixed(2);
}

// Add initial row
document.addEventListener('DOMContentLoaded', addRow);
</script>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
