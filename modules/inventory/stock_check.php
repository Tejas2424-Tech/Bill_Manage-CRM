<?php
/**
 * Stock Check — quick read-only lookup of available vs defective stock and
 * selling price, searchable by product name / category / size. Cashier-accessible.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle  = 'Stock Check';
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Stock Check</span>';

$isAdmin   = isAdmin();
$branch_id = (int)($_SESSION['branch_id'] ?? 0);

$search = sanitize($_GET['search'] ?? '');
$cat_id = (int)($_GET['category_id'] ?? 0);
$size   = sanitize($_GET['size'] ?? '');

$params = [];
$where  = ["p.status = 'active'"];
if (!$isAdmin) { $where[] = "p.branch_id = ?"; $params[] = $branch_id; }
if ($search)   { $where[] = "(p.name LIKE ? OR p.sku LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($cat_id)   { $where[] = "p.category_id = ?"; $params[] = $cat_id; }
if ($size)     { $where[] = "p.size = ?"; $params[] = $size; }

$where_sql = implode(' AND ', $where);
$query = "SELECT p.id, p.name, p.size, p.sku, p.quantity, p.defective_quantity, p.selling_price, p.unit,
                 c.name AS category_name
          FROM products p
          LEFT JOIN categories c ON p.category_id = c.id
          WHERE $where_sql
          ORDER BY p.name ASC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $pdo->query("SELECT id, name FROM categories WHERE status=1 ORDER BY name ASC")->fetchAll();

// Distinct sizes for the filter (branch-scoped for non-admins).
$sizeSql = "SELECT DISTINCT size FROM products WHERE status='active' AND size IS NOT NULL AND size <> ''" . ($isAdmin ? '' : " AND branch_id = " . $branch_id) . " ORDER BY size ASC";
$sizes = $pdo->query($sizeSql)->fetchAll(PDO::FETCH_COLUMN);

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Stock Check</h1>
        <div class="sub">Search availability, defective stock and price</div>
    </div>
</div>

<div class="table-wrapper">
    <div class="table-toolbar" style="flex-wrap:wrap;gap:10px;">
        <form action="" method="GET" style="display:flex;gap:8px;flex-wrap:wrap;flex:1;">
            <input type="text" name="search" class="form-control" placeholder="Product name or SKU…"
                   value="<?php echo $search; ?>" style="max-width:220px;">
            <select name="category_id" class="form-control" style="max-width:160px;">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $cat_id == $cat['id'] ? 'selected' : ''; ?>><?php echo sanitize($cat['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="size" class="form-control" style="max-width:130px;">
                <option value="">All Sizes</option>
                <?php foreach ($sizes as $sz): ?>
                    <option value="<?php echo sanitize($sz); ?>" <?php echo $size === $sz ? 'selected' : ''; ?>><?php echo sanitize($sz); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline"><i class="fas fa-magnifying-glass"></i> Search</button>
            <?php if ($search || $cat_id || $size): ?>
                <a href="stock_check.php" class="btn btn-ghost">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>Category</th>
                <th>Size</th>
                <th class="text-end">Available</th>
                <th class="text-end">Defective</th>
                <th class="text-end">Selling Price</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($products)): ?>
                <tr><td colspan="6" class="text-center py-5 text-muted">No products match your search.</td></tr>
            <?php else: foreach ($products as $p): ?>
                <?php $avail = (int)$p['quantity']; $defq = (int)$p['defective_quantity']; ?>
                <tr>
                    <td>
                        <div class="fw-600"><?php echo sanitize($p['name']); ?></div>
                        <div class="fs-11 text-muted">SKU: <?php echo sanitize($p['sku']); ?></div>
                    </td>
                    <td class="fs-13"><?php echo $p['category_name'] ? sanitize($p['category_name']) : 'N/A'; ?></td>
                    <td><?php echo $p['size'] ? sanitize($p['size']) : '<span class="text-muted">-</span>'; ?></td>
                    <td class="text-end">
                        <span class="badge-pill <?php echo $avail <= 0 ? 'badge-danger' : 'badge-success'; ?>"><?php echo $avail; ?> <?php echo sanitize($p['unit']); ?></span>
                    </td>
                    <td class="text-end">
                        <span class="badge-pill <?php echo $defq > 0 ? 'badge-danger' : ''; ?>" style="<?php echo $defq > 0 ? '' : 'color:var(--on-surface-subtle);'; ?>"><?php echo $defq; ?></span>
                    </td>
                    <td class="text-end fw-600"><?php echo formatCurrency($p['selling_price']); ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <div class="table-footer">
        <span><?php echo count($products); ?> product<?php echo count($products) != 1 ? 's' : ''; ?></span>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
