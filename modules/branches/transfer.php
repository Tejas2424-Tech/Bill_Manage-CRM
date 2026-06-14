<?php
/**
 * Inter-Branch Stock Transfer
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = 'Stock Transfers';
$breadcrumb = '<a href="' . BASE_URL . '/modules/branches/index.php">Branches</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Stock Transfer</span>';

$tab = $_GET['tab'] ?? 'history';
$current_branch_id = $_SESSION['branch_id'];
$isAdmin = isAdmin();
$isStaff = $_SESSION['role'] === 'staff';

// Handle Create Transfer POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
    } else {
        $from_branch = (int)($_POST['from_branch_id'] ?? 0);
        $to_branch = (int)($_POST['to_branch_id'] ?? 0);
        $product_id = (int)($_POST['product_id'] ?? 0);
        $qty = (float)($_POST['quantity'] ?? 0);
        $note = sanitize($_POST['note'] ?? '');

        // Basic validation
        if ($from_branch === $to_branch) {
            flashMessage('danger', 'Source and destination branches must be different.');
        } elseif ($qty <= 0) {
            flashMessage('danger', 'Quantity must be greater than zero.');
        } elseif (!$product_id) {
            flashMessage('danger', 'Please select a product.');
        } else {
            // Check availability at from_branch
            $stmt = $pdo->prepare("SELECT quantity, name FROM products WHERE id = ? AND branch_id = ?");
            $stmt->execute([$product_id, $from_branch]);
            $prod = $stmt->fetch();

            if (!$prod || $prod['quantity'] < $qty) {
                flashMessage('danger', 'Insufficient stock in source branch.');
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO stock_transfers (from_branch_id, to_branch_id, product_id, quantity, note, requested_by, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                    $stmt->execute([$from_branch, $to_branch, $product_id, $qty, $note, $_SESSION['user_id']]);
                    $transfer_id = $pdo->lastInsertId();

                    // Notify destination branch admin
                    createNotification($to_branch, 'transfer', "New Stock Transfer Request", "Transfer request for {$qty} units of {$prod['name']} from " . getBranchName($from_branch));

                    logAudit('create_transfer', 'stock_transfers', "Created transfer request #$transfer_id for {$prod['name']}");
                    flashMessage('success', 'Stock transfer request created successfully.');
                    redirect('transfer.php?tab=history');
                } catch (Exception $e) {
                    flashMessage('danger', 'Error: ' . $e->getMessage());
                }
            }
        }
    }
}

// Fetch data for History
$history_filter = "";
if (!$isAdmin) {
    $history_filter = " AND (t.from_branch_id = $current_branch_id OR t.to_branch_id = $current_branch_id)";
}

$query_history = "SELECT t.*, 
                  fb.name as from_branch_name, tb.name as to_branch_name, 
                  p.name as product_name, u.name as requester_name 
                  FROM stock_transfers t 
                  JOIN branches fb ON t.from_branch_id = fb.id 
                  JOIN branches tb ON t.to_branch_id = tb.id 
                  JOIN products p ON t.product_id = p.id 
                  JOIN users u ON t.requested_by = u.id 
                  WHERE 1=1 $history_filter 
                  ORDER BY t.created_at DESC";
$history = $pdo->query($query_history)->fetchAll();

// Fetch branches for dropdown
$branches = $pdo->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name ASC")->fetchAll();

// Fetch products for current branch (default)
$source_branch_id = $isAdmin ? (int)($_GET['from_branch'] ?? ($branches[0]['id'] ?? 0)) : $current_branch_id;
$products = $pdo->prepare("SELECT id, name, quantity FROM products WHERE branch_id = ? AND status = 'active' ORDER BY name ASC");
$products->execute([$source_branch_id]);
$products_list = $products->fetchAll();

$csrf_token = generateCSRFToken();

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1><?php echo $pageTitle; ?></h1>
</div>

<div class="table-wrapper">
    <div class="table-toolbar">
        <div class="d-flex bg-secondary p-1 rounded">
            <a href="?tab=history" class="btn btn-sm <?php echo $tab === 'history' ? 'btn-primary' : 'btn-ghost'; ?>">Transfer History</a>
            <?php if (!$isStaff): ?>
                <a href="?tab=create" class="btn btn-sm <?php echo $tab === 'create' ? 'btn-primary' : 'btn-ghost'; ?>">Create Transfer</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($tab === 'create' && !$isStaff): ?>
        <div class="p-4">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <form action="" method="POST" class="form-card">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="create">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">From Branch <span class="req">*</span></label>
                                <?php if ($isAdmin): ?>
                                    <select name="from_branch_id" id="from_branch_id" class="form-control" onchange="updateProductList(this.value)">
                                        <?php foreach ($branches as $b): ?>
                                            <option value="<?php echo $b['id']; ?>"><?php echo $b['name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="hidden" name="from_branch_id" value="<?php echo $current_branch_id; ?>">
                                    <input type="text" class="form-control" value="<?php echo getBranchName($current_branch_id); ?>" readonly disabled>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">To Branch <span class="req">*</span></label>
                                <select name="to_branch_id" class="form-control" required>
                                    <option value="">Select Destination</option>
                                    <?php foreach ($branches as $b): ?>
                                        <?php if ($isAdmin || $b['id'] != $current_branch_id): ?>
                                            <option value="<?php echo $b['id']; ?>"><?php echo $b['name']; ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Product <span class="req">*</span></label>
                            <select name="product_id" id="product_id" class="form-control" required onchange="checkAvailability(this.value)">
                                <option value="">Search Product...</option>
                                <?php foreach ($products_list as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo $p['name']; ?> (Stock: <?php echo $p['quantity']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div id="availability_info" class="fs-12 mt-1 text-accent"></div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Quantity to Transfer <span class="req">*</span></label>
                                <input type="number" step="0.01" name="quantity" class="form-control" required min="0.01">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Note</label>
                                <input type="text" name="note" class="form-control" placeholder="Optional internal note">
                            </div>
                        </div>

                        <div class="d-flex justify-end gap-2 mt-4">
                            <button type="reset" class="btn btn-outline">Reset</button>
                            <button type="submit" class="btn btn-primary">Initiate Transfer</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <?php else: // History Tab ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>From / To</th>
                    <th>Product</th>
                    <th>Qty</th>
                    <th>Status</th>
                    <th>Requested By</th>
                    <th>Date</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $t): ?>
                    <tr>
                        <td class="fs-12">#<?php echo $t['id']; ?></td>
                        <td>
                            <div class="fw-600"><?php echo sanitize($t['from_branch_name']); ?></div>
                            <div class="fs-10 text-muted">to <?php echo sanitize($t['to_branch_name']); ?></div>
                        </td>
                        <td>
                            <div class="fw-600"><?php echo sanitize($t['product_name']); ?></div>
                            <?php if ($t['note']): ?>
                                <div class="fs-10 text-muted italic"><?php echo sanitize($t['note']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (float)$t['quantity']; ?></td>
                        <td>
                            <?php 
                                $status_classes = [
                                    'pending' => 'warning',
                                    'approved' => 'info',
                                    'dispatched' => 'purple',
                                    'received' => 'success',
                                    'cancelled' => 'danger'
                                ];
                                $cls = $status_classes[$t['status']] ?? 'secondary';
                            ?>
                            <span class="badge-pill badge-<?php echo $cls; ?>"><?php echo ucfirst($t['status']); ?></span>
                        </td>
                        <td>
                            <div class="fs-11"><?php echo sanitize($t['requester_name']); ?></div>
                        </td>
                        <td class="fs-11 text-muted"><?php echo formatDate($t['created_at']); ?></td>
                        <td class="text-end">
                            <div class="d-flex justify-end gap-1">
                                <?php if ($t['status'] === 'pending'): ?>
                                    <?php if ($t['requested_by'] == $_SESSION['user_id'] || $isAdmin): ?>
                                        <form action="transfer_action.php?action=cancel" method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                                            <button type="submit" class="btn btn-ghost btn-icon btn-sm text-danger" title="Cancel Request"><i class="fas fa-times"></i></button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <?php if (($t['to_branch_id'] == $current_branch_id && !$isStaff) || $isAdmin): ?>
                                        <form action="transfer_action.php?action=approve" method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                                            <button type="submit" class="btn btn-ghost btn-icon btn-sm text-success" title="Approve"><i class="fas fa-check"></i></button>
                                        </form>
                                        <form action="transfer_action.php?action=reject" method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                                            <button type="submit" class="btn btn-ghost btn-icon btn-sm text-danger" title="Reject"><i class="fas fa-ban"></i></button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($t['status'] === 'approved'): ?>
                                    <?php if (($t['from_branch_id'] == $current_branch_id && !$isStaff) || $isAdmin): ?>
                                        <form action="transfer_action.php?action=dispatch" method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                                            <button type="submit" class="btn btn-ghost btn-sm text-accent" title="Mark Dispatched"><i class="fas fa-truck-fast me-1"></i> Dispatch</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($t['status'] === 'dispatched'): ?>
                                    <?php if (($t['to_branch_id'] == $current_branch_id && !$isStaff) || $isAdmin): ?>
                                        <form action="transfer_action.php?action=receive" method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                                            <button type="submit" class="btn btn-ghost btn-sm text-success" title="Mark Received"><i class="fas fa-box-open me-1"></i> Receive</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($history)): ?>
                    <tr><td colspan="8" class="text-center py-5 text-muted">No transfer history found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
function checkAvailability(productId) {
    if (!productId) {
        document.getElementById('availability_info').innerText = '';
        return;
    }
    const branchId = <?php echo $isAdmin ? "document.getElementById('from_branch_id').value" : $current_branch_id; ?>;
    
    fetch(`get_product_qty.php?product_id=${productId}&branch_id=${branchId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('availability_info').innerText = `Available stock: ${data.quantity}`;
            } else {
                document.getElementById('availability_info').innerText = data.message || 'Error fetching quantity';
            }
        });
}

function updateProductList(branchId) {
    // In a real app, this would fetch products via AJAX. 
    // For this task, we'll reload the page with a branch_id param or just assume current list is fine if same branch.
    // However, to be thorough:
    location.href = `transfer.php?tab=create&from_branch=${branchId}`;
}

<?php if ($isAdmin && isset($_GET['from_branch'])): ?>
document.getElementById('from_branch_id').value = <?php echo (int)$_GET['from_branch']; ?>;
<?php endif; ?>
</script>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
