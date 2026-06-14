<?php
/**
 * AJAX: Get Credit Customers for POS
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Set JSON header
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$q = sanitize($_GET['q'] ?? '');

if (empty($q)) {
    echo json_encode([]);
    exit;
}

try {
    // Build branch filter — superadmin searches all branches, staff see their own only
    $search_params = ["%$q%", "%$q%"];
    $branch_where  = "";
    if (!isAdmin()) {
        $branch_where = "cc.branch_id = ? AND ";
        array_unshift($search_params, $_SESSION['branch_id']);
    }

    $query = "SELECT cc.id, cc.name, cc.phone, cc.address, cc.reference_name, cc.reference_relation,
              COALESCE((SELECT SUM(total_amount - paid_amount)
                        FROM bills
                        WHERE customer_phone = cc.phone
                        AND bill_type = 'credit' AND status != 'cancelled'), 0) as outstanding
              FROM credit_customers cc
              WHERE {$branch_where}(cc.name LIKE ? OR cc.phone LIKE ?)
              LIMIT 10";

    $stmt = $pdo->prepare($query);
    $stmt->execute($search_params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($customers);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
