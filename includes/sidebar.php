<?php
$current_uri = $_SERVER['REQUEST_URI'];
$user = getCurrentUser();
$unread_notifs = getUnreadNotificationCount();
?>
<aside class="sidebar" id="sidebar">

    <!-- Logo -->
    <div class="sidebar-logo" style="position:relative;">
        <div class="logo-icon">BM</div>
        <div class="sidebar-logo-text">
            <h2>BillManage</h2>
            <span><?php echo sanitize($user['branch_name']); ?></span>
        </div>
        <button class="sidebar-collapse-btn" id="sidebar-collapse-btn" title="Toggle sidebar">
            <i class="fas fa-chevron-left" id="collapse-icon"></i>
        </button>
    </div>

    <!-- Main Menu -->
    <div class="nav-section">Main Menu</div>

    <a href="<?php echo BASE_URL; ?>/modules/dashboard/index.php"
       class="nav-item <?php echo strpos($current_uri, '/dashboard/') !== false ? 'active' : ''; ?>"
       data-tooltip="Dashboard">
        <i class="fas fa-chart-pie"></i>
        <span class="nav-label">Dashboard</span>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/billing/create.php"
       class="nav-item <?php echo strpos($current_uri, '/billing/create') !== false ? 'active' : ''; ?>"
       data-tooltip="Billing / POS">
        <i class="fas fa-cash-register"></i>
        <span class="nav-label">Billing / POS</span>
        <span class="nav-pos-badge">POS</span>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/billing/history.php"
       class="nav-item <?php echo strpos($current_uri, '/billing/history') !== false ? 'active' : ''; ?>"
       data-tooltip="Reprint Sale">
        <i class="fas fa-clock-rotate-left"></i>
        <span class="nav-label">Reprint Sale</span>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/billing/drafts.php"
       class="nav-item <?php echo strpos($current_uri, '/billing/drafts') !== false ? 'active' : ''; ?>"
       data-tooltip="Draft Bills">
        <i class="fas fa-floppy-disk"></i>
        <span class="nav-label">Draft Bills</span>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/billing/today.php"
       class="nav-item <?php echo strpos($current_uri, '/billing/today') !== false ? 'active' : ''; ?>"
       data-tooltip="Today's Sales">
        <i class="fas fa-calendar-day"></i>
        <span class="nav-label">Today's Sales</span>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/exchange/index.php"
       class="nav-item <?php echo (strpos($current_uri, '/exchange/') !== false || strpos($current_uri, '/defective/') !== false) ? 'active' : ''; ?>"
       data-tooltip="Exchange / Replacement">
        <i class="fas fa-right-left"></i>
        <span class="nav-label">Exchange / Replace</span>
    </a>

    <!-- Inventory -->
    <div class="nav-section">Inventory</div>

    <a href="<?php echo BASE_URL; ?>/modules/products/index.php"
       class="nav-item <?php echo strpos($current_uri, '/products/') !== false ? 'active' : ''; ?>"
       data-tooltip="Products">
        <i class="fas fa-box"></i>
        <span class="nav-label">Products</span>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/inventory/index.php"
       class="nav-item <?php echo (strpos($current_uri, '/inventory/') !== false && strpos($current_uri, '/inventory/stock_check') === false) ? 'active' : ''; ?>"
       data-tooltip="Inventory">
        <i class="fas fa-warehouse"></i>
        <span class="nav-label">Inventory</span>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/inventory/stock_check.php"
       class="nav-item <?php echo strpos($current_uri, '/inventory/stock_check') !== false ? 'active' : ''; ?>"
       data-tooltip="Stock Check">
        <i class="fas fa-magnifying-glass-chart"></i>
        <span class="nav-label">Stock Check</span>
    </a>

    <?php if (!isCashier()): ?>
    <a href="<?php echo BASE_URL; ?>/modules/purchase/index.php"
       class="nav-item <?php echo strpos($current_uri, '/purchase/') !== false ? 'active' : ''; ?>"
       data-tooltip="Purchase">
        <i class="fas fa-truck"></i>
        <span class="nav-label">Purchase</span>
    </a>
    <?php endif; ?>

    <!-- People -->
    <div class="nav-section">People</div>

    <?php if (!isCashier()): ?>
    <a href="<?php echo BASE_URL; ?>/modules/vendors/index.php"
       class="nav-item <?php echo (strpos($current_uri, '/vendors/') !== false && strpos($current_uri, '/vendors/report') === false) ? 'active' : ''; ?>"
       data-tooltip="Vendors">
        <i class="fas fa-handshake"></i>
        <span class="nav-label">Vendors</span>
    </a>
    <?php endif; ?>

    <a href="<?php echo BASE_URL; ?>/modules/customers/search.php"
       class="nav-item <?php echo strpos($current_uri, '/customers/search') !== false ? 'active' : ''; ?>"
       data-tooltip="Customer Search">
        <i class="fas fa-magnifying-glass"></i>
        <span class="nav-label">Customer Search</span>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/customers/index.php"
       class="nav-item <?php echo (strpos($current_uri, '/customers/') !== false && strpos($current_uri, '/customers/search') === false) ? 'active' : ''; ?>"
       data-tooltip="Credit Customers">
        <i class="fas fa-users"></i>
        <span class="nav-label">Credit Customers</span>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/birthday/index.php"
       class="nav-item <?php echo strpos($current_uri, '/birthday/') !== false ? 'active' : ''; ?>"
       data-tooltip="Birthday List">
        <i class="fas fa-cake-candles"></i>
        <span class="nav-label">Birthday List</span>
    </a>

    <?php if (!isCashier()): ?>
    <a href="<?php echo BASE_URL; ?>/modules/alteration/index.php"
       class="nav-item <?php echo strpos($current_uri, '/alteration/') !== false ? 'active' : ''; ?>"
       data-tooltip="Alterations">
        <i class="fas fa-scissors"></i>
        <span class="nav-label">Alterations</span>
    </a>
    <?php endif; ?>

    <!-- Finance -->
    <div class="nav-section">Finance</div>

    <?php if (!isCashier()): ?>
    <a href="<?php echo BASE_URL; ?>/modules/expenses/index.php"
       class="nav-item <?php echo strpos($current_uri, '/expenses/') !== false ? 'active' : ''; ?>"
       data-tooltip="Expenses">
        <i class="fas fa-receipt"></i>
        <span class="nav-label">Expenses</span>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/reports/index.php"
       class="nav-item <?php echo strpos($current_uri, '/reports/') !== false ? 'active' : ''; ?>"
       data-tooltip="Reports">
        <i class="fas fa-chart-bar"></i>
        <span class="nav-label">Reports</span>
    </a>
    <?php endif; ?>

    <!-- Multi-Branch (Superadmin only) -->
    <?php if (isAdmin()): ?>
    <div class="nav-section">Multi-Branch</div>

    <a href="<?php echo BASE_URL; ?>/modules/branches/index.php"
       class="nav-item <?php echo (strpos($current_uri, '/branches/') !== false && strpos($current_uri, '/transfer') === false) ? 'active' : ''; ?>"
       data-tooltip="Branches">
        <i class="fas fa-store"></i>
        <span class="nav-label">Branches</span>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/branches/transfer.php"
       class="nav-item <?php echo strpos($current_uri, '/branches/transfer') !== false ? 'active' : ''; ?>"
       data-tooltip="Stock Transfer">
        <i class="fas fa-arrows-rotate"></i>
        <span class="nav-label">Stock Transfer</span>
    </a>
    <?php endif; ?>

    <!-- System -->
    <div class="nav-section">System</div>

    <a href="<?php echo BASE_URL; ?>/modules/notifications/index.php"
       class="nav-item <?php echo strpos($current_uri, '/notifications/') !== false ? 'active' : ''; ?>"
       data-tooltip="Notifications">
        <i class="fas fa-bell"></i>
        <span class="nav-label">Notifications</span>
        <?php if ($unread_notifs > 0): ?>
            <span class="nav-badge"><?php echo $unread_notifs; ?></span>
        <?php endif; ?>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/settings/index.php"
       class="nav-item <?php echo strpos($current_uri, '/settings/') !== false ? 'active' : ''; ?>"
       data-tooltip="Settings">
        <i class="fas fa-gear"></i>
        <span class="nav-label">Settings</span>
    </a>

    <?php if (isAdmin()): ?>
    <a href="<?php echo BASE_URL; ?>/modules/users/index.php"
       class="nav-item <?php echo strpos($current_uri, '/users/') !== false ? 'active' : ''; ?>"
       data-tooltip="Users">
        <i class="fas fa-users-gear"></i>
        <span class="nav-label">Users</span>
    </a>
    <?php endif; ?>

    <!-- User Profile -->
    <div class="sidebar-user">
        <div class="avatar"><?php echo strtoupper(substr($user['name'], 0, 1)); ?></div>
        <div class="user-info">
            <div class="user-name"><?php echo sanitize($user['name']); ?></div>
            <div class="user-role"><?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?></div>
        </div>
        <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link" title="Logout">
            <i class="fas fa-sign-out-alt"></i>
        </a>
    </div>

</aside>

<script>
// Sync collapse icon direction
(function() {
    const btn  = document.getElementById('sidebar-collapse-btn');
    const icon = document.getElementById('collapse-icon');
    const sb   = document.getElementById('sidebar');
    if (!btn || !icon || !sb) return;

    function syncIcon() {
        if (sb.classList.contains('collapsed')) {
            icon.className = 'fas fa-chevron-right';
        } else {
            icon.className = 'fas fa-chevron-left';
        }
    }

    syncIcon();
    btn.addEventListener('click', () => setTimeout(syncIcon, 50));
})();
</script>
