<?php
/**
 * AJAX: Mark Notification as Read
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Set JSON header
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$all = (int)($_GET['all'] ?? 0);
$branch_id = $_SESSION['branch_id'];

try {
    if ($all === 1) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE branch_id = ? OR branch_id IS NULL");
        $stmt->execute([$branch_id]);
    } elseif ($id > 0) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (branch_id = ? OR branch_id IS NULL)");
        $stmt->execute([$id, $branch_id]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
