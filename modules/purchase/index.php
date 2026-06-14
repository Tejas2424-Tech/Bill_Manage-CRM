<?php
/**
 * Purchase Management Index
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle = 'Purchases';
$breadcrumb = '<a href="' . BASE_URL . '/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Purchase Orders</span>';

$branch_id = $_SESSION['branch_id'];
$branch_filter = isAdmin() ? "" : " AND p.branch_id = " . (int)$branch_id;

// Handle Delete (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
    } else {
        $id = (int)$_POST['id'];
        
        // Note: In a production system, you'd probably want to reverse stock here.
        // For this task, we'll just delete the record as requested, with a warning.
        try {
            $pdo->beginTransaction();
            
            // Delete items first (cascade should handle it but being explicit)
            $stmt = $pdo->prepare("DELETE FROM purchase_items WHERE purchase_id = ?");
            $stmt->execute([$id]);
            
            // Delete purchase
            $stmt = $pdo->prepare("DELETE FROM purchases WHERE id = ?" . (!isAdmin() ? " AND branch_id = " . (int)$branch_id : ""));
            $stmt->execute([$id]);
            
            $pdo->commit();
            logAudit('delete_purchase', 'purchase', "Deleted purchase ID: $id");
            flashMessage('success', 'Purchase record deleted successfully.');
        } catch (Exception $e) {
            $pdo->rollBack();
            flashMessage('danger', 'Error: ' . $e->getMessage());
        }
    }
    redirect('index.php');
}

// Filters
$vendor_id = (int)($_GET['vendor_id'] ?? 0);
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

$params = [$date_from, $date_to];
$where = "DATE(p.purchase_date) BETWEEN ? AND ?";

if ($vendor_id) {
    $where .= " AND p.vendor_id = ?";
    $params[] = $vendor_id;
}

// Fetch Purchases
$query = "SELECT p.*, v.name as vendor_name, u.name as creator_name, 
          (SELECT COUNT(*) FROM purchase_items WHERE purchase_id = p.id) as item_count 
          FROM purchases p 
          LEFT JOIN vendors v ON p.vendor_id = v.id 
          LEFT JOIN users u ON p.created_by = u.id 
          WHERE $where $branch_filter 
          ORDER BY p.purchase_date DESC, p.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$purchases = $stmt->fetchAll();

// Monthly Summary
$stmt = $pdo->query("SELECT SUM(total_amount) FROM purchases p WHERE MONTH(purchase_date) = MONTH(CURDATE()) AND YEAR(purchase_date) = YEAR(CURDATE()) $branch_filter");
$monthly_total = $stmt->fetchColumn() ?? 0;

$vendors = $pdo->query("SELECT id, name FROM vendors WHERE status=1 ORDER BY name ASC")->fetchAll();
$csrf_token = generateCSRFToken();

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="stat-grid mb-4">
    <div class="stat-card blue">
        <div class="stat-value"><?php echo count($purchases); ?></div>
        <div class="stat-label">Total Purchases (Selected Range)</div>
    </div>
    <div class="stat-card green">
        <div class="stat-value"><?php echo formatCurrency($monthly_total); ?></div>
        <div class="stat-label">This Month Purchases</div>
    </div>
</div>

<div class="table-wrapper">
    <div class="table-toolbar flex-wrap gap-3">
        <form action="" method="GET" class="d-flex gap-2 flex-grow-1">
            <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>" style="max-width: 150px;">
            <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>" style="max-width: 150px;">
            <select name="vendor_id" class="form-control" style="max-width: 180px;">
                <option value="">All Vendors</option>
                <?php foreach ($vendors as $v): ?>
                    <option value="<?php echo $v['id']; ?>" <?php echo $vendor_id == $v['id'] ? 'selected' : ''; ?>><?php echo $v['name']; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline btn-icon"><i class="fas fa-filter"></i></button>
        </form>
        <a href="add.php" class="btn btn-primary ms-auto"><i class="fas fa-plus me-1"></i> New Purchase</a>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Invoice #</th>
                <th>Vendor</th>
                <th>Items</th>
                <th>Total Amount</th>
                <th>Date</th>
                <th>Created By</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($purchases)): ?>
                <tr><td colspan="7" class="text-center py-5 text-muted">No purchase records found.</td></tr>
            <?php else: ?>
                <?php foreach ($purchases as $p): ?>
                    <tr>
                        <td class="fw-700 text-primary"><?php echo $p['invoice_number'] ?: 'N/A'; ?></td>
                        <td><div class="fw-600"><?php echo sanitize($p['vendor_name'] ?? 'Direct Purchase'); ?></div></td>
                        <td><span class="badge bg-secondary"><?php echo $p['item_count']; ?> items</span></td>
                        <td class="fw-700 text-success"><?php echo formatCurrency($p['total_amount']); ?></td>
                        <td class="fs-12"><?php echo formatDate($p['purchase_date']); ?></td>
                        <td class="fs-12 text-secondary"><?php echo sanitize($p['creator_name']); ?></td>
                        <td class="text-end">
                            <div class="d-flex justify-end gap-1">
                                <a href="view.php?id=<?php echo $p['id']; ?>" class="btn btn-ghost btn-icon btn-sm" title="View"><i class="fas fa-eye"></i></a>
                                <form action="" method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button type="submit" class="btn btn-ghost btn-icon btn-sm text-danger" data-confirm="Delete this purchase? Stock will NOT be automatically reversed." title="Delete">
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
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
