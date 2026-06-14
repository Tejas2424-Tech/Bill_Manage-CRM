<?php
/**
 * Thermal Receipt View
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$bill_id = (int)($_GET['id'] ?? 0);
if (!$bill_id) exit('Invalid ID');

$stmt = $pdo->prepare("SELECT b.*, br.name as branch_name, br.address as branch_address, br.phone as branch_phone, u.name as cashier_name 
                     FROM bills b 
                     JOIN branches br ON b.branch_id = br.id 
                     JOIN users u ON b.created_by = u.id 
                     WHERE b.id = ?" . (!isAdmin() ? " AND b.branch_id = " . (int)$_SESSION['branch_id'] : ""));
$stmt->execute([$bill_id]);
$bill = $stmt->fetch();

if (!$bill) exit('Access Denied');

$stmt = $pdo->prepare("SELECT * FROM bill_items WHERE bill_id = ?");
$stmt->execute([$bill_id]);
$items = $stmt->fetchAll();

$company_name = getSettingValue('company_name') ?: 'BillManage';
$company_logo = getSettingValue('company_logo');
$gst_number = getSettingValue('gst_number') ?: '';
$invoice_prefix = getSettingValue('invoice_prefix') ?: 'INV-';
$show_gst = getSettingValue('show_gst') ?? '1';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt #<?php echo $bill['bill_number']; ?></title>
    <style>
        body { font-family: 'Courier New', Courier, monospace; width: 72mm; margin: 0; padding: 10px; font-size: 12px; line-height: 1.2; color: black; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .divider { border-top: 1px dashed #000; margin: 8px 0; }
        .item-table { width: 100%; border-collapse: collapse; }
        .item-table td { padding: 2px 0; vertical-align: top; }
        .total-table { width: 100%; margin-top: 5px; }
        .total-table td { padding: 2px 0; }
        .receipt-logo { max-width: 40mm; max-height: 15mm; margin-bottom: 5px; filter: grayscale(100%) contrast(200%); }
        @media print {
            @page { margin: 0; }
            body { margin: 0; }
        }
    </style>
</head>
<body onload="window.print(); setTimeout(window.close, 500);">
    <div class="text-center">
        <?php if ($company_logo): ?>
            <img src="<?php echo BASE_URL; ?>/assets/images/logo/<?php echo $company_logo; ?>" class="receipt-logo" alt="Logo"><br>
        <?php endif; ?>
        <strong style="font-size: 16px;"><?php echo strtoupper($company_name); ?></strong><br>
        <?php echo $bill['branch_name']; ?><br>
        <?php echo $bill['branch_phone']; ?><br>
        <?php if ($show_gst == '1' && $gst_number): ?>GSTIN: <?php echo $gst_number; ?><br><?php endif; ?>
    </div>

    <div class="divider"></div>

    <div>
        No: <?php echo $invoice_prefix . $bill['bill_number']; ?><br>
        Date: <?php echo date('d/m/y H:i', strtotime($bill['created_at'])); ?><br>
        Cashier: <?php echo $bill['cashier_name']; ?>
    </div>

    <div class="divider"></div>

    <table class="item-table">
        <?php foreach ($items as $item): ?>
            <tr>
                <td colspan="3"><?php echo sanitize($item['product_name']); ?></td>
            </tr>
            <tr>
                <td style="width: 40%;"><?php echo $item['quantity']; ?> x <?php echo number_format($item['selling_price'], 2); ?></td>
                <td class="text-right" style="width: 30%;">-<?php echo number_format($item['discount'], 2); ?></td>
                <td class="text-right" style="width: 30%;"><?php echo number_format($item['total'], 2); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <div class="divider"></div>

    <table class="total-table">
        <tr>
            <td>Subtotal:</td>
            <td class="text-right"><?php echo number_format($bill['subtotal'], 2); ?></td>
        </tr>
        <?php if ($bill['discount_amount'] > 0): ?>
            <tr>
                <td>Discount (<?php echo $bill['discount_percent']; ?>%):</td>
                <td class="text-right">-<?php echo number_format($bill['discount_amount'], 2); ?></td>
            </tr>
        <?php endif; ?>
        <tr>
            <td>GST (<?php echo $bill['gst_percent']; ?>%):</td>
            <td class="text-right"><?php echo number_format($bill['gst_amount'], 2); ?></td>
        </tr>
        <tr style="font-weight: bold; font-size: 14px;">
            <td>GRAND TOTAL:</td>
            <td class="text-right"><?php echo number_format($bill['total_amount'], 2); ?></td>
        </tr>
        <?php if ($bill['bill_type'] !== 'credit'): ?>
            <tr>
                <td>Received:</td>
                <td class="text-right"><?php echo number_format($bill['paid_amount'], 2); ?></td>
            </tr>
            <tr>
                <td>Change:</td>
                <td class="text-right"><?php echo number_format($bill['paid_amount'] - $bill['total_amount'], 2); ?></td>
            </tr>
        <?php endif; ?>
    </table>

    <div class="divider"></div>

    <div class="text-center" style="font-size: 10px;">
        THANK YOU FOR SHOPPING!<br>
        Please visit again.
    </div>
</body>
</html>
