<?php
/**
 * User Management Index
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['superadmin', 'branch_admin']);

$pageTitle = 'User Management';
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Users</span>';

// Handle Actions (Toggle Status / Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
        redirect(BASE_URL . '/modules/users/index.php');
    }

    $target_id = (int)$_POST['user_id'];
    
    // Prevent self-action
    if ($target_id === (int)$_SESSION['user_id']) {
        flashMessage('danger', 'You cannot perform this action on your own account.');
        redirect(BASE_URL . '/modules/users/index.php');
    }

    if ($_POST['action'] === 'toggle_status') {
        $stmt = $pdo->prepare("UPDATE users SET status = IF(status='active', 'inactive', 'active') WHERE id = ?");
        $stmt->execute([$target_id]);
        logAudit('toggle_status', 'users', "Toggled status for user ID: $target_id");
        flashMessage('success', 'User status updated successfully.');
    } elseif ($_POST['action'] === 'delete' && isAdmin()) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$target_id]);
        logAudit('delete', 'users', "Deleted user ID: $target_id");
        flashMessage('success', 'User deleted successfully.');
    }
    
    redirect(BASE_URL . '/modules/users/index.php');
}

// Fetch Users
$query = "SELECT u.*, b.name as branch_name 
          FROM users u 
          LEFT JOIN branches b ON u.branch_id = b.id";

if (!isAdmin()) {
    $query .= " WHERE u.branch_id = ?";
}
$query .= " ORDER BY u.created_at DESC";

$stmt = $pdo->prepare($query);
if (!isAdmin()) $stmt->execute([$_SESSION['branch_id']]); else $stmt->execute();
$users = $stmt->fetchAll();
$csrf_token = generateCSRFToken();

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>User Management</h1>
    <div class="d-flex gap-2">
        <div class="input-group" style="width: 250px;">
            <input type="text" id="userSearch" class="form-control" placeholder="Search users..." onkeyup="filterTable('userSearch', 'userTable')">
        </div>
        <?php if (isBranchAdmin()): ?>
            <a href="add.php" class="btn btn-primary">
                <i class="fas fa-user-plus"></i> Add User
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="table-wrapper">
    <table class="data-table" id="userTable">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Branch</th>
                <th>Status</th>
                <th>Created</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td>
                        <div class="fw-600"><?php echo sanitize($u['name']); ?></div>
                        <?php if ($u['id'] === $_SESSION['user_id']): ?>
                            <span class="badge-pill badge-info fs-10">You</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo sanitize($u['email']); ?></td>
                    <td>
                        <?php 
                        $role_class = [
                            'superadmin' => 'badge-purple',
                            'branch_admin' => 'badge-primary',
                            'staff' => 'badge-success',
                            'cashier' => 'badge-warning'
                        ][$u['role']] ?? 'badge-secondary';
                        ?>
                        <span class="badge-pill <?php echo $role_class; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $u['role'])); ?>
                        </span>
                    </td>
                    <td>
                        <span class="text-secondary"><?php echo $u['branch_name'] ?: 'Global'; ?></span>
                    </td>
                    <td>
                        <span class="badge-pill <?php echo $u['status'] === 'active' ? 'badge-success' : 'badge-danger'; ?>">
                            <?php echo ucfirst($u['status']); ?>
                        </span>
                    </td>
                    <td class="fs-12 text-muted"><?php echo formatDate($u['created_at']); ?></td>
                    <td class="text-end">
                        <div class="d-flex justify-end gap-2">
                            <a href="edit.php?id=<?php echo $u['id']; ?>" class="btn btn-ghost btn-icon btn-sm" title="Edit User">
                                <i class="fas fa-edit"></i>
                            </a>
                            
                            <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                                <form action="" method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <button type="submit" class="btn btn-ghost btn-icon btn-sm text-warning" title="Toggle Status">
                                        <i class="fas fa-power-off"></i>
                                    </button>
                                </form>

                                <?php if (isAdmin()): ?>
                                    <form action="" method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="btn btn-ghost btn-icon btn-sm text-danger" 
                                                data-confirm="Are you sure you want to permanently delete this user?" title="Delete User">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
