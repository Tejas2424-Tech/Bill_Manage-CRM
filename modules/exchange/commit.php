<?php
/**
 * Exchange commit — return one old item and issue one new item.
 * Enforces: new value >= return value (no refund / no cash back); old stock +1,
 * new stock -1, both logged; records the exchange. Runs in one transaction.
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
$new_product_id = (int)($_POST['new_product_id'] ?? 0);
$new_disc       = max(0, min(100, (float)($_POST['new_discount_percent'] ?? 0)));
$payment_mode   = $_POST['payment_mode'] ?? '';

// 1. Resolve the old bill line + its bill (branch-scoped for non-admins).
$stmt = $pdo->prepare("SELECT bi.id AS item_id, bi.product_id, bi.product_name, bi.selling_price, bi.quantity, bi.discount_percent, bi.total,
                              b.id AS bill_id, b.branch_id, b.customer_id, b.customer_name, b.customer_phone, b.status,
                              COALESCE(bi.size, p.size) AS old_size, c.name AS old_category
                       FROM bill_items bi
                       JOIN bills b ON bi.bill_id = b.id
                       LEFT JOIN products p ON bi.product_id = p.id
                       LEFT JOIN categories c ON p.category_id = c.id
                       WHERE bi.id = ?" . (!$isAdmin ? " AND b.branch_id = " . $branch_id : ""));
$stmt->execute([$bill_item_id]);
$old = $stmt->fetch();

if (!$old) { flashMessage('danger', 'Original bill item not found or access denied.'); redirect('index.php'); }
if ($old['status'] === 'cancelled') { flashMessage('danger', 'Cannot exchange against a cancelled bill.'); redirect('index.php'); }

$ex_branch = (int)$old['branch_id'];
$old_qty   = max(1, (int)$old['quantity']);
$return_value = round((float)$old['total'] / $old_qty, 2);   // per-unit price the customer paid

// Old discount % (use stored; else derive from amount).
$old_disc = (float)$old['discount_percent'];
if ($old_disc <= 0 && (float)$old['selling_price'] > 0) {
    $old_disc = round((1 - ($return_value / (float)$old['selling_price'])) * 100, 2);
    if ($old_disc < 0) $old_disc = 0;
}

// 2. Resolve the new product (same branch as the bill).
$stmt = $pdo->prepare("SELECT p.id, p.name, p.size, p.selling_price, p.quantity, c.name AS category
                       FROM products p LEFT JOIN categories c ON p.category_id = c.id
                       WHERE p.id = ? AND p.status = 'active' AND p.branch_id = ?");
$stmt->execute([$new_product_id, $ex_branch]);
$new = $stmt->fetch();
if (!$new) { flashMessage('danger', 'Replacement product not found in this branch.'); redirect('index.php'); }

$new_mrp   = (float)$new['selling_price'];
$new_final = round($new_mrp * (1 - $new_disc / 100), 2);
$difference = round($new_final - $return_value, 2);

// 3. Value rule: new value must be >= return value (no refund / no cash back).
if ($difference < 0) {
    flashMessage('danger', 'Exchange value cannot be lower than return value. No Refund. No Cash Back.');
    redirect('index.php?mobile=' . urlencode($old['customer_phone']));
}
if ($difference > 0 && !in_array($payment_mode, ['cash','online','card'], true)) {
    flashMessage('danger', 'Select how the customer paid the difference of ' . formatCurrency($difference) . '.');
    redirect('index.php?mobile=' . urlencode($old['customer_phone']));
}
if ($difference <= 0) $payment_mode = null;
if ((int)$new['quantity'] < 1) { flashMessage('danger', 'Replacement product is out of stock.'); redirect('index.php'); }

// Customer link (reuse bill's, else find-or-create by phone).
$customer_id = $old['customer_id'] ?: findOrCreateCustomer($pdo, $ex_branch, (string)$old['customer_name'], (string)$old['customer_phone'], null, (int)$_SESSION['user_id']);

try {
    $pdo->beginTransaction();

    // 4. Record the exchange.
    $stmt = $pdo->prepare("INSERT INTO exchanges
        (branch_id, customer_id, original_bill_id, original_bill_item_id, old_product_id, old_product_name, old_size, old_mrp, old_discount_percent, return_value,
         new_product_id, new_product_name, new_size, new_mrp, new_discount_percent, new_final_price, difference_paid, payment_mode, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $ex_branch, $customer_id, (int)$old['bill_id'], $bill_item_id, $old['product_id'], $old['product_name'], $old['old_size'], (float)$old['selling_price'], $old_disc, $return_value,
        $new['id'], $new['name'], $new['size'], $new_mrp, $new_disc, $new_final, max(0, $difference), $payment_mode, $_SESSION['user_id']
    ]);
    $exchange_id = (int)$pdo->lastInsertId();

    // 5. Stock: returned product +1 (back to sellable), new product -1.
    if (!empty($old['product_id'])) {
        $pdo->prepare("UPDATE products SET quantity = quantity + 1 WHERE id = ?")->execute([$old['product_id']]);
        $pdo->prepare("INSERT INTO inventory_log (branch_id, product_id, type, quantity, reference_type, reference_id, note, created_by) VALUES (?, ?, 'in', 1, 'exchange', ?, ?, ?)")
            ->execute([$ex_branch, $old['product_id'], $exchange_id, 'Exchange return: ' . $old['product_name'], $_SESSION['user_id']]);
    }

    $upd = $pdo->prepare("UPDATE products SET quantity = quantity - 1 WHERE id = ? AND quantity >= 1");
    $upd->execute([$new['id']]);
    if ($upd->rowCount() === 0) throw new Exception('Replacement product went out of stock.');
    $pdo->prepare("INSERT INTO inventory_log (branch_id, product_id, type, quantity, reference_type, reference_id, note, created_by) VALUES (?, ?, 'out', 1, 'exchange', ?, ?, ?)")
        ->execute([$ex_branch, $new['id'], $exchange_id, 'Exchange issue: ' . $new['name'], $_SESSION['user_id']]);

    $pdo->commit();
    logAudit('exchange', 'exchange', "Exchange #$exchange_id — diff " . formatCurrency($difference));
    flashMessage('success', "Exchange recorded." . ($difference > 0 ? ' Difference collected: ' . formatCurrency($difference) . ' (' . ucfirst($payment_mode) . ').' : ' Even exchange — no payment.'));
} catch (Exception $e) {
    $pdo->rollBack();
    flashMessage('danger', 'Error: ' . $e->getMessage());
}

redirect('index.php');
