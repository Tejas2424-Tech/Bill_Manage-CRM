<?php
/**
 * Audit Log Viewer - Superadmin Only
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireRole('superadmin');

$pageTitle = 'Audit Log';
$breadcrumb = '<a href="' . BASE_URL . '/modules/settings/index.php">Settings</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Audit Log</span>';

// Handle Clear Old Logs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_old') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $stmt = $pdo->prepare("DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        $stmt->execute();
        $count = $stmt->rowCount();
        logAudit('clear_audit_log', 'settings', "Cleared $count audit log entries older than 90 days");
        flashMessage('success', "Cleared $count old audit log entries.");
    }
    redirect('audit_log.php');
}

// Filters
$user_id   = $_GET['user_id']   ?? 'all';
$module    = $_GET['module']    ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';
$search    = sanitize($_GET['search'] ?? '');

$where  = ["1=1"];
$params = [];

if ($user_id !== 'all') {
    $where[]  = "al.user_id = ?";
    $params[] = $user_id;
}
if ($module !== 'all') {
    $where[]  = "al.module = ?";
    $params[] = $module;
}
if ($date_from) {
    $where[]  = "al.created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
}
if ($date_to) {
    $where[]  = "al.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
}
if ($search) {
    $where[]  = "al.description LIKE ?";
    $params[] = "%$search%";
}

$where_clause = implode(" AND ", $where);

// Handle Export CSV
if (isset($_GET['export']) && $_GET['export'] == '1') {
    $export_query = "SELECT al.created_at, u.name as user_name, u.role, al.module, al.action, al.description, al.ip_address
                     FROM audit_log al
                     LEFT JOIN users u ON al.user_id = u.id
                     WHERE $where_clause ORDER BY al.created_at DESC";
    $stmt = $pdo->prepare($export_query);
    $stmt->execute($params);
    $logs_to_export = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $headers = ['Timestamp', 'User', 'Role', 'Module', 'Action', 'Description', 'IP Address'];
    exportCSV('audit_log_export_' . date('Y-m-d') . '.csv', $headers, $logs_to_export);
    exit;
}

// Pagination
$per_page = 50;
$page     = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $per_page;

// Count total
$count_query = "SELECT COUNT(*) FROM audit_log al WHERE $where_clause";
$stmt = $pdo->prepare($count_query);
$stmt->execute($params);
$total_logs  = $stmt->fetchColumn();
$total_pages = ceil($total_logs / $per_page);

// Fetch logs
$query = "SELECT al.*, u.name as user_name, u.role as user_role
          FROM audit_log al
          LEFT JOIN users u ON al.user_id = u.id
          WHERE $where_clause
          ORDER BY al.created_at DESC
          LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Modules for filter
$modules = ['auth', 'billing', 'products', 'inventory', 'purchase', 'users', 'branches', 'settings', 'expenses', 'customers'];

// Module → badge color (platform CSS vars only)
$module_colors = [
    'billing'   => 'secondary',
    'products'  => 'success',
    'inventory' => 'warning',
    'auth'      => '',           // inline-styled (muted)
    'users'     => 'info',
    'branches'  => 'info',
    'settings'  => '',           // inline-styled (muted)
    'expenses'  => 'danger',
    'purchase'  => 'success',
    'customers' => 'warning',
];

// Role → badge color
$role_badge = [
    'superadmin'   => 'badge-danger',
    'branch_admin' => 'badge-warning',
    'staff'        => 'badge-success',
];

// All users for filter dropdown
$all_users = $pdo->query("SELECT id, name FROM users ORDER BY name ASC")->fetchAll();

$csrf_token = generateCSRFToken();

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Audit Log</h1>
        <div class="sub">Complete record of every action taken in the system</div>
    </div>
    <div class="page-header-actions">
        <form method="POST" onsubmit="return confirm('Delete all audit log entries older than 90 days?');" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="clear_old">
            <button type="submit" class="btn btn-outline btn-sm"
                    style="color:var(--danger);border-color:var(--danger);">
                <i class="fas fa-trash-alt"></i> Clear Old Logs (&gt;90d)
            </button>
        </form>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 1])); ?>"
           class="btn btn-outline btn-sm">
            <i class="fas fa-download"></i> Export CSV
        </a>
    </div>
</div>

<!-- Filters -->
<div class="table-wrapper" style="padding:16px 20px;margin-bottom:20px;">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label class="fs-11 text-muted" style="display:block;margin-bottom:4px;">User</label>
            <select name="user_id" class="form-control" style="min-width:160px;">
                <option value="all">All Users</option>
                <?php foreach ($all_users as $u): ?>
                    <option value="<?php echo $u['id']; ?>" <?php echo $user_id == $u['id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($u['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="fs-11 text-muted" style="display:block;margin-bottom:4px;">Module</label>
            <select name="module" class="form-control" style="min-width:140px;">
                <option value="all">All Modules</option>
                <?php foreach ($modules as $m): ?>
                    <option value="<?php echo $m; ?>" <?php echo $module === $m ? 'selected' : ''; ?>>
                        <?php echo ucfirst($m); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="fs-11 text-muted" style="display:block;margin-bottom:4px;">Date From</label>
            <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
        </div>
        <div>
            <label class="fs-11 text-muted" style="display:block;margin-bottom:4px;">Date To</label>
            <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
        </div>
        <div style="flex:1;min-width:180px;">
            <label class="fs-11 text-muted" style="display:block;margin-bottom:4px;">Search Description</label>
            <input type="text" name="search" class="form-control" value="<?php echo $search; ?>" placeholder="Keywords…">
        </div>
        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-outline"><i class="fas fa-filter"></i> Apply</button>
            <a href="audit_log.php" class="btn btn-ghost btn-sm btn-icon" title="Reset filters">
                <i class="fas fa-rotate-left"></i>
            </a>
        </div>
    </form>
</div>

<!-- Log table -->
<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th style="white-space:nowrap;">Timestamp</th>
                <th>User</th>
                <th>Module</th>
                <th>Action</th>
                <th style="max-width:350px;">Description</th>
                <th>IP Address</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="6">
                    <div class="empty-state">
                        <i class="fas fa-shield-halved empty-icon"></i>
                        <h3>No audit logs found</h3>
                        <p>Try adjusting your filters or date range.</p>
                    </div>
                </td></tr>
            <?php else: ?>
                <?php foreach ($logs as $l):
                    $color = $module_colors[$l['module']] ?? '';
                    $rb    = $role_badge[$l['user_role'] ?? ''] ?? 'badge-success';
                ?>
                <tr>
                    <td class="fs-12 text-muted" style="white-space:nowrap;"><?php echo formatDateTime($l['created_at']); ?></td>
                    <td>
                        <div class="fw-600 fs-13"><?php echo sanitize($l['user_name'] ?? 'System'); ?></div>
                        <span class="badge-pill <?php echo $rb; ?>" style="font-size:10px;">
                            <?php echo strtoupper($l['user_role'] ?? 'SYSTEM'); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($color): ?>
                            <span class="badge-pill badge-<?php echo $color; ?>"><?php echo strtoupper($l['module']); ?></span>
                        <?php else: ?>
                            <span class="badge-pill" style="background:var(--surface-variant);color:var(--on-surface-muted);border:1px solid var(--border);"><?php echo strtoupper($l['module']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="fw-600 fs-12" style="white-space:nowrap;"><?php echo strtoupper(sanitize($l['action'])); ?></td>
                    <td class="fs-13" style="max-width:350px;word-break:break-word;"><?php echo sanitize($l['description']); ?></td>
                    <td class="fs-12 text-muted" style="font-family:monospace;"><?php echo sanitize($l['ip_address']); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 0): ?>
    <div class="table-footer">
        <span>Showing <?php echo number_format(min($offset + 1, $total_logs)); ?>–<?php echo number_format(min($offset + $per_page, $total_logs)); ?> of <?php echo number_format($total_logs); ?> entries</span>
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>"
                   class="btn btn-outline btn-sm">Previous</a>
            <?php endif; ?>

            <?php
            $start = max(1, $page - 2);
            $end   = min($total_pages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                   class="btn btn-sm <?php echo $page == $i ? 'btn-primary' : 'btn-outline'; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>"
                   class="btn btn-outline btn-sm">Next</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
