<?php
/**
 * Add/Edit Product Logic
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$product_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$is_edit = $product_id !== null;

$pageTitle = $is_edit ? 'Edit Product' : 'Add Product';
$breadcrumb = "Inventory / Products / " . ($is_edit ? 'Edit' : 'Add');

$product = null;
if ($is_edit) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?" . (!isAdmin() ? " AND branch_id = " . (int)$_SESSION['branch_id'] : ""));
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();
    if (!$product) {
        flashMessage('danger', 'Product not found.');
        redirect('index.php');
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
        redirect('index.php');
    }
    $name = sanitize($_POST['name'] ?? '');
    $size = sanitize($_POST['size'] ?? '');
    $sku = sanitize($_POST['sku'] ?? '');
    $barcode = sanitize($_POST['barcode'] ?? '');
    $category_id = $_POST['category_id'] ?: null;
    $brand_id = $_POST['brand_id'] ?: null;
    $unit = sanitize($_POST['unit'] ?? 'pcs');
    $purchase_price = (float)($_POST['purchase_price'] ?? 0);
    $selling_price = (float)($_POST['selling_price'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);
    $alert_quantity = (int)($_POST['alert_quantity'] ?? 5);
    $dead_stock_days = (int)($_POST['dead_stock_days'] ?? 90);
    $status = $_POST['status'] ?? 'active';
    
    // Single-branch app: products always belong to the one shop
    $branch_id = (int)($_SESSION['branch_id'] ?? 1);

    if (empty($name) || $purchase_price <= 0 || $selling_price <= 0) {
        $error = 'Product Name, Purchase Price, and Selling Price are required.';
    } else {
        // Handle Image Upload
        $image_name = $product['image'] ?? null;
        if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../../assets/images/products/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            
            $file_info = new finfo(FILEINFO_MIME_TYPE);
            $mime_type = $file_info->file($_FILES['product_image']['tmp_name']);
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            
            if (!in_array($mime_type, $allowed_types)) {
                $error = 'Invalid image type. Only JPG, PNG, GIF, and WEBP are allowed.';
            } elseif ($_FILES['product_image']['size'] > 2 * 1024 * 1024) {
                $error = 'Image size exceeds 2MB limit.';
            } else {
                $ext = pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION);
                $new_name = uniqid() . '_' . time() . '.' . strtolower($ext);
                if (move_uploaded_file($_FILES['product_image']['tmp_name'], $upload_dir . $new_name)) {
                    $image_name = $new_name;
                }
            }
        }

        if (!$error) {
            if ($is_edit) {
            $stmt = $pdo->prepare("UPDATE products SET name=?, size=?, sku=?, barcode=?, category_id=?, brand_id=?, unit=?, purchase_price=?, selling_price=?, quantity=?, alert_quantity=?, dead_stock_days=?, status=?, image=? WHERE id=?");
            $stmt->execute([$name, $size, $sku, $barcode, $category_id, $brand_id, $unit, $purchase_price, $selling_price, $quantity, $alert_quantity, $dead_stock_days, $status, $image_name, $product_id]);
            logAudit('edit_product', 'products', "Updated product: $name (ID: $product_id)");
            flashMessage('success', 'Product updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO products (branch_id, name, size, sku, barcode, category_id, brand_id, unit, purchase_price, selling_price, quantity, alert_quantity, dead_stock_days, status, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$branch_id, $name, $size, $sku, $barcode, $category_id, $brand_id, $unit, $purchase_price, $selling_price, $quantity, $alert_quantity, $dead_stock_days, $status, $image_name]);
            $new_id = $pdo->lastInsertId();
            logAudit('add_product', 'products', "Added new product: $name (ID: $new_id)");
            flashMessage('success', 'Product added successfully.');
        }
        redirect('index.php');
    }
}
}

$categories = $pdo->query("SELECT id, name FROM categories WHERE status=1 ORDER BY name ASC")->fetchAll();
$brands = $pdo->query("SELECT id, name FROM brands WHERE status=1 ORDER BY name ASC")->fetchAll();

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-10">
        <div class="form-card">
            <div class="card-header">
                <h3 class="card-title"><?php echo $is_edit ? 'Update Product Details' : 'Register New Product'; ?></h3>
                <a href="index.php" class="btn btn-outline btn-sm">Back to List</a>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger mt-3">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo $error; ?></div>
                </div>
            <?php endif; ?>

            <form action="" method="POST" enctype="multipart/form-data" class="mt-4">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div class="row">
                    <!-- LEFT COLUMN -->
                    <div class="col-md-6 border-end border-secondary pe-md-4">
                        <div class="form-group">
                            <label class="form-label">Product Name <span class="req">*</span></label>
                            <input type="text" name="name" id="prodName" class="form-control" value="<?php echo sanitize($product['name'] ?? ''); ?>" required>
                        </div>

                        <div class="form-row mt-3">
                            <div class="form-group">
                                <label class="form-label">SKU</label>
                                <div class="input-group">
                                    <input type="text" name="sku" id="skuInput" class="form-control" value="<?php echo sanitize($product['sku'] ?? ''); ?>">
                                    <button type="button" class="btn btn-outline btn-sm" onclick="autoGenSKU()">Auto</button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Barcode</label>
                                <div class="input-group">
                                    <input type="text" name="barcode" id="barcodeInput" class="form-control" value="<?php echo sanitize($product['barcode'] ?? ''); ?>">
                                    <button type="button" class="btn btn-outline btn-sm" onclick="autoGenBarcode()">Gen</button>
                                </div>
                                <div id="barcodePreview" class="mt-2 text-center bg-white p-2 rounded d-none">
                                    <svg id="barcodeCanvas"></svg>
                                </div>
                            </div>
                        </div>

                        <div class="form-row mt-3">
                            <div class="form-group">
                                <label class="form-label">Category</label>
                                <select name="category_id" class="form-control">
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>" <?php echo ($product['category_id'] ?? '') == $cat['id'] ? 'selected' : ''; ?>><?php echo $cat['name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <a href="categories.php" class="fs-11 text-accent mt-1 d-block">+ Add new category</a>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Brand</label>
                                <select name="brand_id" class="form-control">
                                    <option value="">Select Brand</option>
                                    <?php foreach ($brands as $br): ?>
                                        <option value="<?php echo $br['id']; ?>" <?php echo ($product['brand_id'] ?? '') == $br['id'] ? 'selected' : ''; ?>><?php echo $br['name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <a href="brands.php" class="fs-11 text-accent mt-1 d-block">+ Add new brand</a>
                            </div>
                        </div>

                        <div class="form-row mt-3">
                            <div class="form-group">
                                <label class="form-label">Size</label>
                                <input type="text" name="size" class="form-control" list="sizeOptions" placeholder="e.g. XL, 32, Free Size" value="<?php echo sanitize($product['size'] ?? ''); ?>">
                                <datalist id="sizeOptions">
                                    <?php foreach (['S','M','L','XL','XXL','XXXL','Free Size','28','30','32','34','36','38','40','42','44','46'] as $sz): ?>
                                        <option value="<?php echo $sz; ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Unit</label>
                                <select name="unit" class="form-control">
                                    <?php $units = ['pcs', 'kg', 'L', 'dozen', 'box', 'strip', 'other'];
                                    foreach ($units as $u): ?>
                                        <option value="<?php echo $u; ?>" <?php echo ($product['unit'] ?? 'pcs') == $u ? 'selected' : ''; ?>><?php echo $u; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT COLUMN -->
                    <div class="col-md-6 ps-md-4">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Purchase Price (₹) <span class="req">*</span></label>
                                <input type="number" step="0.01" name="purchase_price" id="pPrice" class="form-control currency-input" value="<?php echo $product['purchase_price'] ?? ''; ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Selling Price (₹) <span class="req">*</span></label>
                                <input type="number" step="0.01" name="selling_price" id="sPrice" class="form-control currency-input" value="<?php echo $product['selling_price'] ?? ''; ?>" required>
                            </div>
                        </div>
                        <div class="fs-12 text-success mb-3 fw-600">
                            Profit Margin: <span id="profitMargin">0.0%</span>
                        </div>

                        <div class="form-row mt-3">
                            <div class="form-group">
                                <label class="form-label">Current Stock</label>
                                <input type="number" name="quantity" class="form-control" value="<?php echo $product['quantity'] ?? '0'; ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Alert Quantity</label>
                                <input type="number" name="alert_quantity" class="form-control" value="<?php echo $product['alert_quantity'] ?? '5'; ?>">
                            </div>
                        </div>

                        <div class="form-row mt-3">
                            <div class="form-group">
                                <label class="form-label">Dead Stock Days</label>
                                <input type="number" name="dead_stock_days" class="form-control" value="<?php echo $product['dead_stock_days'] ?? '90'; ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-control">
                                    <option value="active" <?php echo ($product['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($product['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group mt-3">
                            <label class="form-label">Product Image</label>
                            <input type="file" name="product_image" id="imgInput" class="form-control" accept="image/*">
                            <div class="mt-2">
                                <img id="imgPreview" src="<?php echo ($product['image'] ?? false) ? BASE_URL . '/assets/images/products/' . $product['image'] : '#'; ?>" 
                                     class="rounded <?php echo ($product['image'] ?? false) ? '' : 'd-none'; ?>" style="max-width: 120px; max-height: 120px; object-fit: cover;">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-end gap-3 mt-5 border-top pt-4">
                    <button type="reset" class="btn btn-outline">Reset</button>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-save"></i> <?php echo $is_edit ? 'Update Product' : 'Save Product'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3/dist/JsBarcode.all.min.js"></script>
<script>
    // 1. Live Profit Margin
    const pPrice = document.getElementById('pPrice');
    const sPrice = document.getElementById('sPrice');
    const marginSpan = document.getElementById('profitMargin');

    function updateMargin() {
        const p = parseFloat(pPrice.value) || 0;
        const s = parseFloat(sPrice.value) || 0;
        if (s > 0) {
            const margin = ((s - p) / s * 100).toFixed(1);
            marginSpan.innerText = margin + '%';
            marginSpan.className = margin >= 0 ? 'text-success' : 'text-danger';
        }
    }
    pPrice.addEventListener('input', updateMargin);
    sPrice.addEventListener('input', updateMargin);
    updateMargin();

    // 2. Auto SKU
    function autoGenSKU() {
        const name = document.getElementById('prodName').value;
        if (!name) return alert('Enter product name first');
        let sku = name.replace(/[^A-Za-z]/g, '').substring(0, 3).toUpperCase();
        if (sku.length < 3) sku = sku.padEnd(3, 'X');
        sku += Math.floor(1000 + Math.random() * 9000);
        document.getElementById('skuInput').value = sku;
    }

    // 3. Auto Barcode
    function autoGenBarcode() {
        const val = '2' + Math.floor(Math.random() * 1000000000000).toString().padStart(12, '0');
        document.getElementById('barcodeInput').value = val;
        renderBarcode(val);
    }

    function renderBarcode(val) {
        if (val.length >= 8) {
            document.getElementById('barcodePreview').classList.remove('d-none');
            JsBarcode("#barcodeCanvas", val, {
                format: "CODE128",
                width: 1.5,
                height: 50,
                displayValue: true,
                lineColor: "#000"
            });
        }
    }

    document.getElementById('barcodeInput').addEventListener('input', (e) => renderBarcode(e.target.value));
    <?php if ($is_edit && !empty($product['barcode'])): ?>
        renderBarcode("<?php echo $product['barcode']; ?>");
    <?php endif; ?>

    // 4. Image Preview
    document.getElementById('imgInput').addEventListener('change', function() {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('imgPreview');
                preview.src = e.target.result;
                preview.classList.remove('d-none');
            }
            reader.readAsDataURL(file);
        }
    });
</script>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
