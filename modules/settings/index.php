<?php
/**
 * Global Settings Management - Superadmin Only
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireRole('superadmin');

$pageTitle = 'Settings';
$breadcrumb = '<a href="' . BASE_URL . '/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Settings</span>';
$active_tab = $_GET['tab'] ?? 'business';

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
    } else {
        $pdo->beginTransaction();
        try {
            // 1. Handle File Upload (Logo)
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = __DIR__ . '/../../assets/images/logo/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                
                $file_info = new finfo(FILEINFO_MIME_TYPE);
                $mime_type = $file_info->file($_FILES['logo']['tmp_name']);
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                
                if (!in_array($mime_type, $allowed_types)) {
                    throw new Exception('Invalid logo type. Only JPG, PNG, GIF, and WEBP are allowed.');
                } elseif ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
                    throw new Exception('Logo size exceeds 2MB limit.');
                } else {
                    $file_ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                    $new_filename = 'logo_' . uniqid() . '_' . time() . '.' . $file_ext;
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $new_filename)) {
                        updateSetting('company_logo', $new_filename);
                    }
                }
            }

            // 2. Handle Text Settings
            foreach ($_POST as $key => $value) {
                if (in_array($key, ['csrf_token', 'tab'])) continue;
                
                // Handle checkboxes (if not in POST, they are off)
                // For this implementation, we'll handle specific keys if needed, 
                // but a generic loop works for most.
                updateSetting($key, $value);
            }

            // Special case for checkboxes not sent in POST
            if ($active_tab === 'invoice') {
                $show_gst = isset($_POST['show_gst']) ? '1' : '0';
                updateSetting('show_gst', $show_gst);
            }

            $pdo->commit();
            logAudit('update_settings', 'settings', "Updated $active_tab settings");
            flashMessage('success', ucfirst($active_tab) . ' settings updated successfully.');
        } catch (Exception $e) {
            $pdo->rollBack();
            flashMessage('danger', 'Error: ' . $e->getMessage());
        }
    }
    redirect("index.php?tab=$active_tab");
}

// Fetch all settings for display
$settings = [];
$stmt = $pdo->query("SELECT key_name, value FROM settings");
while ($row = $stmt->fetch()) {
    $settings[$row['key_name']] = $row['value'];
}

include_once __DIR__ . '/../../includes/header.php';
?>

<style>
    .settings-nav { display: flex; gap: 24px; border-bottom: 1px solid var(--border); margin-bottom: 30px; }
    .settings-nav-item { padding: 12px 4px; color: var(--on-surface-muted); text-decoration: none; border-bottom: 2px solid transparent; font-size: 14px; font-weight: 500; }
    .settings-nav-item.active { color: var(--primary); border-bottom-color: var(--primary); }
    
    .logo-preview { width: 120px; height: 120px; border: 1px solid var(--border); border-radius: var(--radius); display: flex; align-items: center; justify-content: center; background: var(--surface); overflow: hidden; margin-bottom: 15px; }
    .logo-preview img { max-width: 100%; max-height: 100%; object-fit: contain; }
</style>

<div class="page-header">
    <h1>System Settings</h1>
</div>

<div class="settings-nav">
    <a href="?tab=business" class="settings-nav-item <?php echo $active_tab === 'business' ? 'active' : ''; ?>">Business Info</a>
    <a href="?tab=invoice"  class="settings-nav-item <?php echo $active_tab === 'invoice'  ? 'active' : ''; ?>">Invoice Settings</a>
    <a href="?tab=stock"    class="settings-nav-item <?php echo $active_tab === 'stock'    ? 'active' : ''; ?>">Stock &amp; Inventory</a>
    <a href="?tab=security" class="settings-nav-item <?php echo $active_tab === 'security' ? 'active' : ''; ?>">Security</a>
    <a href="audit_log.php" class="settings-nav-item" style="margin-left:auto;">
        <i class="fas fa-shield-halved" style="margin-right:5px;"></i>Audit Log
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-9">
        <form action="" method="POST" enctype="multipart/form-data" class="form-card">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="tab" value="<?php echo $active_tab; ?>">

            <?php if ($active_tab === 'business'): ?>
                <div class="row align-center mb-4 pb-4 border-bottom border-secondary">
                    <div class="col-md-3 text-center">
                        <div class="logo-preview">
                            <?php if (!empty($settings['company_logo'])): ?>
                                <img src="<?php echo BASE_URL; ?>/assets/images/logo/<?php echo $settings['company_logo']; ?>" alt="Logo">
                            <?php else: ?>
                                <i class="fas fa-image fa-2x text-muted"></i>
                            <?php endif; ?>
                        </div>
                        <label class="btn btn-ghost btn-sm">
                            <i class="fas fa-upload me-1"></i> Upload Logo
                            <input type="file" name="logo" hidden accept="image/*">
                        </label>
                    </div>
                    <div class="col-md-9">
                        <div class="form-group">
                            <label class="form-label">Company Name <span class="req">*</span></label>
                            <input type="text" name="company_name" class="form-control" value="<?php echo sanitize($settings['company_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group mt-3">
                            <label class="form-label">GST Number</label>
                            <input type="text" name="gst_number" class="form-control" value="<?php echo sanitize($settings['gst_number'] ?? ''); ?>" placeholder="Enter GSTIN">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Full Address</label>
                    <textarea name="address" class="form-control" rows="2"><?php echo sanitize($settings['address'] ?? ''); ?></textarea>
                </div>

                <div class="form-row mt-3">
                    <div class="form-group"><label class="form-label">City</label><input type="text" name="city" class="form-control" value="<?php echo sanitize($settings['city'] ?? ''); ?>"></div>
                    <div class="form-group"><label class="form-label">State</label><input type="text" name="state" class="form-control" value="<?php echo sanitize($settings['state'] ?? ''); ?>"></div>
                    <div class="form-group"><label class="form-label">Pincode</label><input type="text" name="pincode" class="form-control" value="<?php echo sanitize($settings['pincode'] ?? ''); ?>"></div>
                </div>

                <div class="form-row mt-3">
                    <div class="form-group"><label class="form-label">Phone</label><input type="text" name="company_phone" class="form-control" value="<?php echo sanitize($settings['company_phone'] ?? ''); ?>"></div>
                    <div class="form-group"><label class="form-label">Email</label><input type="email" name="company_email" class="form-control" value="<?php echo sanitize($settings['company_email'] ?? ''); ?>"></div>
                    <div class="form-group"><label class="form-label">Website</label><input type="text" name="website" class="form-control" value="<?php echo sanitize($settings['website'] ?? ''); ?>"></div>
                </div>

                <?php $owner_branches = $pdo->query("SELECT id, name FROM branches WHERE status='active' ORDER BY (code='MAIN') DESC, name ASC")->fetchAll(); ?>
                <div class="form-group mt-3 pt-3 border-top border-secondary">
                    <label class="form-label">Owner's Operating Branch (for billing)</label>
                    <select name="owner_branch_id" class="form-control" style="max-width:320px;">
                        <option value="">Main Branch (default)</option>
                        <?php foreach ($owner_branches as $ob): ?>
                            <option value="<?php echo $ob['id']; ?>" <?php echo ($settings['owner_branch_id'] ?? '') == $ob['id'] ? 'selected' : ''; ?>><?php echo sanitize($ob['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted d-block mt-1">When the owner (superadmin) opens the POS, bills are created for this branch. Other roles always bill for their own branch. Oversight pages (dashboard, reports) still show all branches.</small>
                </div>

            <?php elseif ($active_tab === 'invoice'): ?>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Invoice Prefix</label>
                        <input type="text" name="invoice_prefix" class="form-control" value="<?php echo sanitize($settings['invoice_prefix'] ?? 'INV-'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Default GST %</label>
                        <select name="default_gst_percent" class="form-control">
                            <?php foreach ([0, 5, 12, 18, 28] as $g): ?>
                                <option value="<?php echo $g; ?>" <?php echo ($settings['default_gst_percent'] ?? '18') == $g ? 'selected' : ''; ?>><?php echo $g; ?>%</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Currency Symbol</label>
                        <input type="text" name="currency_symbol" class="form-control" value="<?php echo sanitize($settings['currency_symbol'] ?? '₹'); ?>">
                    </div>
                </div>

                <div class="form-group mt-3">
                    <label class="form-label">Invoice Note (Internal)</label>
                    <textarea name="invoice_note" class="form-control" rows="2"><?php echo sanitize($settings['invoice_note'] ?? ''); ?></textarea>
                </div>

                <div class="form-group mt-3">
                    <label class="form-label">Invoice Footer (Public)</label>
                    <textarea name="invoice_footer" class="form-control" rows="2"><?php echo sanitize($settings['invoice_footer'] ?? ''); ?></textarea>
                </div>

                <div class="form-group mt-3">
                    <label class="form-label">Return Policy — English (bold, printed on bill)</label>
                    <textarea name="return_policy_en" class="form-control" rows="2"><?php echo sanitize($settings['return_policy_en'] ?? 'NO RETURN • NO EXCHANGE • NO REFUND'); ?></textarea>
                </div>

                <div class="form-group mt-3">
                    <label class="form-label">Return Policy — Marathi (bold, printed on bill)</label>
                    <textarea name="return_policy_mr" class="form-control" rows="2"><?php echo sanitize($settings['return_policy_mr'] ?? 'माल विकला गेला आहे. परतावा, बदल किंवा पैसे परत मिळणार नाहीत.'); ?></textarea>
                </div>

                <div class="form-row mt-3">
                    <div class="form-group">
                        <label class="form-label">Default Print Format</label>
                        <select name="default_print_format" class="form-control">
                            <option value="a4" <?php echo ($settings['default_print_format'] ?? 'a4') === 'a4' ? 'selected' : ''; ?>>A4 Professional</option>
                            <option value="thermal" <?php echo ($settings['default_print_format'] ?? '') === 'thermal' ? 'selected' : ''; ?>>Thermal 80mm</option>
                        </select>
                    </div>
                    <div class="form-group d-flex align-end pb-2">
                        <label class="d-flex align-center gap-2 cursor-pointer">
                            <input type="checkbox" name="show_gst" value="1" <?php echo ($settings['show_gst'] ?? '1') == '1' ? 'checked' : ''; ?>>
                            <span>Show GST on Invoice</span>
                        </label>
                    </div>
                </div>

            <?php elseif ($active_tab === 'stock'): ?>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Dead Stock Alert Days</label>
                        <select name="dead_stock_days" class="form-control">
                            <?php foreach ([30, 60, 90, 120] as $d): ?>
                                <option value="<?php echo $d; ?>" <?php echo ($settings['dead_stock_days'] ?? '90') == $d ? 'selected' : ''; ?>><?php echo $d; ?> Days</option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Products not sold in these many days appear in Dead Stock.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Global Default Alert Qty</label>
                        <input type="number" name="default_alert_qty" class="form-control" value="<?php echo sanitize($settings['default_alert_qty'] ?? '5'); ?>">
                        <small class="text-muted">Used if product-specific alert qty is 0.</small>
                    </div>
                </div>

            <?php elseif ($active_tab === 'security'): ?>
                <div class="form-group">
                    <label class="form-label">Session Timeout (Hours)</label>
                    <input type="number" name="session_timeout" class="form-control" value="<?php echo sanitize($settings['session_timeout'] ?? '24'); ?>">
                    <small class="text-muted">Users will be logged out after this many hours of inactivity.</small>
                </div>

                <div class="mt-5 pt-4 border-top">
                    <h4 class="h6 mb-3">Quick Actions</h4>
                    <div class="d-flex gap-3">
                        <a href="../users/index.php" class="btn btn-outline btn-sm">
                            <i class="fas fa-users me-1"></i> Manage All Users
                        </a>
                        <a href="../auth/change_password.php" class="btn btn-outline btn-sm">
                            <i class="fas fa-key me-1"></i> Change My Password
                        </a>
                    </div>
                </div>

                <div class="mt-4 p-3 bg-variant rounded">
                    <div class="fs-12 text-muted mb-1">Your Last Login:</div>
                    <div class="fs-14 fw-600"><?php echo formatDateTime($_SESSION['last_login'] ?? date('Y-m-d H:i:s')); ?></div>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-end gap-2 mt-5 border-top pt-4">
                <button type="submit" class="btn btn-primary px-5">
                    <i class="fas fa-save me-1"></i> Save <?php echo ucfirst($active_tab); ?> Settings
                </button>
            </div>
        </form>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
