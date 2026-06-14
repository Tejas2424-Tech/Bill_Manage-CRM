<?php
$user          = getCurrentUser();
$unread_notifs = getUnreadNotificationCount();
$flash         = getFlashMessage();
?>
<header class="top-header">

    <!-- Mobile Toggle -->
    <button class="icon-btn" id="mobile-toggle" style="border:none;background:transparent;" title="Toggle Menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Page Title & Breadcrumb -->
    <div>
        <h1 class="page-title"><?php echo $pageTitle ?? 'Dashboard'; ?></h1>
        <?php if (isset($breadcrumb)): ?>
            <div class="breadcrumb"><?php echo $breadcrumb; ?></div>
        <?php endif; ?>
    </div>

    <!-- Right Actions -->
    <div class="header-actions">

        <!-- Global Search -->
        <div class="global-search">
            <i class="fas fa-magnifying-glass"></i>
            <input type="text" id="global-search-input" placeholder="Search..." autocomplete="off">
            <span class="kbd">Ctrl K</span>
        </div>

        <!-- Notification Bell -->
        <div class="dropdown-wrapper" id="notif-dropdown">
            <button class="icon-btn" onclick="toggleNotifDropdown()" title="Notifications">
                <i class="fas fa-bell"></i>
                <?php if ($unread_notifs > 0): ?>
                    <span class="notif-badge"><?php echo $unread_notifs > 9 ? '9+' : $unread_notifs; ?></span>
                <?php endif; ?>
            </button>

            <div class="notif-dropdown-content" id="notif-content">
                <div class="notif-dropdown-header">
                    <span><i class="fas fa-bell" style="color:var(--primary);margin-right:6px;font-size:12px;"></i>Notifications</span>
                    <a href="<?php echo BASE_URL; ?>/modules/notifications/index.php">View all</a>
                </div>
                <div class="notif-dropdown-body" id="notif-items">
                    <div style="text-align:center;padding:24px;font-size:13px;color:var(--on-surface-muted)">
                        <i class="fas fa-spinner fa-spin"></i> Loading…
                    </div>
                </div>
            </div>
        </div>

        <!-- User Chip -->
        <div class="user-chip">
            <div class="chip-avatar"><?php echo strtoupper(substr($user['name'], 0, 1)); ?></div>
            <span><?php echo sanitize($user['name']); ?></span>
            <i class="fas fa-chevron-down"></i>
        </div>

    </div>
</header>

<?php if ($flash): ?>
<!-- Toast Flash Message -->
<div class="toast-container" id="flash-toast-container">
    <div class="toast toast-<?php echo $flash['type']; ?> alert-auto-dismiss" role="alert">
        <i class="fas fa-<?php
            echo match($flash['type']) {
                'success' => 'circle-check',
                'danger'  => 'circle-xmark',
                'warning' => 'triangle-exclamation',
                default   => 'circle-info',
            };
        ?> toast-icon"></i>
        <div class="toast-body"><?php echo $flash['message']; ?></div>
        <button onclick="this.closest('.toast').remove()" style="background:none;border:none;cursor:pointer;color:var(--on-surface-subtle);font-size:14px;margin-left:8px;padding:0;" title="Dismiss">
            <i class="fas fa-xmark"></i>
        </button>
    </div>
</div>
<?php endif; ?>
