<?php
/**
 * Defective Stock Mover — shift units between Normal (sellable) and Defective
 * (non-sellable) buckets. Every move writes an inventory_log 'adjustment' row
 * (reference_type='defective') to keep the ledger in lockstep with quantities.
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
    $quantity   = (int)$_POST['quantity'];
    $direction  = $_POST['direction'] ?? 'to_defective';   // to_defective | to_normal
    $note       = sanitize($_POST['note'] ?? '');
    $branch_id  = $_SESSION['branch_id'];

    if ($quantity <= 0) {
        flashMessage('danger', 'Invalid quantity.');
        redirect('index.php');
    }
    if (!in_array($direction, ['to_defective', 'to_normal'], true)) {
        flashMessage('danger', 'Invalid move direction.');
        redirect('index.php');
    }

    // Resolve product + its branch (non-admins restricted to their own branch).
    $stmt = $pdo->prepare("SELECT branch_id, quantity, defective_quantity, name FROM products WHERE id = ?" . (!isAdmin() ? " AND branch_id = " . (int)$branch_id : ""));
    $stmt->execute([$product_id]);
    $prod = $stmt->fetch();
    if (!$prod) {
        flashMessage('danger', 'Product not found or access denied.');
        redirect('index.php');
    }
    $branch_id = (int)$prod['branch_id'];

    // Guard against moving more than the source bucket holds.
    if ($direction === 'to_defective' && $quantity > (int)$prod['quantity']) {
        flashMessage('danger', 'Cannot move more than available normal stock (' . (int)$prod['quantity'] . ').');
        redirect('index.php');
    }
    if ($direction === 'to_normal' && $quantity > (int)$prod['defective_quantity']) {
        flashMessage('danger', 'Cannot restore more than defective stock (' . (int)$prod['defective_quantity'] . ').');
        redirect('index.php');
    }

    try {
        $pdo->beginTransaction();

        if ($direction === 'to_defective') {
            $stmt = $pdo->prepare("UPDATE products SET quantity = quantity - ?, defective_quantity = defective_quantity + ? WHERE id = ?");
            $stmt->execute([$quantity, $quantity, $product_id]);
            $log_note = trim('Moved to defective. ' . $note);
        } else {
            $stmt = $pdo->prepare("UPDATE products SET defective_quantity = defective_quantity - ?, quantity = quantity + ? WHERE id = ?");
            $stmt->execute([$quantity, $quantity, $product_id]);
            $log_note = trim('Restored from defective to normal. ' . $note);
        }

        // Ledger row (the net sellable stock changed, so we log it).
        $stmt = $pdo->prepare("INSERT INTO inventory_log (branch_id, product_id, type, quantity, reference_type, note, created_by) VALUES (?, ?, 'adjustment', ?, 'defective', ?, ?)");
        $stmt->execute([$branch_id, $product_id, $quantity, $log_note, $_SESSION['user_id']]);

        $pdo->commit();
        logAudit('move_defective', 'inventory', "$direction $quantity units — product ID: $product_id");
        flashMessage('success', $direction === 'to_defective'
            ? "$quantity unit(s) moved to defective stock."
            : "$quantity unit(s) restored to normal stock.");
    } catch (Exception $e) {
        $pdo->rollBack();
        flashMessage('danger', 'Error: ' . $e->getMessage());
    }
}

redirect('index.php');
