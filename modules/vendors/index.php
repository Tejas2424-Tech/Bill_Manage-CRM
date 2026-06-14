<?php
/**
 * Vendor Management Index
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle = 'Vendors';
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Vendors</span>';

$branch_id = $_SESSION['branch_id'];
$branch_filter = isAdmin() ? "" : " WHERE branch_id = " . (int)$branch_id;

// Handle Delete (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
    } else {
        $vendor_id = (int)$_POST['id'];
        
        // Check if vendor has purchases
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE vendor_id = ?");
        $stmt->execute([$vendor_id]);
        if ($stmt->fetchColumn() > 0) {
            flashMessage('danger', 'Cannot delete vendor with existing purchase records.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM vendors WHERE id = ?" . (!isAdmin() ? " AND branch_id = " . (int)$branch_id : ""));
            $stmt->execute([$vendor_id]);
            logAudit('delete_vendor', 'vendors', "Deleted vendor ID: $vendor_id");
            flashMessage('success', 'Vendor deleted successfully.');
        }
    }
    redirect('index.php');
}

// Handle Toggle Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $vendor_id = (int)$_POST['id'];
        $stmt = $pdo->prepare("UPDATE vendors SET status = IF(status=1, 0, 1) WHERE id = ?" . (!isAdmin() ? " AND branch_id = " . (int)$branch_id : ""));
        $stmt->execute([$vendor_id]);
        flashMessage('success', 'Vendor status updated.');
    }
    redirect('index.php');
}

// Fetch Vendors with stats
$query = "SELECT v.*, 
          (SELECT COUNT(*) FROM purchases WHERE vendor_id = v.id) as total_purchases,
          (SELECT SUM(total_amount) FROM purchases WHERE vendor_id = v.id) as total_amount 
          FROM vendors v 
          $branch_filter 
          ORDER BY v.name ASC";
$vendors = $pdo->query($query)->fetchAll();

$csrf_token = generateCSRFToken();

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>Vendor Directory</h1>
    <a href="add.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> Add Vendor
    </a>
</div>

<div class="table-wrapper">
    <div class="table-toolbar">
        <input type="text" id="vendorSearch" class="form-control" placeholder="Search vendors..." onkeyup="filterTable('vendorSearch', 'vendorTable')" style="max-width: 250px;">
    </div>
    <table class="data-table" id="vendorTable">
        <thead>
            <tr>
                <th>Name</th>
                <th>Contact</th>
                <th>GST No</th>
                <th>Payment</th>
                <th>Status</th>
                <th>Stats</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($vendors as $v): ?>
                <tr>
                    <td>
                        <div class="fw-600"><?php echo sanitize($v['name']); ?></div>
                        <div class="fs-11 text-muted truncate" style="max-width: 200px;"><?php echo sanitize($v['address']); ?></div>
                    </td>
                    <td><?php echo sanitize($v['phone']); ?></td>
                    <td><span class="text-secondary"><?php echo $v['gst_number'] ?: '-'; ?></span></td>
                    <td>
                        <?php 
                        $icon = $v['payment_type'] === 'bank' ? 'fa-building-columns' : ($v['payment_type'] === 'upi' ? 'fa-mobile-screen' : 'fa-money-bill');
                        ?>
                        <span class="badge-pill badge-info">
                            <i class="fas <?php echo $icon; ?> me-1 fs-10"></i> <?php echo strtoupper($v['payment_type']); ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge-pill <?php echo $v['status'] ? 'badge-success' : 'badge-danger'; ?>">
                            <?php echo $v['status'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td>
                        <div class="fs-12 fw-500"><?php echo $v['total_purchases']; ?> Purchases</div>
                        <div class="fs-11 text-success fw-600"><?php echo formatCurrency($v['total_amount'] ?? 0); ?></div>
                    </td>
                    <td class="text-end">
                        <div class="d-flex justify-end gap-1">
                            <a href="report.php?vendor_id=<?php echo $v['id']; ?>" class="btn btn-ghost btn-icon btn-sm" title="Purchase Report"><i class="fas fa-chart-bar"></i></a>
                            <a href="edit.php?id=<?php echo $v['id']; ?>" class="btn btn-ghost btn-icon btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                            <form action="" method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="id" value="<?php echo $v['id']; ?>">
                                <input type="hidden" name="action" value="toggle">
                                <button type="submit" class="btn btn-ghost btn-icon btn-sm text-warning" title="Toggle Status"><i class="fas fa-power-off"></i></button>
                            </form>
                            <form action="" method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="id" value="<?php echo $v['id']; ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" class="btn btn-ghost btn-icon btn-sm text-danger" data-confirm="Delete this vendor?" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
