<?php
/**
 * Background Notification Generator
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Set JSON header
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$branch_id = $_SESSION['branch_id'];
$created_count = 0;

try {
    // Helper function to check for recent duplicate notification
    function shouldNotify($branch_id, $type, $title) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications 
                             WHERE branch_id = ? AND type = ? AND title = ? 
                             AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                             AND is_read = 0");
        $stmt->execute([$branch_id, $type, $title]);
        return $stmt->fetchColumn() == 0;
    }

    // 1. Low Stock Check
    $stmt = $pdo->prepare("SELECT name, quantity, alert_quantity FROM products 
                         WHERE branch_id = ? AND status = 'active' AND quantity <= alert_quantity");
    $stmt->execute([$branch_id]);
    $low_stock_items = $stmt->fetchAll();

    foreach ($low_stock_items as $item) {
        $title = "Low Stock: " . $item['name'];
        if (shouldNotify($branch_id, 'low_stock', $title)) {
            createNotification($branch_id, 'low_stock', $title, "{$item['name']} has only {$item['quantity']} units left (Alert at {$item['alert_quantity']}).");
            $created_count++;
        }
    }

    // 2. Dead Stock Check (90 days)
    $stmt = $pdo->prepare("SELECT id, name FROM products 
                         WHERE branch_id = ? AND status = 'active' 
                         AND (reviewed_at IS NULL OR reviewed_at < DATE_SUB(NOW(), INTERVAL 30 DAY))
                         AND id NOT IN (SELECT product_id FROM bill_items bi JOIN bills b ON bi.bill_id = b.id WHERE b.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY))");
    $stmt->execute([$branch_id]);
    $dead_stock_items = $stmt->fetchAll();

    foreach ($dead_stock_items as $item) {
        $title = "Dead Stock Alert: " . $item['name'];
        if (shouldNotify($branch_id, 'dead_stock', $title)) {
            createNotification($branch_id, 'dead_stock', $title, "{$item['name']} has not sold in the last 90 days. Consider a promotion or review.");
            $created_count++;
        }
    }

    // 3. Credit Overdue Check (30 days)
    $stmt = $pdo->prepare("SELECT cc.name, cc.phone, MAX(b.created_at) as last_bill 
                         FROM credit_customers cc 
                         JOIN bills b ON cc.phone = b.customer_phone 
                         WHERE cc.branch_id = ? AND b.bill_type = 'credit' AND b.status IN ('credit', 'partial')
                         GROUP BY cc.id 
                         HAVING last_bill < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute([$branch_id]);
    $overdue_customers = $stmt->fetchAll();

    foreach ($overdue_customers as $cust) {
        $title = "Payment Overdue: " . $cust['name'];
        if (shouldNotify($branch_id, 'credit_due', $title)) {
            createNotification($branch_id, 'credit_due', $title, "Customer {$cust['name']} ({$cust['phone']}) has outstanding dues for over 30 days.");
            $created_count++;
        }
    }

    echo json_encode(['success' => true, 'notifications_created' => $created_count]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
