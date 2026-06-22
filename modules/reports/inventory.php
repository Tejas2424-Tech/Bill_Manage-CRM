<?php
/**
 * Inventory Snapshot Report
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle = 'Inventory Snapshot';
$breadcrumb = '<a href="' . BASE_URL . '/modules/reports/index.php">Reports</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Inventory Report</span>';

$branch_id = $_SESSION['branch_id'];
$isAdmin = isAdmin();

// Branch Filter
$selected_branch = $_GET['branch_id'] ?? ($isAdmin ? 'all' : $branch_id);
$branch_filter = "";
if ($selected_branch !== 'all') {
    $branch_filter = " AND p.branch_id = " . (int)$selected_branch;
}

// 1. Fetch Inventory Data
$query = "SELECT p.*, c.name as category_name 
          FROM products p 
          LEFT JOIN categories c ON p.category_id = c.id 
          WHERE p.status = 'active' $branch_filter 
          ORDER BY (p.purchase_price * p.quantity) DESC";
$products = $pdo->query($query)->fetchAll();

// 2. Summary Stats
$stats = [
    'total_items' => count($products),
    'total_stock_value' => 0,
    'low_stock_count' => 0,
    'dead_stock_count' => 0
];

foreach ($products as $p) {
    $stats['total_stock_value'] += ($p['purchase_price'] * $p['quantity']);
    if ($p['quantity'] <= $p['alert_quantity']) $stats['low_stock_count']++;
    
    // Dead stock: not updated for more than dead_stock_days
    $last_update = strtotime($p['updated_at']);
    $days_since_update = (time() - $last_update) / (60 * 60 * 24);
    if ($days_since_update > $p['dead_stock_days']) $stats['dead_stock_count']++;
}

// Handle Export CSV
if (isset($_POST['export'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
    } else {
        $filename = "inventory_snapshot_" . date('Y-m-d') . ".csv";
        $headers = ['Product', 'SKU', 'Category', 'Purchase Price', 'Selling Price', 'Qty', 'Stock Value', 'Last Updated'];
        $export_data = [];
        foreach ($products as $p) {
            $export_data[] = [
                $p['name'],
                $p['sku'],
                $p['category_name'],
                $p['purchase_price'],
                $p['selling_price'],
                $p['quantity'],
                $p['purchase_price'] * $p['quantity'],
                $p['updated_at']
            ];
        }
        exportCSV($filename, $headers, $export_data);
    }
}

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header flex-wrap gap-3">
    <h1>Inventory Snapshot</h1>
    <div class="d-flex gap-2">
        <form action="" method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="export" value="1">
            <button type="submit" class="btn btn-outline btn-sm"><i class="fas fa-file-csv me-1"></i> Export CSV</button>
        </form>
    </div>
</div>


<div class="stat-grid mb-4">
    <div class="stat-card blue">
        <div class="stat-value"><?php echo $stats['total_items']; ?></div>
        <div class="stat-label">Total Products</div>
    </div>
    <div class="stat-card green">
        <div class="stat-value"><?php echo formatCurrency($stats['total_stock_value']); ?></div>
        <div class="stat-label">Total Stock Value (Purchase)</div>
    </div>
    <div class="stat-card orange">
        <div class="stat-value"><?php echo $stats['low_stock_count']; ?></div>
        <div class="stat-label">Low Stock Items</div>
    </div>
    <div class="stat-card red">
        <div class="stat-value"><?php echo $stats['dead_stock_count']; ?></div>
        <div class="stat-label">Dead Stock (>90 Days)</div>
    </div>
</div>

<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>Category</th>
                <th>Purchase</th>
                <th>Selling</th>
                <th>Qty</th>
                <th>Stock Value</th>
                <th>Last Updated</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products as $p): 
                $stock_val = $p['purchase_price'] * $p['quantity'];
                $is_low = $p['quantity'] <= $p['alert_quantity'];
            ?>
                <tr class="<?php echo $is_low ? 'bg-warning-subtle' : ''; ?>">
                    <td>
                        <div class="fw-600"><?php echo sanitize($p['name']); ?></div>
                        <div class="fs-11 text-muted">SKU: <?php echo $p['sku']; ?></div>
                    </td>
                    <td class="fs-12"><?php echo sanitize($p['category_name'] ?: 'Uncategorized'); ?></td>
                    <td><?php echo formatCurrency($p['purchase_price']); ?></td>
                    <td><?php echo formatCurrency($p['selling_price']); ?></td>
                    <td>
                        <span class="fw-700 <?php echo $is_low ? 'text-danger' : ''; ?>">
                            <?php echo $p['quantity']; ?> <?php echo $p['unit']; ?>
                        </span>
                    </td>
                    <td class="fw-700 text-primary"><?php echo formatCurrency($stock_val); ?></td>
                    <td class="fs-11 text-muted"><?php echo formatDate($p['updated_at']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>

