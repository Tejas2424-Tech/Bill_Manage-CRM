<?php
/**
 * POST handler for Stock Transfer Actions
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('transfer.php');
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    flashMessage('danger', 'Invalid security token.');
    redirect('transfer.php');
}

$action = $_GET['action'] ?? '';
$transfer_id = (int)($_POST['id'] ?? 0);
$current_user_id = $_SESSION['user_id'];
$current_branch_id = $_SESSION['branch_id'];
$isAdmin = isAdmin();

if (!$transfer_id) {
    flashMessage('danger', 'Missing transfer ID.');
    redirect('transfer.php');
}

// Load transfer details
$stmt = $pdo->prepare("SELECT t.*, p.name as product_name, p.sku, p.category_id, p.unit, p.purchase_price, p.selling_price, p.alert_quantity, p.dead_stock_days 
                     FROM stock_transfers t 
                     JOIN products p ON t.product_id = p.id 
                     WHERE t.id = ?");
$stmt->execute([$transfer_id]);
$t = $stmt->fetch();

if (!$t) {
    flashMessage('danger', 'Transfer record not found.');
    redirect('transfer.php');
}

try {
    switch ($action) {
        case 'approve':
            if ($t['status'] !== 'pending') throw new Exception("Transfer is not in pending state.");
            if ($t['to_branch_id'] != $current_branch_id && !$isAdmin) throw new Exception("Unauthorized.");
            
            $stmt = $pdo->prepare("UPDATE stock_transfers SET status = 'approved', approved_by = ? WHERE id = ?");
            $stmt->execute([$current_user_id, $transfer_id]);
            
            createNotification($t['from_branch_id'], 'transfer', "Transfer Approved", "Your transfer request #{$transfer_id} has been approved.");
            logAudit('approve_transfer', 'stock_transfers', "Approved transfer #$transfer_id");
            flashMessage('success', 'Transfer approved.');
            break;

        case 'reject':
            if ($t['status'] !== 'pending') throw new Exception("Transfer is not in pending state.");
            if ($t['to_branch_id'] != $current_branch_id && !$isAdmin) throw new Exception("Unauthorized.");
            
            $stmt = $pdo->prepare("UPDATE stock_transfers SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$transfer_id]);
            
            createNotification($t['from_branch_id'], 'transfer', "Transfer Rejected", "Your transfer request #{$transfer_id} was rejected by " . getBranchName($t['to_branch_id']));
            logAudit('reject_transfer', 'stock_transfers', "Rejected transfer #$transfer_id");
            flashMessage('warning', 'Transfer rejected.');
            break;

        case 'cancel':
            if ($t['status'] !== 'pending') throw new Exception("Only pending transfers can be cancelled.");
            if ($t['requested_by'] != $current_user_id && !$isAdmin) throw new Exception("Unauthorized.");
            
            $stmt = $pdo->prepare("UPDATE stock_transfers SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$transfer_id]);
            
            logAudit('cancel_transfer', 'stock_transfers', "Cancelled transfer #$transfer_id");
            flashMessage('info', 'Transfer request cancelled.');
            break;

        case 'dispatch':
            if ($t['status'] !== 'approved') throw new Exception("Transfer must be approved before dispatch.");
            if ($t['from_branch_id'] != $current_branch_id && !$isAdmin) throw new Exception("Unauthorized.");
            
            $stmt = $pdo->prepare("UPDATE stock_transfers SET status = 'dispatched' WHERE id = ?");
            $stmt->execute([$transfer_id]);
            
            createNotification($t['to_branch_id'], 'transfer', "Stock Dispatched", "Stock for transfer #{$transfer_id} has been dispatched from " . getBranchName($t['from_branch_id']));
            logAudit('dispatch_transfer', 'stock_transfers', "Dispatched transfer #$transfer_id");
            flashMessage('success', 'Transfer marked as dispatched.');
            break;

        case 'receive':
            if ($t['status'] !== 'dispatched') throw new Exception("Transfer must be dispatched before receiving.");
            if ($t['to_branch_id'] != $current_branch_id && !$isAdmin) throw new Exception("Unauthorized.");
            
            $pdo->beginTransaction();

            // 1. Update status
            $stmt = $pdo->prepare("UPDATE stock_transfers SET status = 'received' WHERE id = ?");
            $stmt->execute([$transfer_id]);

            // 2. Deduct from source branch
            $stmt = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ? AND branch_id = ?");
            $stmt->execute([$t['quantity'], $t['product_id'], $t['from_branch_id']]);
            
            // Log inventory (Out)
            $stmt = $pdo->prepare("INSERT INTO inventory_log (branch_id, product_id, type, quantity, reference_type, reference_id, created_by) VALUES (?, ?, 'out', ?, 'transfer', ?, ?)");
            $stmt->execute([$t['from_branch_id'], $t['product_id'], $t['quantity'], $transfer_id, $current_user_id]);

            // 3. Add to destination branch
            // Check if product with same SKU exists in destination branch
            $stmt = $pdo->prepare("SELECT id FROM products WHERE sku = ? AND branch_id = ?");
            $stmt->execute([$t['sku'], $t['to_branch_id']]);
            $target_prod = $stmt->fetch();

            if ($target_prod) {
                $target_prod_id = $target_prod['id'];
                $stmt = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
                $stmt->execute([$t['quantity'], $target_prod_id]);
            } else {
                // Create new product record in destination branch
                $stmt = $pdo->prepare("INSERT INTO products (branch_id, category_id, name, sku, unit, purchase_price, selling_price, quantity, alert_quantity, dead_stock_days, status) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                $stmt->execute([
                    $t['to_branch_id'], $t['category_id'], $t['product_name'], $t['sku'], 
                    $t['unit'], $t['purchase_price'], $t['selling_price'], $t['quantity'], 
                    $t['alert_quantity'], $t['dead_stock_days']
                ]);
                $target_prod_id = $pdo->lastInsertId();
            }

            // Log inventory (In)
            $stmt = $pdo->prepare("INSERT INTO inventory_log (branch_id, product_id, type, quantity, reference_type, reference_id, created_by) VALUES (?, ?, 'in', ?, 'transfer', ?, ?)");
            $stmt->execute([$t['to_branch_id'], $target_prod_id, $t['quantity'], $transfer_id, $current_user_id]);

            $pdo->commit();

            createNotification($t['from_branch_id'], 'transfer', "Transfer Completed", "Transfer #{$transfer_id} has been received by " . getBranchName($t['to_branch_id']));
            logAudit('receive_transfer', 'stock_transfers', "Received transfer #$transfer_id");
            flashMessage('success', 'Stock received and inventory updated.');
            break;

        default:
            throw new Exception("Invalid action.");
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flashMessage('danger', 'Error: ' . $e->getMessage());
}

redirect('transfer.php?tab=history');
