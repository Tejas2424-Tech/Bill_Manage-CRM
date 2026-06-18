<?php
/**
 * Bill Cancellation Handler
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
        redirect('history.php');
    }

    $bill_id = (int)$_POST['bill_id'];
    $branch_id = $_SESSION['branch_id'];

    // 1. Only an admin (branch_admin or superadmin) may cancel a bill (spec rule).
    if (!isBranchAdmin()) {
        flashMessage('danger', 'Only an administrator can cancel a bill.');
        redirect('history.php');
    }

    // 2. A cancellation reason is mandatory.
    $cancel_reason = sanitize($_POST['cancel_reason'] ?? '');
    if (trim($cancel_reason) === '') {
        flashMessage('danger', 'A cancellation reason is required.');
        redirect('history.php');
    }

    // 3. Fetch Bill to verify ownership and status
    $stmt = $pdo->prepare("SELECT * FROM bills WHERE id = ?" . (!isAdmin() ? " AND branch_id = " . (int)$branch_id : ""));
    $stmt->execute([$bill_id]);
    $bill = $stmt->fetch();

    if (!$bill) {
        flashMessage('danger', 'Bill not found or access denied.');
        redirect('history.php');
    }

    if ($bill['status'] === 'cancelled') {
        flashMessage('warning', 'Bill is already cancelled.');
        redirect('history.php');
    }

    // Reverse stock against the bill's own branch (superadmin's session branch is NULL).
    $bill_branch_id = (int)$bill['branch_id'];

    try {
        $pdo->beginTransaction();

        // 4. Update Bill Status + cancellation audit
        $stmt = $pdo->prepare("UPDATE bills SET status = 'cancelled', cancel_reason = ?, cancelled_by = ?, cancelled_at = NOW() WHERE id = ?");
        $stmt->execute([$cancel_reason, $_SESSION['user_id'], $bill_id]);

        // 4. Reverse Stock for each item
        $stmt = $pdo->prepare("SELECT product_id, quantity FROM bill_items WHERE bill_id = ?");
        $stmt->execute([$bill_id]);
        $items = $stmt->fetchAll();

        foreach ($items as $item) {
            if ($item['product_id']) {
                // Update Product Quantity
                $stmt = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
                $stmt->execute([$item['quantity'], $item['product_id']]);

                // Log Inventory (Reverse)
                $stmt = $pdo->prepare("INSERT INTO inventory_log (branch_id, product_id, type, quantity, reference_type, reference_id, note, created_by) VALUES (?, ?, 'in', ?, 'bill', ?, ?, ?)");
                $stmt->execute([$bill_branch_id, $item['product_id'], $item['quantity'], $bill_id, "Cancelled Bill: " . $bill['bill_number'], $_SESSION['user_id']]);
            }
        }

        $pdo->commit();
        logAudit('cancel_bill', 'billing', "Cancelled bill " . $bill['bill_number'] . " — Reason: " . $cancel_reason);
        flashMessage('success', "Bill " . $bill['bill_number'] . " cancelled and stock reversed.");

    } catch (Exception $e) {
        $pdo->rollBack();
        flashMessage('danger', 'Error: ' . $e->getMessage());
    }
}

redirect('history.php');
?>
