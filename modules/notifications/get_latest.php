<?php
/**
 * AJAX: Get Latest 5 Notifications
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode([]);
    exit;
}

$branch_id = $_SESSION['branch_id'];

try {
    $stmt = $pdo->prepare("SELECT * FROM notifications 
                         WHERE (branch_id = ? OR branch_id IS NULL) 
                         AND is_read = 0 
                         ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$branch_id]);
    $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add time_ago string
    foreach ($notifs as &$n) {
        $n['time_ago'] = time_elapsed_string($n['created_at']);
    }

    echo json_encode($notifs);

} catch (Exception $e) {
    echo json_encode([]);
}
?>
