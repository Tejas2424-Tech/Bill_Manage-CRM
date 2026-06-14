<?php
/**
 * View Purchase Details
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$purchase_id = (int)($_GET['id'] ?? 0);
if (!$purchase_id) redirect('index.php');

// Fetch Purchase with Vendor and Creator info
$stmt = $pdo->prepare("SELECT p.*, v.name as vendor_name, v.phone as vendor_phone, v.address as vendor_address, u.name as creator_name 
                     FROM purchases p 
                     LEFT JOIN vendors v ON p.vendor_id = v.id 
                     JOIN users u ON p.created_by = u.id 
                     WHERE p.id = ?" . (!isAdmin() ? " AND p.branch_id = " . (int)$_SESSION['branch_id'] : ""));
$stmt->execute([$purchase_id]);
$purchase = $stmt->fetch();

if (!$purchase) {
    flashMessage('danger', 'Purchase record not found or access denied.');
    redirect('index.php');
}

// Fetch Purchase Items
$stmt = $pdo->prepare("SELECT pi.*, p.name as product_name, p.sku 
                     FROM purchase_items pi 
                     JOIN products p ON pi.product_id = p.id 
                     WHERE pi.purchase_id = ?");
$stmt->execute([$purchase_id]);
$items = $stmt->fetchAll();

$pageTitle = 'Purchase Details: ' . ($purchase['invoice_number'] ?: 'Direct');
include_once __DIR__ . '/../../includes/header.php';
?>

<style>
    @media print {
        .no-print { display: none !important; }
        .main-content { margin: 0 !important; padding: 0 !important; }
        .page-content { padding: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
    }
</style>

<div class="no-print d-flex justify-between align-center mb-4">
    <a href="index.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left me-1"></i> Back to List</a>
    <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print me-1"></i> Print Record</button>
</div>

<div class="card">
    <div class="row mb-5">
        <div class="col-md-6">
            <div class="pos-section-title">Vendor Information</div>
            <div class="fw-700 fs-18 text-primary"><?php echo sanitize($purchase['vendor_name'] ?? 'Direct Purchase'); ?></div>
            <?php if ($purchase['vendor_phone']): ?>
                <div class="text-secondary fs-14 mt-1"><i class="fas fa-phone me-1"></i> <?php echo $purchase['vendor_phone']; ?></div>
            <?php endif; ?>
            <?php if ($purchase['vendor_address']): ?>
                <div class="text-muted fs-13 mt-1"><?php echo nl2br(sanitize($purchase['vendor_address'])); ?></div>
            <?php endif; ?>
        </div>
        <div class="col-md-6 text-md-end mt-4 mt-md-0">
            <div class="pos-section-title">Purchase Details</div>
            <div class="fs-14"><span class="text-muted">Invoice No:</span> <span class="fw-600"><?php echo $purchase['invoice_number'] ?: 'N/A'; ?></span></div>
            <div class="fs-14"><span class="text-muted">Purchase Date:</span> <span class="fw-600"><?php echo formatDate($purchase['purchase_date']); ?></span></div>
            <div class="fs-14"><span class="text-muted">Recorded By:</span> <span class="fw-600"><?php echo sanitize($purchase['creator_name']); ?></span></div>
            <div class="fs-12 text-muted mt-1">ID: #<?php echo $purchase['id']; ?> | <?php echo formatDateTime($purchase['created_at']); ?></div>
        </div>
    </div>

    <table class="data-table mt-4">
        <thead>
            <tr>
                <th style="width: 50px;">#</th>
                <th>Product / Item</th>
                <th>Quantity</th>
                <th>Unit Price</th>
                <th class="text-end">Line Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $idx => $item): ?>
                <tr>
                    <td><?php echo $idx + 1; ?></td>
                    <td>
                        <div class="fw-600"><?php echo sanitize($item['product_name']); ?></div>
                        <div class="fs-11 text-muted">SKU: <?php echo $item['sku']; ?></div>
                    </td>
                    <td><?php echo $item['quantity']; ?></td>
                    <td><?php echo formatCurrency($item['purchase_price']); ?></td>
                    <td class="text-end fw-600"><?php echo formatCurrency($item['total']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="bg-secondary">
                <td colspan="4" class="text-end fw-700 py-3 fs-15">GRAND TOTAL:</td>
                <td class="text-end fw-800 py-3 fs-18 text-success"><?php echo formatCurrency($purchase['total_amount']); ?></td>
            </tr>
        </tfoot>
    </table>

    <?php if ($purchase['note']): ?>
        <div class="mt-5 p-3 bg-secondary-subtle rounded border border-secondary">
            <div class="pos-section-title mb-2">Internal Note</div>
            <div class="fs-13 text-secondary italic"><?php echo nl2br(sanitize($purchase['note'])); ?></div>
        </div>
    <?php endif; ?>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
