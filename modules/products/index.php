<?php
/**
 * Product Management Index
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = 'Products';
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Products</span>';

// Pagination setup
$limit = 25;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Filters
$search = sanitize($_GET['search'] ?? '');
$category_id = sanitize($_GET['category_id'] ?? '');
$brand_id = sanitize($_GET['brand_id'] ?? '');
$status_filter = sanitize($_GET['status'] ?? '');

$params = [];
$where_clauses = ["p.status != 'deleted'"];

if (!isAdmin()) {
    $where_clauses[] = "p.branch_id = ?";
    $params[] = $_SESSION['branch_id'];
}

if ($search) {
    $where_clauses[] = "(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if ($category_id) {
    $where_clauses[] = "p.category_id = ?";
    $params[] = $category_id;
}

if ($brand_id) {
    $where_clauses[] = "p.brand_id = ?";
    $params[] = $brand_id;
}

if ($status_filter) {
    $where_clauses[] = "p.status = ?";
    $params[] = $status_filter;
}

$where_sql = implode(" AND ", $where_clauses);

// Count total for pagination
$count_query = "SELECT COUNT(*) FROM products p WHERE $where_sql";
$stmt = $pdo->prepare($count_query);
$stmt->execute($params);
$total_rows = $stmt->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// Main Query
$query = "SELECT p.*, c.name as category_name, b.name as brand_name 
          FROM products p 
          LEFT JOIN categories c ON p.category_id = c.id 
          LEFT JOIN brands b ON p.brand_id = b.id 
          WHERE $where_sql 
          ORDER BY p.created_at DESC 
          LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Handle Delete (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    requireNotCashier();
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
    } else {
        $product_id = (int)$_POST['product_id'];
        // Soft delete
        $stmt = $pdo->prepare("UPDATE products SET status = 'deleted' WHERE id = ?" . (!isAdmin() ? " AND branch_id = " . (int)$_SESSION['branch_id'] : ""));
        $stmt->execute([$product_id]);
        logAudit('delete_product', 'products', "Deleted product ID: $product_id");
        flashMessage('success', 'Product deleted successfully.');
    }
    redirect(BASE_URL . '/modules/products/index.php');
}

// Fetch categories and brands for filters
$categories = $pdo->query("SELECT id, name FROM categories WHERE status=1 ORDER BY name ASC")->fetchAll();
$brands = $pdo->query("SELECT id, name FROM brands WHERE status=1 ORDER BY name ASC")->fetchAll();

$csrf_token = generateCSRFToken();

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Product Catalog</h1>
        <div class="sub"><?php echo $total_rows; ?> products total</div>
    </div>
    <div class="page-header-actions">
        <a href="categories.php"   class="btn btn-outline btn-sm"><i class="fas fa-tags"></i> Categories</a>
        <a href="brands.php"       class="btn btn-outline btn-sm"><i class="fas fa-copyright"></i> Brands</a>
        <a href="bulk_barcode.php" class="btn btn-outline btn-sm"><i class="fas fa-barcode"></i> Bulk Barcodes</a>
        <a href="add.php"          class="btn btn-primary"><i class="fas fa-plus"></i> Add Product</a>
    </div>
</div>

<div class="table-wrapper">
    <div class="table-toolbar" style="flex-wrap:wrap;gap:10px;">
        <form action="" method="GET" style="display:flex;gap:8px;flex-wrap:wrap;flex:1;">
            <div style="position:relative;flex:1;min-width:180px;max-width:260px;">
                <i class="fas fa-magnifying-glass" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--on-surface-subtle);font-size:12px;pointer-events:none;"></i>
                <input type="text" name="search" class="form-control" placeholder="Search name, SKU, barcode…"
                       value="<?php echo $search; ?>" style="padding-left:32px;">
            </div>
            <select name="category_id" class="form-control" style="width:150px;">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $category_id == $cat['id'] ? 'selected' : ''; ?>><?php echo sanitize($cat['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="brand_id" class="form-control" style="width:140px;">
                <option value="">All Brands</option>
                <?php foreach ($brands as $br): ?>
                    <option value="<?php echo $br['id']; ?>" <?php echo $brand_id == $br['id'] ? 'selected' : ''; ?>><?php echo sanitize($br['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($search || $category_id || $brand_id): ?>
                <a href="index.php" class="btn btn-ghost">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <table class="data-table" id="productTable">
        <thead>
            <tr>
                <th>#</th>
                <th>Product</th>
                <th>Category</th>
                <th>Purchase</th>
                <th>Selling</th>
                <th>Stock</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($products)): ?>
                <tr><td colspan="8">
                    <div class="empty-state">
                        <i class="fas fa-box empty-icon"></i>
                        <h3>No products found</h3>
                        <p>Try adjusting your search or filters, or add your first product.</p>
                    </div>
                </td></tr>
            <?php else: ?>
                <?php foreach ($products as $i => $p): ?>
                    <tr>
                        <td><?php echo $offset + $i + 1; ?></td>
                        <td>
                            <div class="d-flex align-center gap-3">
                                <?php if ($p['image']): ?>
                                    <img src="<?php echo BASE_URL . '/assets/images/products/' . $p['image']; ?>" class="rounded" style="width: 40px; height: 40px; object-fit: cover;">
                                <?php else: ?>
                                    <div style="width:40px;height:40px;background:var(--surface-variant);border:1.5px solid var(--border);border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--on-surface-subtle);font-size:14px;">
                                        <i class="fas fa-box"></i>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight:600;color:var(--on-surface);"><?php echo sanitize($p['name']); ?></div>
                                    <div style="font-size:11px;color:var(--on-surface-subtle);margin-top:2px;">SKU: <?php echo sanitize($p['sku']); ?> &nbsp;|&nbsp; <?php echo sanitize($p['barcode']); ?></div>
                                    <?php if ($p['brand_name']): ?><div style="font-size:10px;color:var(--on-surface-subtle);"><?php echo sanitize($p['brand_name']); ?></div><?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><span class="fs-13"><?php echo $p['category_name'] ?: 'Uncategorized'; ?></span></td>
                        <td><?php echo formatCurrency($p['purchase_price']); ?></td>
                        <td class="fw-600"><?php echo formatCurrency($p['selling_price']); ?></td>
                        <td>
                            <?php 
                            $qty = $p['quantity'];
                            $badge = 'badge-success';
                            if ($qty <= 0) $badge = 'badge-danger fw-700';
                            elseif ($qty < $p['alert_quantity']) $badge = 'badge-danger';
                            elseif ($qty <= 10) $badge = 'badge-warning';
                            ?>
                            <span class="badge-pill <?php echo $badge; ?>">
                                <?php echo $qty; ?> <?php echo $p['unit']; ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge-pill <?php echo $p['status'] === 'active' ? 'badge-success' : 'badge-danger'; ?>">
                                <?php echo ucfirst($p['status']); ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <div class="d-flex justify-end gap-1">
                                <a href="barcode.php?id=<?php echo $p['id']; ?>" class="btn btn-ghost btn-icon btn-sm" title="Print Barcode">
                                    <i class="fas fa-barcode"></i>
                                </a>
                                <a href="edit.php?id=<?php echo $p['id']; ?>" class="btn btn-ghost btn-icon btn-sm" title="Edit Product">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form action="" method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button type="submit" class="btn btn-ghost btn-icon btn-sm text-danger" data-confirm="Delete this product?" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <div class="table-footer">
        <span>Showing <?php echo $total_rows > 0 ? $offset + 1 : 0; ?>–<?php echo min($offset + $limit, $total_rows); ?> of <?php echo $total_rows; ?> products</span>
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php
                $q_str = http_build_query(['search' => $search, 'category_id' => $category_id, 'brand_id' => $brand_id, 'status' => $status_filter]);
                ?>
                <a href="?page=<?php echo max(1,$page-1); ?>&<?php echo $q_str; ?>" class="page-btn <?php echo $page==1?'disabled':''; ?>"><i class="fas fa-chevron-left"></i></a>
                <?php for ($p_idx = max(1,$page-2); $p_idx <= min($total_pages,$page+2); $p_idx++): ?>
                    <a href="?page=<?php echo $p_idx; ?>&<?php echo $q_str; ?>" class="page-btn <?php echo $p_idx==$page?'active':''; ?>"><?php echo $p_idx; ?></a>
                <?php endfor; ?>
                <a href="?page=<?php echo min($total_pages,$page+1); ?>&<?php echo $q_str; ?>" class="page-btn <?php echo $page==$total_pages?'disabled':''; ?>"><i class="fas fa-chevron-right"></i></a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
