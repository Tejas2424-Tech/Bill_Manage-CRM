<?php
/**
 * Stock In Handler
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

    if ($quantity <= 0) {
        flashMessage('danger', 'Invalid quantity.');
        redirect('index.php');
    }

    // Resolve product + its branch (non-admins restricted to their own branch).
    // Superadmin has a NULL session branch, so always take the branch from the product.
    $stmt = $pdo->prepare("SELECT branch_id FROM products WHERE id = ?" . (!isAdmin() ? " AND branch_id = " . (int)$branch_id : ""));
    $stmt->execute([$product_id]);
    $prod = $stmt->fetch();
    if (!$prod) {
        flashMessage('danger', 'Product not found or access denied.');
        redirect('index.php');
    }
    $branch_id = (int)$prod['branch_id'];
    if (!$branch_id) {
        flashMessage('danger', 'Unable to determine branch for this product.');
        redirect('index.php');
    }

    try {
        $pdo->beginTransaction();

        // 1. Update Product Quantity
        $stmt = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?" . (!isAdmin() ? " AND branch_id = " . (int)$branch_id : ""));
        $stmt->execute([$quantity, $product_id]);

        if ($stmt->rowCount() === 0) {
            throw new Exception("Product not found or access denied.");
        }

        // 2. Log Inventory Movement
        $stmt = $pdo->prepare("INSERT INTO inventory_log (branch_id, product_id, type, quantity, note, created_by) VALUES (?, ?, 'in', ?, ?, ?)");
        $stmt->execute([$branch_id, $product_id, $quantity, $note, $_SESSION['user_id']]);

        $pdo->commit();
        
        logAudit('stock_in', 'inventory', "Added $quantity units to product ID: $product_id");
        flashMessage('success', "Stock added successfully.");
    } catch (Exception $e) {
        $pdo->rollBack();
        flashMessage('danger', 'Error: ' . $e->getMessage());
    }
}

// Only allow known relative filenames to prevent open redirect
$allowed_redirects = ['index.php', 'alerts.php', 'history.php'];
$redirect_to = $_POST['redirect_to'] ?? 'index.php';
if (!in_array(basename($redirect_to), $allowed_redirects)) {
    $redirect_to = 'index.php';
}
redirect($redirect_to);
?>
