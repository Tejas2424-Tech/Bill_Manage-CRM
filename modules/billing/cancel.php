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

    // 1. Fetch Bill to verify ownership and status
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

    // 2. Check cancellation permission
    $is_today = date('Y-m-d', strtotime($bill['created_at'])) === date('Y-m-d');
    if (!isAdmin() && !$is_today) {
        flashMessage('danger', 'You can only cancel bills created today. Contact administrator.');
        redirect('history.php');
    }

    try {
        $pdo->beginTransaction();

        // 3. Update Bill Status
        $stmt = $pdo->prepare("UPDATE bills SET status = 'cancelled' WHERE id = ?");
        $stmt->execute([$bill_id]);

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
                $stmt = $pdo->prepare("INSERT INTO inventory_log (branch_id, product_id, type, quantity, note, created_by) VALUES (?, ?, 'in', ?, ?, ?)");
                $stmt->execute([$branch_id, $item['product_id'], $item['quantity'], "Cancelled Bill: " . $bill['bill_number'], $_SESSION['user_id']]);
            }
        }

        $pdo->commit();
        logAudit('cancel_bill', 'billing', "Cancelled bill " . $bill['bill_number']);
        flashMessage('success', "Bill " . $bill['bill_number'] . " cancelled and stock reversed.");

    } catch (Exception $e) {
        $pdo->rollBack();
        flashMessage('danger', 'Error: ' . $e->getMessage());
    }
}

redirect('history.php');
?>
