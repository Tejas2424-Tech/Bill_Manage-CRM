<?php
/**
 * Branch Management - Superadmin Only
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireRole('superadmin');

$pageTitle = 'Branches';
$breadcrumb = '<a href="' . BASE_URL . '/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Branches</span>';

// Handle Status Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $id = (int)$_POST['id'];
        $new_status = $_POST['status'] === 'active' ? 'inactive' : 'active';
        $stmt = $pdo->prepare("UPDATE branches SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $id]);
        logAudit('toggle_branch', 'branches', "Changed branch ID $id status to $new_status");
        flashMessage('success', "Branch marked as $new_status.");
    }
    redirect('index.php');
}

// Fetch Branches with Stats
$query = "SELECT b.*, 
          (SELECT COALESCE(SUM(total_amount), 0) FROM bills WHERE branch_id = b.id AND DATE(created_at) = CURDATE() AND status != 'cancelled') as today_sales,
          (SELECT COUNT(*) FROM products WHERE branch_id = b.id AND status = 'active') as product_count,
          (SELECT COUNT(*) FROM users WHERE branch_id = b.id AND status = 'active') as staff_count
          FROM branches b 
          ORDER BY b.created_at ASC";
$branches = $pdo->query($query)->fetchAll();

$csrf_token = generateCSRFToken();

include_once __DIR__ . '/../../includes/header.php';
?>

<style>
    .branch-card { 
        background: var(--surface); 
        border: 1px solid var(--border); 
        border-radius: var(--radius-lg); 
        padding: 24px; 
        transition: border-color .15s, transform 0.15s; 
        display: flex;
        flex-direction: column;
        height: 100%;
    }
    .branch-card:hover { border-color: var(--primary); transform: translateY(-2px); }
    
    .branch-code { 
        font-size: 10px; 
        font-weight: 700; 
        letter-spacing: 1px; 
        padding: 4px 10px; 
        background: rgba(77,120,255,.12); 
        color: var(--primary); 
        border-radius: 4px; 
        display: inline-block; 
        margin-bottom: 12px;
        text-transform: uppercase;
    }
    
    .branch-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
        margin: 20px 0;
        padding: 16px 0;
        border-top: 1px solid var(--border);
        border-bottom: 1px solid var(--border);
    }
    .bs-item { text-align: center; }
    .bs-value { font-size: 15px; font-weight: 700; color: var(--on-surface); margin-bottom: 4px; }
    .bs-label { font-size: 10px; color: var(--on-surface-subtle); text-transform: uppercase; }
</style>

<div class="page-header">
    <h1>Multi-Branch Management</h1>
    <a href="add.php" class="btn btn-primary">
        <i class="fas fa-plus me-1"></i> Add New Branch
    </a>
</div>

<div class="grid-3">
    <?php foreach ($branches as $b): ?>
        <div class="branch-card">
            <div class="d-flex justify-between align-start">
                <div class="branch-code"><?php echo sanitize($b['code']); ?></div>
                <span class="badge-pill badge-<?php echo $b['status'] === 'active' ? 'success' : 'danger'; ?>">
                    <?php echo ucfirst($b['status']); ?>
                </span>
            </div>
            
            <h3 class="h5 fw-700 mb-1"><?php echo sanitize($b['name']); ?></h3>
            <div class="fs-12 text-muted mb-3"><i class="fas fa-user-tie me-1"></i> <?php echo sanitize($b['manager_name'] ?: 'No Manager'); ?></div>
            <div class="fs-12 text-muted"><i class="fas fa-phone me-1"></i> <?php echo sanitize($b['phone'] ?: 'N/A'); ?></div>
            
            <div class="branch-stats">
                <div class="bs-item">
                    <div class="bs-value"><?php echo formatCurrency($b['today_sales']); ?></div>
                    <div class="bs-label">Today</div>
                </div>
                <div class="bs-item">
                    <div class="bs-value"><?php echo $b['product_count']; ?></div>
                    <div class="bs-label">Items</div>
                </div>
                <div class="bs-item">
                    <div class="bs-value"><?php echo $b['staff_count']; ?></div>
                    <div class="bs-label">Staff</div>
                </div>
            </div>
            
            <div class="d-flex gap-2 mt-auto pt-2">
                <a href="edit.php?id=<?php echo $b['id']; ?>" class="btn btn-outline btn-sm flex-grow-1">Edit</a>
                <a href="performance.php?id=<?php echo $b['id']; ?>" class="btn btn-ghost btn-icon btn-sm" title="View Performance">
                    <i class="fas fa-chart-bar"></i>
                </a>
                <form action="" method="POST" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="id" value="<?php echo $b['id']; ?>">
                    <input type="hidden" name="status" value="<?php echo $b['status']; ?>">
                    <input type="hidden" name="action" value="toggle">
                    <button type="submit" class="btn btn-ghost btn-icon btn-sm text-<?php echo $b['status'] === 'active' ? 'danger' : 'success'; ?>" 
                            title="<?php echo $b['status'] === 'active' ? 'Disable' : 'Enable'; ?>">
                        <i class="fas fa-power-off"></i>
                    </button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
