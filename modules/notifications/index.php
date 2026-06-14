<?php
/**
 * Notifications Center
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = 'Notifications';
$breadcrumb = '<a href="' . BASE_URL . '/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Notifications</span>';

$branch_id = $_SESSION['branch_id'];
$filter = $_GET['filter'] ?? 'all';

// Handle Mark All Read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_all') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE branch_id = ? OR branch_id IS NULL");
        $stmt->execute([$branch_id]);
        flashMessage('success', 'All notifications marked as read.');
    }
    redirect('index.php');
}

// Build Query
$where = " (branch_id = ? OR branch_id IS NULL) ";
$params = [$branch_id];

if ($filter === 'unread') {
    $where .= " AND is_read = 0 ";
} elseif ($filter === 'low_stock') {
    $where .= " AND type = 'low_stock' ";
} elseif ($filter === 'dead_stock') {
    $where .= " AND type = 'dead_stock' ";
} elseif ($filter === 'credits') {
    $where .= " AND type = 'credit_due' ";
}

$query = "SELECT * FROM notifications WHERE $where ORDER BY created_at DESC LIMIT 100";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$notifications = $stmt->fetchAll();

$csrf_token = generateCSRFToken();

// Icon mapping
$type_icons = [
    'low_stock' => ['icon' => 'fa-box-open', 'class' => 'warning'],
    'dead_stock' => ['icon' => 'fa-skull-crossbones', 'class' => 'danger'],
    'credit_due' => ['icon' => 'fa-user-clock', 'class' => 'info'],
    'transfer' => ['icon' => 'fa-right-left', 'class' => 'primary'],
    'system' => ['icon' => 'fa-circle-info', 'class' => 'muted']
];

include_once __DIR__ . '/../../includes/header.php';
?>

<style>
    .notif-tabs { margin-bottom: 24px; border-bottom: 1px solid var(--border); display: flex; gap: 20px; }
    .notif-tab { padding: 12px 4px; color: var(--on-surface-muted); text-decoration: none; border-bottom: 2px solid transparent; font-size: 14px; font-weight: 500; }
    .notif-tab.active { color: var(--primary); border-bottom-color: var(--primary); }
    
    .notif-item { display: flex; gap: 16px; padding: 16px; border-bottom: 1px solid var(--border); cursor: pointer; transition: background 0.2s; position: relative; }
    .notif-item:hover { background: var(--surface-hover); }
    .notif-item.unread { border-left: 4px solid var(--primary); background: rgba(77, 120, 255, 0.03); }
    
    .notif-icon { width: 40px; height: 40px; border-radius: var(--radius); display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 16px; }
    .notif-icon.warning { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
    .notif-icon.danger { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
    .notif-icon.info { background: rgba(6, 182, 212, 0.1); color: #06b6d4; }
    .notif-icon.primary { background: rgba(77, 120, 255, 0.1); color: var(--primary); }
    .notif-icon.muted { background: var(--surface); color: var(--on-surface-subtle); }
    
    .notif-content { flex-grow: 1; }
    .notif-title { font-weight: 600; font-size: 15px; margin-bottom: 4px; color: var(--on-surface); }
    .notif-msg { color: var(--on-surface-muted); font-size: 13px; line-height: 1.5; }
    .notif-time { font-size: 11px; color: var(--on-surface-subtle); margin-top: 8px; }
    
    .unread-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--primary); position: absolute; right: 20px; top: 20px; }
</style>

<div class="page-header">
    <h1>Notifications</h1>
    <form action="" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="action" value="mark_all">
        <button type="submit" class="btn btn-outline btn-sm">Mark All Read</button>
    </form>
</div>

<div class="notif-tabs">
    <a href="?filter=all" class="notif-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">All</a>
    <a href="?filter=unread" class="notif-tab <?php echo $filter === 'unread' ? 'active' : ''; ?>">Unread</a>
    <a href="?filter=low_stock" class="notif-tab <?php echo $filter === 'low_stock' ? 'active' : ''; ?>">Low Stock</a>
    <a href="?filter=dead_stock" class="notif-tab <?php echo $filter === 'dead_stock' ? 'active' : ''; ?>">Dead Stock</a>
    <a href="?filter=credits" class="notif-tab <?php echo $filter === 'credits' ? 'active' : ''; ?>">Credits</a>
</div>

<div class="card p-0 overflow-hidden">
    <?php if (empty($notifications)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-bell-slash fa-3x mb-3"></i>
            <p>No notifications found in this category.</p>
        </div>
    <?php else: ?>
        <?php foreach ($notifications as $n): 
            $meta = $type_icons[$n['type']] ?? $type_icons['system'];
        ?>
            <div class="notif-item <?php echo $n['is_read'] ? '' : 'unread'; ?>" 
                 onclick="markRead(<?php echo $n['id']; ?>, this)">
                <div class="notif-icon <?php echo $meta['class']; ?>">
                    <i class="fas <?php echo $meta['icon']; ?>"></i>
                </div>
                <div class="notif-content">
                    <div class="notif-title"><?php echo sanitize($n['title']); ?></div>
                    <div class="notif-msg"><?php echo sanitize($n['message']); ?></div>
                    <div class="notif-time">
                        <i class="far fa-clock me-1"></i> 
                        <?php 
                        $time = strtotime($n['created_at']);
                        echo time_elapsed_string($n['created_at']); 
                        ?>
                    </div>
                </div>
                <?php if (!$n['is_read']): ?>
                    <div class="unread-dot"></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function markRead(id, el) {
    if (!el.classList.contains('unread')) return;
    
    fetch('mark_read.php?id=' + id, { method: 'POST' })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                el.classList.remove('unread');
                const dot = el.querySelector('.unread-dot');
                if (dot) dot.remove();
                
                // Update topbar badge if exists
                const badge = document.querySelector('.notif-badge');
                if (badge) {
                    let count = parseInt(badge.innerText) || 0;
                    if (count > 1) badge.innerText = count - 1;
                    else badge.remove();
                }
            }
        });
}
</script>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
