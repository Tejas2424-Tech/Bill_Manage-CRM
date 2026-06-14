<?php
/**
 * Bulk Barcode Print
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = 'Bulk Barcodes';
$breadcrumb = '<a href="' . BASE_URL . '/modules/products/index.php">Products</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Bulk Barcodes</span>';

$search      = sanitize($_GET['search'] ?? '');
$category_id = sanitize($_GET['category_id'] ?? '');

$params = [];
$where  = ["p.status != 'deleted'", "p.barcode != ''", "p.barcode IS NOT NULL"];

if (!isAdmin()) {
    $where[] = "p.branch_id = ?";
    $params[] = $_SESSION['branch_id'];
}

if ($search) {
    $where[] = "(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
    $s = "%$search%";
    $params[] = $s; $params[] = $s; $params[] = $s;
}

if ($category_id) {
    $where[] = "p.category_id = ?";
    $params[] = $category_id;
}

$where_sql = implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT p.*, c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE $where_sql
    ORDER BY p.name ASC");
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $pdo->query("SELECT id, name FROM categories WHERE status=1 ORDER BY name ASC")->fetchAll();

include_once __DIR__ . '/../../includes/header.php';
?>

<style>
@media print {
    body * { visibility: hidden; }
    #printArea, #printArea * { visibility: visible; }
    #printArea {
        position: absolute; left: 0; top: 0; width: 100%;
        display: block !important; padding: 10px;
    }
    .label-card {
        border: 1px solid #ccc; padding: 8px; text-align: center;
        page-break-inside: avoid; background: #fff;
    }
    .label-name  { font-size: 10px; font-weight: bold; margin-bottom: 2px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
    .label-price { font-size: 12px; font-weight: bold; margin-top: 2px; }
}

.bulk-layout { display: flex; gap: 20px; align-items: flex-start; }
.bulk-left   { flex: 1 1 0; min-width: 0; }
.bulk-right  { width: 280px; flex-shrink: 0; }
.selection-footer {
    display: flex; align-items: center; gap: 10px; padding: 10px 16px;
    border-top: 1px solid var(--border); background: var(--surface-variant);
}
.qty-input { width: 70px; text-align: center; padding: 4px 8px; }
</style>

<div class="page-header">
    <div class="page-header-left">
        <h1>Bulk Barcodes</h1>
        <div class="sub">Select products and print multiple barcode labels at once</div>
    </div>
    <div class="page-header-actions">
        <a href="index.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back to Products</a>
    </div>
</div>

<div class="bulk-layout">

    <!-- LEFT: Product selection -->
    <div class="bulk-left">
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
                    <button type="submit" class="btn btn-outline"><i class="fas fa-filter"></i> Filter</button>
                    <?php if ($search || $category_id): ?>
                        <a href="bulk_barcode.php" class="btn btn-ghost">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (empty($products)): ?>
                <div class="empty-state" style="padding:48px 24px;">
                    <i class="fas fa-barcode empty-icon"></i>
                    <h3>No products with barcodes found</h3>
                    <p class="text-muted">Only products that have a barcode value assigned are shown here.</p>
                    <a href="index.php" class="btn btn-outline btn-sm mt-2">Go to Products</a>
                </div>
            <?php else: ?>
                <table class="data-table" id="bulkTable">
                    <thead>
                        <tr>
                            <th style="width:36px;"><input type="checkbox" id="checkAll" title="Select all"></th>
                            <th>Product</th>
                            <th>Category</th>
                            <th style="width:80px;">Stock</th>
                            <th style="width:100px;">Print Qty</th>
                            <th style="width:80px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p): ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="row-check"
                                    data-id="<?php echo $p['id']; ?>"
                                    data-barcode="<?php echo htmlspecialchars($p['barcode'], ENT_QUOTES); ?>"
                                    data-name="<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>"
                                    data-price="<?php echo formatCurrency($p['selling_price']); ?>"
                                    data-stock="<?php echo (int)$p['quantity']; ?>">
                            </td>
                            <td>
                                <div class="fw-600 fs-13"><?php echo sanitize($p['name']); ?></div>
                                <div class="fs-11 text-muted">SKU: <?php echo sanitize($p['sku']); ?> &nbsp;|&nbsp; <?php echo sanitize($p['barcode']); ?></div>
                            </td>
                            <td class="fs-13"><?php echo sanitize($p['category_name'] ?? 'Uncategorized'); ?></td>
                            <td>
                                <span class="badge-pill <?php echo $p['quantity'] <= 0 ? 'badge-danger' : ($p['quantity'] <= $p['alert_quantity'] ? 'badge-warning' : 'badge-success'); ?>">
                                    <?php echo $p['quantity']; ?> <?php echo sanitize($p['unit']); ?>
                                </span>
                            </td>
                            <td>
                                <input type="number" class="form-control qty-input" value="1" min="1" max="999">
                            </td>
                            <td>
                                <button type="button" class="btn btn-ghost btn-sm stock-btn"
                                    data-stock="<?php echo (int)$p['quantity']; ?>"
                                    title="Set qty to current stock">
                                    <i class="fas fa-layer-group"></i> Stock
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="selection-footer">
                    <span id="selectedCount" class="fw-600 fs-13">0 selected</span>
                    <button type="button" id="selectAllBtn" class="btn btn-outline btn-sm">Select All</button>
                    <button type="button" id="clearAllBtn"  class="btn btn-ghost btn-sm">Clear</button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT: Layout config -->
    <div class="bulk-right">
        <div class="card" style="padding:20px;">
            <div class="card-header" style="padding:0 0 16px 0;border-bottom:1px solid var(--border);margin-bottom:16px;">
                <h3 class="card-title" style="margin:0;"><i class="fas fa-sliders" style="color:var(--primary);margin-right:6px;"></i> Label Layout</h3>
            </div>

            <div class="form-group">
                <label class="form-label">Columns per Row</label>
                <select id="colSetting" class="form-control">
                    <option value="2">2 columns</option>
                    <option value="3" selected>3 columns</option>
                    <option value="4">4 columns</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Barcode Height: <span id="heightVal">60</span>px</label>
                <input type="range" id="heightSetting" min="20" max="100" value="60" step="5"
                       class="form-control" style="padding:4px 0;cursor:pointer;">
            </div>

            <div class="form-group">
                <label class="form-label">Width Factor</label>
                <input type="number" id="widthSetting" class="form-control" value="1.5" min="0.5" max="3.0" step="0.1">
                <div class="fs-11 text-muted mt-1">Controls bar density (0.5 – 3.0)</div>
            </div>

            <button type="button" id="generateBtn" class="btn btn-primary w-100 mt-2" style="margin-top:12px;">
                <i class="fas fa-print"></i> Generate &amp; Print
            </button>

            <div style="margin-top:14px;padding:12px;background:var(--surface-variant);border-radius:var(--radius);font-size:12px;color:var(--on-surface-muted);line-height:1.6;">
                <i class="fas fa-circle-info" style="color:var(--info);margin-right:4px;"></i>
                Select products on the left, configure layout, then click <strong>Generate &amp; Print</strong>.
            </div>
        </div>
    </div>
</div>

<!-- Print area — built by JS, hidden in UI -->
<div id="printArea" style="display:none;"></div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3/dist/JsBarcode.all.min.js"></script>
<script>
(function () {
    // ── Selection counter ──────────────────────────────────
    function updateCount() {
        const n = document.querySelectorAll('.row-check:checked').length;
        document.getElementById('selectedCount').textContent = n + ' selected';
    }

    document.querySelectorAll('.row-check').forEach(cb => cb.addEventListener('change', updateCount));

    document.getElementById('checkAll')?.addEventListener('change', function () {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
        updateCount();
    });

    document.getElementById('selectAllBtn')?.addEventListener('click', function () {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = true);
        document.getElementById('checkAll') && (document.getElementById('checkAll').checked = true);
        updateCount();
    });

    document.getElementById('clearAllBtn')?.addEventListener('click', function () {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = false);
        document.getElementById('checkAll') && (document.getElementById('checkAll').checked = false);
        updateCount();
    });

    // ── Stock button ───────────────────────────────────────
    document.querySelectorAll('.stock-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const stock = parseInt(this.dataset.stock) || 0;
            this.closest('tr').querySelector('.qty-input').value = Math.max(stock, 1);
        });
    });

    // ── Height slider live label ───────────────────────────
    document.getElementById('heightSetting')?.addEventListener('input', function () {
        document.getElementById('heightVal').textContent = this.value;
    });

    // ── Generate & Print ───────────────────────────────────
    document.getElementById('generateBtn')?.addEventListener('click', generateAndPrint);

    function generateAndPrint() {
        const checked = [...document.querySelectorAll('.row-check:checked')];
        if (!checked.length) {
            alert('Please select at least one product.');
            return;
        }

        const cols    = parseInt(document.getElementById('colSetting').value)   || 3;
        const bHeight = parseInt(document.getElementById('heightSetting').value) || 60;
        const bWidth  = parseFloat(document.getElementById('widthSetting').value) || 1.5;

        const area = document.getElementById('printArea');
        area.innerHTML = '';

        const grid = document.createElement('div');
        grid.style.cssText = `display:grid;grid-template-columns:repeat(${cols},1fr);gap:8px;`;

        const entries = []; // {uid, barcode}

        checked.forEach(cb => {
            const qty = parseInt(cb.closest('tr').querySelector('.qty-input').value) || 1;
            for (let i = 0; i < qty; i++) {
                const uid  = 'bc_' + cb.dataset.id + '_' + i;
                const card = document.createElement('div');
                card.className = 'label-card';
                card.innerHTML =
                    '<div class="label-name" title="' + escHtml(cb.dataset.name) + '">' + escHtml(cb.dataset.name) + '</div>' +
                    '<svg id="' + uid + '"></svg>' +
                    '<div class="label-price">' + escHtml(cb.dataset.price) + '</div>';
                grid.appendChild(card);
                entries.push({ uid: uid, barcode: cb.dataset.barcode });
            }
        });

        area.appendChild(grid);
        area.style.display = 'block';

        entries.forEach(e => {
            try {
                JsBarcode('#' + e.uid, e.barcode, {
                    format: 'CODE128',
                    width: bWidth,
                    height: bHeight,
                    displayValue: true,
                    fontSize: 10
                });
            } catch (err) {
                console.warn('Barcode render failed for', e.barcode, err);
            }
        });

        setTimeout(() => window.print(), 500);
    }

    window.onafterprint = function () {
        const area = document.getElementById('printArea');
        area.innerHTML = '';
        area.style.display = 'none';
    };

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
})();
</script>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
