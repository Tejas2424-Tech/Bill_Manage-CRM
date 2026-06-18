<?php
/**
 * Draft Bills — held carts that haven't moved stock yet. Edit (reopens POS),
 * Delete, or Confirm (re-submits the stored cart to create.php to make a real
 * bill + move stock). Branch-scoped; cashier-accessible.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle  = 'Draft Bills';
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Draft Bills</span>';

$isAdmin   = isAdmin();
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$scope     = !$isAdmin ? " AND branch_id = " . $branch_id : "";

// Delete a draft
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
    } else {
        $stmt = $pdo->prepare("DELETE FROM draft_bills WHERE id = ?$scope");
        $stmt->execute([(int)$_POST['id']]);
        logAudit('delete_draft', 'billing', "Deleted draft #" . (int)$_POST['id']);
        flashMessage('success', 'Draft deleted.');
    }
    redirect('drafts.php');
}

$stmt = $pdo->prepare("SELECT d.*, u.name AS staff FROM draft_bills d LEFT JOIN users u ON d.created_by = u.id
                       WHERE 1=1 $scope ORDER BY d.updated_at DESC");
$stmt->execute();
$drafts = $stmt->fetchAll();

$csrf_token = generateCSRFToken();
include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Draft Bills</h1>
        <div class="sub"><?php echo count($drafts); ?> held draft<?php echo count($drafts) != 1 ? 's' : ''; ?> · stock not yet moved</div>
    </div>
    <div class="page-header-actions">
        <a href="create.php" class="btn btn-primary"><i class="fas fa-plus"></i> New Bill</a>
    </div>
</div>

<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Saved</th>
                <th>Customer</th>
                <th>Type</th>
                <th class="text-end">Items</th>
                <th class="text-end">Total</th>
                <th>By</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($drafts)): ?>
                <tr><td colspan="7"><div class="empty-state" style="padding:40px;text-align:center;">
                    <i class="fas fa-floppy-disk empty-icon" style="font-size:32px;color:var(--border);display:block;margin-bottom:10px;"></i>
                    <h3>No drafts</h3><p>Hold a bill from the POS with “Save Draft”.</p>
                </div></td></tr>
            <?php else: foreach ($drafts as $d): ?>
                <?php $items = json_decode($d['cart_json'], true) ?: []; $count = is_array($items) ? count($items) : 0; ?>
                <tr>
                    <td class="fs-12"><?php echo formatDateTime($d['updated_at']); ?></td>
                    <td>
                        <div class="fw-600"><?php echo $d['customer_name'] ? sanitize($d['customer_name']) : '<span class="text-muted">Walk-in</span>'; ?></div>
                        <?php if ($d['customer_phone']): ?><div class="fs-11 text-muted"><?php echo sanitize($d['customer_phone']); ?></div><?php endif; ?>
                    </td>
                    <td><span class="badge-pill badge-info"><?php echo ucfirst($d['bill_type']); ?></span></td>
                    <td class="text-end"><?php echo $count; ?></td>
                    <td class="text-end fw-600"><?php echo formatCurrency($d['total_amount']); ?></td>
                    <td class="fs-12"><?php echo sanitize($d['staff']); ?></td>
                    <td class="text-end">
                        <div class="d-flex justify-end gap-1">
                            <a href="create.php?draft=<?php echo $d['id']; ?>" class="btn btn-ghost btn-icon btn-sm" title="Edit"><i class="fas fa-edit"></i></a>

                            <!-- Confirm: re-submit the stored cart to create.php to make a real bill -->
                            <form action="create.php" method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="save_mode" value="confirm">
                                <input type="hidden" name="draft_id" value="<?php echo $d['id']; ?>">
                                <input type="hidden" name="cart_json" value="<?php echo htmlspecialchars($d['cart_json'], ENT_QUOTES); ?>">
                                <input type="hidden" name="customer_name" value="<?php echo htmlspecialchars((string)$d['customer_name'], ENT_QUOTES); ?>">
                                <input type="hidden" name="customer_phone" value="<?php echo htmlspecialchars((string)$d['customer_phone'], ENT_QUOTES); ?>">
                                <input type="hidden" name="customer_dob" value="<?php echo htmlspecialchars((string)$d['customer_dob'], ENT_QUOTES); ?>">
                                <input type="hidden" name="employee_name" value="<?php echo htmlspecialchars((string)$d['employee_name'], ENT_QUOTES); ?>">
                                <input type="hidden" name="bill_type" value="<?php echo $d['bill_type']; ?>">
                                <input type="hidden" name="alteration_required" value="<?php echo $d['alteration_required'] ? '1' : ''; ?>">
                                <input type="hidden" name="alteration_length" value="<?php echo htmlspecialchars((string)$d['alteration_length'], ENT_QUOTES); ?>">
                                <input type="hidden" name="alteration_charge" value="<?php echo $d['alteration_charge']; ?>">
                                <input type="hidden" name="subtotal" value="<?php echo $d['subtotal']; ?>">
                                <input type="hidden" name="discount_amount" value="<?php echo $d['discount_amount']; ?>">
                                <input type="hidden" name="discount_percent" value="<?php echo $d['discount_percent']; ?>">
                                <input type="hidden" name="gst_amount" value="<?php echo $d['gst_amount']; ?>">
                                <input type="hidden" name="gst_percent" value="<?php echo $d['gst_percent']; ?>">
                                <input type="hidden" name="total_amount" value="<?php echo $d['total_amount']; ?>">
                                <input type="hidden" name="received_amount" value="<?php echo $d['total_amount']; ?>">
                                <button type="submit" class="btn btn-ghost btn-icon btn-sm text-success" data-confirm="Confirm this draft into a bill? Stock will be deducted." title="Confirm to Bill"><i class="fas fa-circle-check"></i></button>
                            </form>

                            <form action="" method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                                <button type="submit" class="btn btn-ghost btn-icon btn-sm text-danger" data-confirm="Delete this draft?" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
