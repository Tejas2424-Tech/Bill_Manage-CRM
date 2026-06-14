<?php
/**
 * Delete Expense Record
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
        redirect('index.php');
    }

    $id = (int)($_POST['id'] ?? 0);
    $branch_id = $_SESSION['branch_id'];
    $isAdmin = isAdmin();

    if ($id > 0) {
        try {
            // Fetch category and amount for logging before deletion
            $stmt = $pdo->prepare("SELECT category, amount FROM expenses WHERE id = ?" . (!$isAdmin ? " AND branch_id = $branch_id" : ""));
            $stmt->execute([$id]);
            $expense = $stmt->fetch();

            if ($expense) {
                $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ?");
                $stmt->execute([$id]);
                logAudit('delete_expense', 'expenses', "Deleted expense: {$expense['category']} - " . formatCurrency($expense['amount']));
                flashMessage('success', 'Expense record deleted successfully.');
            } else {
                flashMessage('danger', 'Expense record not found or access denied.');
            }
        } catch (Exception $e) {
            flashMessage('danger', 'Error: ' . $e->getMessage());
        }
    }
}

redirect('index.php');

