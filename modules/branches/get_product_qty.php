<?php
/**
 * AJAX: Get Product Quantity for a specific Branch
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Set JSON header
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$product_id = (int)($_GET['product_id'] ?? 0);
$branch_id = (int)($_GET['branch_id'] ?? 0);

if (!$product_id || !$branch_id) {
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

try {
    // We search by product_id but we need to find the product with that ID in THAT branch
    // However, the requested flow implies we select a product (perhaps from a master list or current branch)
    // and check its stock in the source branch.
    // Assuming product_id is the primary key from products table.
    
    $stmt = $pdo->prepare("SELECT name, quantity FROM products WHERE id = ? AND branch_id = ?");
    $stmt->execute([$product_id, $branch_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($product) {
        echo json_encode([
            'success' => true,
            'quantity' => (float)$product['quantity'],
            'product_name' => $product['name']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'quantity' => 0,
            'message' => 'Product not found in this branch'
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
