<?php
/**
 * System Health & Diagnostic Dashboard - Superadmin Only
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireRole('superadmin');

$pageTitle = 'System Health';
$breadcrumb = '<a href="' . BASE_URL . '/modules/settings/index.php">Settings</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">System Health</span>';

// 1. Core System Checks
$checks = [];

// DB Connection
try {
    $pdo->query('SELECT 1');
    $checks['Database Connection'] = ['status' => true, 'msg' => 'Connected successfully'];
} catch (Exception $e) {
    $checks['Database Connection'] = ['status' => false, 'msg' => $e->getMessage()];
}

// PHP Version
$php_v = phpversion();
$checks['PHP Version (>= 7.4)'] = [
    'status' => version_compare($php_v, '7.4.0', '>='),
    'msg' => "Current: $php_v"
];

// PDO MySQL Extension
$checks['PDO MySQL Extension'] = [
    'status' => extension_loaded('pdo_mysql'),
    'msg' => extension_loaded('pdo_mysql') ? 'Loaded' : 'Missing'
];

// Upload Directory Writable
$upload_path = __DIR__ . '/../../assets/images';
$checks['Upload Directory Writable'] = [
    'status' => is_writable($upload_path),
    'msg' => is_writable($upload_path) ? 'Writable' : 'Permission Denied'
];

// Session Working
$checks['Session Working'] = [
    'status' => isset($_SESSION['user_id']),
    'msg' => isset($_SESSION['user_id']) ? 'Active' : 'Inactive'
];

// 2. Table Counts
$counts = [
    'Total Bills' => $pdo->query("SELECT COUNT(*) FROM bills")->fetchColumn(),
    'Total Products' => $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
    'Total Users' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'Audit Log Entries' => $pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn()
];

// Last Audit Entry
$last_audit = $pdo->query("SELECT created_at FROM audit_log ORDER BY created_at DESC LIMIT 1")->fetchColumn();

include_once __DIR__ . '/../../includes/header.php';
?>

<style>
    .health-row { display: flex; align-items: center; justify-content: space-between; padding: 16px 0; border-bottom: 1px solid var(--border); }
    .health-row:last-child { border-bottom: none; }
    .health-info { display: flex; align-items: center; gap: 12px; }
    .health-ok { color: var(--success); font-size: 18px; }
    .health-fail { color: var(--danger); font-size: 18px; }
    .health-label { font-weight: 500; font-size: 15px; }
    .health-msg { font-size: 12px; color: var(--on-surface-subtle); }
</style>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">System Requirements & Status</h3></div>
            <div class="p-4">
                <?php foreach ($checks as $label => $c): ?>
                    <div class="health-row">
                        <div class="health-info">
                            <i class="fas <?php echo $c['status'] ? 'fa-check-circle health-ok' : 'fa-times-circle health-fail'; ?>"></i>
                            <div>
                                <div class="health-label"><?php echo $label; ?></div>
                                <div class="health-msg"><?php echo $c['msg']; ?></div>
                            </div>
                        </div>
                        <div>
                            <span class="badge-pill badge-<?php echo $c['status'] ? 'success' : 'danger'; ?>">
                                <?php echo $c['status'] ? 'PASSED' : 'FAILED'; ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-md-5">
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">Database Overview</h3></div>
            <div class="p-4">
                <?php foreach ($counts as $label => $val): ?>
                    <div class="d-flex justify-between mb-3">
                        <span class="text-muted"><?php echo $label; ?></span>
                        <span class="fw-700"><?php echo number_format($val); ?></span>
                    </div>
                <?php endforeach; ?>
                
                <div class="mt-4 pt-4 border-top">
                    <div class="fs-11 text-muted uppercase mb-1">Last Audit Activity</div>
                    <div class="fw-600"><?php echo $last_audit ? formatDateTime($last_audit) : 'No logs yet'; ?></div>
                </div>
            </div>
        </div>

        <div class="card bg-primary-subtle border-primary">
            <div class="p-4 text-center">
                <i class="fas fa-server fa-3x mb-3 text-primary"></i>
                <h4 class="h5 mb-1">Environment</h4>
                <p class="text-muted fs-13"><?php echo php_uname('s') . ' ' . php_uname('r'); ?></p>
                <div class="fs-11 text-muted">Server Time: <?php echo date('Y-m-d H:i:s'); ?></div>
            </div>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
