<?php
/**
 * Stock Out Handler
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
        redirect('index.php');
    }

    $product_id = (int)$_POST['product_id'];
    $quantity = (int)$_POST['quantity'];
    $note = sanitize($_POST['note'] ?? '');
    $branch_id = $_SESSION['branch_id'];

    // Get current quantity
    $stmt = $pdo->prepare("SELECT quantity, name FROM products WHERE id = ?" . (!isAdmin() ? " AND branch_id = " . (int)$branch_id : ""));
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    if (!$product) {
        flashMessage('danger', 'Product not found or access denied.');
        redirect('index.php');
    }

    if ($quantity <= 0) {
        flashMessage('danger', 'Invalid quantity.');
        redirect('index.php');
    }

    if ($quantity > $product['quantity']) {
        flashMessage('danger', "Insufficient stock for '{$product['name']}'. Available: {$product['quantity']}");
        redirect('index.php');
    }

    try {
        $pdo->beginTransaction();

        // 1. Update Product Quantity
        $stmt = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
        $stmt->execute([$quantity, $product_id]);

        // 2. Log Inventory Movement
        $stmt = $pdo->prepare("INSERT INTO inventory_log (branch_id, product_id, type, quantity, note, created_by) VALUES (?, ?, 'out', ?, ?, ?)");
        $stmt->execute([$branch_id, $product_id, $quantity, $note, $_SESSION['user_id']]);

        $pdo->commit();
        
        logAudit('stock_out', 'inventory', "Removed $quantity units from product ID: $product_id");
        flashMessage('success', "Stock removed successfully.");
    } catch (Exception $e) {
        $pdo->rollBack();
        flashMessage('danger', 'Error: ' . $e->getMessage());
    }
}

redirect('index.php');
?>
