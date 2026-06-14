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

// Superadmin has NULL branch_id — search all branches; others filter to their branch
$branch_filter        = (!isAdmin() || $branch_id) ? " AND branch_id = ?" : "";
$branch_filter_params = (!isAdmin() || $branch_id) ? [$branch_id] : [];

if (!empty($barcode)) {
    // Barcode — find product regardless of stock level (stock check happens at bill creation)
    $query = "SELECT id, name, sku, barcode, selling_price, quantity, unit
              FROM products WHERE status = 'active' $branch_filter AND barcode = ? LIMIT 1";
    $stmt  = $pdo->prepare($query);
    $stmt->execute(array_merge($branch_filter_params, [$barcode]));
    echo json_encode($stmt->fetch() ?: null);
} else {
    // Text search — only show products with stock > 0
    $query = "SELECT id, name, sku, barcode, selling_price, quantity, unit
              FROM products WHERE status = 'active' $branch_filter AND quantity > 0
              AND (name LIKE ? OR sku LIKE ?) LIMIT 8";
    $stmt  = $pdo->prepare($query);
    $stmt->execute(array_merge($branch_filter_params, ["%$q%", "%$q%"]));
    echo json_encode($stmt->fetchAll());
}
exit;
?>
