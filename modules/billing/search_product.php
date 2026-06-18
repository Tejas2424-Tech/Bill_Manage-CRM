<?php
/**
 * AJAX Product Search for POS
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

header('Content-Type: application/json');

$branch_id = $_SESSION['branch_id'];
$q       = sanitize($_GET['q'] ?? '');
$barcode = sanitize($_GET['barcode'] ?? '');

if (empty($q) && empty($barcode)) {
    echo json_encode([]);
    exit;
}

// Branch scope: non-admins are always locked to their own branch. Superadmin sees
// all branches, UNLESS an explicit ?branch_id is passed (e.g. Exchange pins the
// replacement search to the original bill's branch).
$req_branch = (int)($_GET['branch_id'] ?? 0);
if (!isAdmin()) {
    $branch_filter        = " AND p.branch_id = ?";
    $branch_filter_params = [(int)$_SESSION['branch_id']];
} elseif ($req_branch > 0) {
    $branch_filter        = " AND p.branch_id = ?";
    $branch_filter_params = [$req_branch];
} else {
    $branch_filter        = "";
    $branch_filter_params = [];
}

if (!empty($barcode)) {
    // Barcode — find product regardless of stock level (stock check happens at bill creation)
    $query = "SELECT p.id, p.name, p.size, p.sku, p.barcode, p.selling_price, p.quantity, p.unit, c.name AS category
              FROM products p LEFT JOIN categories c ON p.category_id = c.id
              WHERE p.status = 'active' $branch_filter AND p.barcode = ? LIMIT 1";
    $stmt  = $pdo->prepare($query);
    $stmt->execute(array_merge($branch_filter_params, [$barcode]));
    echo json_encode($stmt->fetch() ?: null);
} else {
    // Text search — only show products with stock > 0
    $query = "SELECT p.id, p.name, p.size, p.sku, p.barcode, p.selling_price, p.quantity, p.unit, c.name AS category
              FROM products p LEFT JOIN categories c ON p.category_id = c.id
              WHERE p.status = 'active' $branch_filter AND p.quantity > 0
              AND (p.name LIKE ? OR p.sku LIKE ?) LIMIT 8";
    $stmt  = $pdo->prepare($query);
    $stmt->execute(array_merge($branch_filter_params, ["%$q%", "%$q%"]));
    echo json_encode($stmt->fetchAll());
}
exit;
?>
