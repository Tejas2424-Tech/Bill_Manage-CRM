<?php
/**
 * Defective Replacement commit — take back a defective item and issue a
 * replacement. Defective stock +1, replacement normal stock -1, both logged.
 * Sales totals are NOT touched. Rule: replacement value >= original sale price.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../customers/customer_lib.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    flashMessage('danger', 'Invalid security token.');
    redirect('index.php');
}

$isAdmin   = isAdmin();
$branch_id = (int)($_SESSION['branch_id'] ?? 0);

$bill_item_id   = (int)($_POST['original_bill_item_id'] ?? 0);
$new_product_id = (int)($_POST['replacement_product_id'] ?? 0);
$new_disc       = max(0, min(100, (float)($_POST['replacement_discount_percent'] ?? 0)));
$defect_reason  = sanitize($_POST['defect_reason'] ?? '');

if (trim($defect_reason) === '') {
    flashMessage('danger', 'A defect reason is required.');
    redirect('index.php');
}

// 1. Resolve the defective (old) bill line + bill.
$stmt = $pdo->prepare("SELECT bi.id AS item_id, bi.product_id, bi.product_name, bi.selling_price, bi.quantity, bi.discount_percent, bi.total,
                              b.id AS bill_id, b.branch_id, b.customer_id, b.customer_name, b.customer_phone, b.status,
                              COALESCE(bi.size, p.size) AS old_size
                       FROM bill_items bi
                       JOIN bills b ON bi.bill_id = b.id
                       LEFT JOIN products p ON bi.product_id = p.id
                       WHERE bi.id = ?" . (!$isAdmin ? " AND b.branch_id = " . $branch_id : ""));
$stmt->execute([$bill_item_id]);
$old = $stmt->fetch();

if (!$old) { flashMessage('danger', 'Original bill item not found or access denied.'); redirect('index.php'); }
if ($old['status'] === 'cancelled') { flashMessage('danger', 'Cannot process against a cancelled bill.'); redirect('index.php'); }

$dr_branch = (int)$old['branch_id'];
$old_qty   = max(1, (int)$old['quantity']);
$final_sale_price = round((float)$old['total'] / $old_qty, 2);

$old_disc = (float)$old['discount_percent'];
if ($old_disc <= 0 && (float)$old['selling_price'] > 0) {
    $old_disc = round((1 - ($final_sale_price / (float)$old['selling_price'])) * 100, 2);
    if ($old_disc < 0) $old_disc = 0;
}

// 2. Resolve the replacement product (same branch as the bill).
$stmt = $pdo->prepare("SELECT p.id, p.name, p.size, p.selling_price, p.quantity
                       FROM products p WHERE p.id = ? AND p.status = 'active' AND p.branch_id = ?");
$stmt->execute([$new_product_id, $dr_branch]);
$new = $stmt->fetch();
if (!$new) { flashMessage('danger', 'Replacement product not found in this branch.'); redirect('index.php'); }

$new_mrp   = (float)$new['selling_price'];
$new_final = round($new_mrp * (1 - $new_disc / 100), 2);

// 3. Value rule: replacement value must be equal or greater (no downgrade). No payment.
if ($new_final < $final_sale_price) {
    flashMessage('danger', 'Replacement value cannot be lower than the original sale price. Lower value product not allowed.');
    redirect('index.php?mobile=' . urlencode($old['customer_phone']));
}
if ((int)$new['quantity'] < 1) { flashMessage('danger', 'Replacement product is out of stock.'); redirect('index.php'); }

$customer_id = $old['customer_id'] ?: findOrCreateCustomer($pdo, $dr_branch, (string)$old['customer_name'], (string)$old['customer_phone'], null, (int)$_SESSION['user_id']);

try {
    $pdo->beginTransaction();

    // 4. Record the defective replacement.
    $stmt = $pdo->prepare("INSERT INTO defective_replacements
        (branch_id, customer_id, original_bill_id, original_bill_item_id, defective_product_id, defective_product_name, defective_size, defect_reason,
         original_price, original_discount_percent, final_sale_price,
         replacement_product_id, replacement_product_name, replacement_size, replacement_mrp, replacement_discount_percent, replacement_final_price, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $dr_branch, $customer_id, (int)$old['bill_id'], $bill_item_id, $old['product_id'], $old['product_name'], $old['old_size'], $defect_reason,
        (float)$old['selling_price'], $old_disc, $final_sale_price,
        $new['id'], $new['name'], $new['size'], $new_mrp, $new_disc, $new_final, $_SESSION['user_id']
    ]);
    $dr_id = (int)$pdo->lastInsertId();

    // 5. Stock: defective unit → defective bucket (+1); replacement → normal (-1).
    if (!empty($old['product_id'])) {
        $pdo->prepare("UPDATE products SET defective_quantity = defective_quantity + 1 WHERE id = ?")->execute([$old['product_id']]);
        $pdo->prepare("INSERT INTO inventory_log (branch_id, product_id, type, quantity, reference_type, reference_id, note, created_by) VALUES (?, ?, 'adjustment', 1, 'defective', ?, ?, ?)")
            ->execute([$dr_branch, $old['product_id'], $dr_id, 'Defective return: ' . $defect_reason, $_SESSION['user_id']]);
    }

    $upd = $pdo->prepare("UPDATE products SET quantity = quantity - 1 WHERE id = ? AND quantity >= 1");
    $upd->execute([$new['id']]);
    if ($upd->rowCount() === 0) throw new Exception('Replacement product went out of stock.');
    $pdo->prepare("INSERT INTO inventory_log (branch_id, product_id, type, quantity, reference_type, reference_id, note, created_by) VALUES (?, ?, 'out', 1, 'defective', ?, ?, ?)")
        ->execute([$dr_branch, $new['id'], $dr_id, 'Replacement issue: ' . $new['name'], $_SESSION['user_id']]);

    $pdo->commit();
    logAudit('defective_replacement', 'defective', "Defective replacement #$dr_id ({$old['product_name']} → {$new['name']})");
    flashMessage('success', 'Defective replacement recorded. Defective stock +1, replacement issued. Sales unchanged.');
} catch (Exception $e) {
    $pdo->rollBack();
    flashMessage('danger', 'Error: ' . $e->getMessage());
}

redirect('index.php');
