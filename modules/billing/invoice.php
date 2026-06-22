<?php
/**
 * Bill Invoice View & Print
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$bill_id = (int)($_GET['id'] ?? 0);
if (!$bill_id) redirect('history.php');

// Fetch Bill with Branch and Creator info
$stmt = $pdo->prepare("SELECT b.*, br.name as branch_name, br.address as branch_address, br.phone as branch_phone, u.name as cashier_name 
                     FROM bills b 
                     JOIN branches br ON b.branch_id = br.id 
                     JOIN users u ON b.created_by = u.id 
                     WHERE b.id = ?" . (!isAdmin() ? " AND b.branch_id = " . (int)$_SESSION['branch_id'] : ""));
$stmt->execute([$bill_id]);
$bill = $stmt->fetch();

if (!$bill) {
    flashMessage('danger', 'Bill not found or access denied.');
    redirect('history.php');
}

// Fetch Bill Items
$stmt = $pdo->prepare("SELECT * FROM bill_items WHERE bill_id = ?");
$stmt->execute([$bill_id]);
$items = $stmt->fetchAll();

// System Settings
$company_name = getSettingValue('company_name') ?: 'BillManage';
$company_logo = getSettingValue('company_logo');
$gst_number = getSettingValue('gst_number') ?: '';
$invoice_footer = getSettingValue('invoice_footer') ?: 'Thank you for your business!';
$return_policy_en = getSettingValue('return_policy_en') ?: 'NO RETURN • NO EXCHANGE • NO REFUND';
$return_policy_mr = getSettingValue('return_policy_mr') ?: 'माल विकला गेला आहे. परतावा, बदल किंवा पैसे परत मिळणार नाहीत.';
$show_gst = getSettingValue('show_gst') ?? '1';

$pageTitle = 'Invoice ' . $bill['bill_number'];
$breadcrumb = '<a href="'.BASE_URL.'/modules/billing/history.php">Billing</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Invoice '.$bill['bill_number'].'</span>';
include_once __DIR__ . '/../../includes/header.php';
?>

<style>
    .invoice-card {
        background: white;
        color: #1E293B;
        padding: 40px 48px;
        border-radius: 12px;
        box-shadow: 0 4px 24px rgba(0,0,0,0.08);
        max-width: 820px;
        margin: 0 auto;
        border: 1.5px solid #E2E8F0;
    }
    .invoice-logo { max-height: 56px; margin-bottom: 10px; }
    .inv-divider  { border: none; border-top: 1.5px solid #E2E8F0; margin: 24px 0; }
    .invoice-table { width: 100%; border-collapse: collapse; }
    .invoice-table th { background: #F8FAFC; padding: 10px 14px; text-align: left; border-bottom: 1.5px solid #E2E8F0; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; color: #64748B; }
    .invoice-table td { padding: 12px 14px; border-bottom: 1px solid #F1F5F9; font-size: 13px; color: #334155; }
    .invoice-table tbody tr:last-child td { border-bottom: none; }
    .invoice-table tbody tr:hover td { background: #F8FAFC; }

    @media print {
        body { background: white !important; }
        .sidebar, .top-header, .no-print, .toast-container { display: none !important; }
        .main-content { margin: 0 !important; }
        .page-content { padding: 0 !important; }
        .invoice-card { box-shadow: none !important; border: none !important; padding: 20px !important; max-width: 100% !important; }
    }
</style>

<!-- Actions Bar -->
<div class="no-print" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
    <a href="history.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back to History</a>
    <div style="display:flex;gap:8px;">
        <a href="create.php" class="btn btn-ghost btn-sm"><i class="fas fa-plus"></i> New Bill</a>
        <a href="thermal.php?id=<?php echo $bill_id; ?>" class="btn btn-outline btn-sm"><i class="fas fa-receipt"></i> Thermal</a>
        <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> Print Invoice</button>
    </div>
</div>

<div class="invoice-card">

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:28px;">
        <div>
            <?php if ($company_logo): ?>
                <img src="<?php echo BASE_URL; ?>/assets/images/logo/<?php echo $company_logo; ?>" class="invoice-logo" alt="Logo">
            <?php endif; ?>
            <div style="font-size:22px;font-weight:800;color:#6366F1;letter-spacing:-0.5px;"><?php echo sanitize($company_name); ?></div>
            <div style="font-size:12px;color:#64748B;margin-top:4px;line-height:1.6;">
                <?php echo nl2br(sanitize($bill['branch_address'])); ?><br>
                <i class="fas fa-phone" style="font-size:10px;"></i> <?php echo $bill['branch_phone']; ?>
                <?php if ($show_gst == '1' && $gst_number): ?> &nbsp;|&nbsp; GSTIN: <?php echo $gst_number; ?><?php endif; ?>
            </div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:28px;font-weight:800;color:#94A3B8;letter-spacing:2px;">INVOICE</div>
            <div style="font-size:13px;margin-top:8px;color:#475569;">
                <div><span style="color:#94A3B8;">Number:</span> <strong><?php echo $bill['bill_number']; ?></strong></div>
                <div><span style="color:#94A3B8;">Date:</span> <strong><?php echo formatDateTime($bill['created_at']); ?></strong></div>
            </div>
            <div style="margin-top:10px;">
                <span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:600;
                    background:<?php echo $bill['status'] == 'paid' ? '#F0FDF4' : ($bill['status'] == 'cancelled' ? '#FEF2F2' : '#FFFBEB'); ?>;
                    color:<?php echo $bill['status'] == 'paid' ? '#166534' : ($bill['status'] == 'cancelled' ? '#991B1B' : '#92400E'); ?>;
                    border:1px solid <?php echo $bill['status'] == 'paid' ? '#BBF7D0' : ($bill['status'] == 'cancelled' ? '#FECACA' : '#FDE68A'); ?>;">
                    <?php echo strtoupper($bill['status']); ?>
                </span>
            </div>
        </div>
    </div>

    <hr class="inv-divider">

    <!-- Bill To / Cashier -->
    <div style="display:flex;justify-content:space-between;margin-bottom:24px;">
        <div>
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94A3B8;margin-bottom:6px;">Bill To</div>
            <div style="font-size:15px;font-weight:600;color:#1E293B;"><?php echo sanitize($bill['customer_name']) ?: 'Walk-in Customer'; ?></div>
            <?php if ($bill['customer_phone']): ?>
                <div style="font-size:13px;color:#64748B;margin-top:2px;"><i class="fas fa-phone" style="font-size:10px;"></i> <?php echo $bill['customer_phone']; ?></div>
            <?php endif; ?>
            <?php if ($bill['bill_type'] === 'credit' && !empty($bill['collector_name'])): ?>
                <div style="font-size:12px;color:#64748B;margin-top:4px;"><i class="fas fa-person-walking" style="font-size:10px;"></i> Collected by: <strong><?php echo sanitize($bill['collector_name']); ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($bill['employee_name'])): ?>
                <div style="font-size:12px;color:#64748B;margin-top:4px;"><i class="fas fa-user-tag" style="font-size:10px;"></i> Employee: <strong><?php echo sanitize($bill['employee_name']); ?></strong></div>
            <?php endif; ?>
        </div>
        <div style="text-align:right;">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94A3B8;margin-bottom:6px;">Served By</div>
            <div style="font-size:14px;font-weight:600;color:#1E293B;"><?php echo sanitize($bill['cashier_name']); ?></div>
            <div style="margin-top:6px;">
                <?php
                $type_style = [
                    'cash'   => ['#ECFEFF','#0E7490','#A5F3FC','fa-money-bill-wave'],
                    'credit' => ['#FFF7ED','#D97706','#FDE68A','fa-hourglass-half'],
                    'online' => ['#F0FDF4','#16A34A','#BBF7D0','fa-wifi'],
                    'card'   => ['#EDE9FE','#7C3AED','#DDD6FE','fa-credit-card'],
                ];
                [$ts_bg,$ts_color,$ts_border,$ts_icon] = $type_style[$bill['bill_type']] ?? $type_style['cash'];
                ?>
                <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;
                    background:<?php echo $ts_bg; ?>;
                    color:<?php echo $ts_color; ?>;
                    border:1px solid <?php echo $ts_border; ?>;">
                    <i class="fas <?php echo $ts_icon; ?>"></i>
                    <?php echo strtoupper($bill['bill_type']); ?> PAYMENT
                </span>
            </div>
        </div>
    </div>

    <!-- Items Table -->
    <table class="invoice-table">
        <thead>
            <tr>
                <th style="width:40px;">#</th>
                <th>Item Description</th>
                <th style="width:60px;text-align:center;">Qty</th>
                <th style="width:100px;text-align:right;">Rate</th>
                <th style="width:80px;text-align:right;">Discount</th>
                <th style="width:110px;text-align:right;">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $idx => $item): ?>
                <tr>
                    <td style="color:#94A3B8;"><?php echo $idx + 1; ?></td>
                    <td style="font-weight:600;"><?php echo sanitize($item['product_name']); ?><?php echo !empty($item['size']) ? ' <span style="color:#94A3B8;font-size:11px;font-weight:500;">('.sanitize($item['size']).')</span>' : ''; ?></td>
                    <td style="text-align:center;"><?php echo $item['quantity']; ?></td>
                    <td style="text-align:right;">₹<?php echo number_format($item['selling_price'], 2); ?></td>
                    <td style="text-align:right;color:#EF4444;"><?php echo $item['discount'] > 0 ? '-₹'.number_format($item['discount'], 2) . (!empty($item['discount_percent']) ? ' <span style="color:#94A3B8;font-size:11px;">('.rtrim(rtrim(number_format($item['discount_percent'],2),'0'),'.').'%)</span>' : '') : '—'; ?></td>
                    <td style="text-align:right;font-weight:700;">₹<?php echo number_format($item['total'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <hr class="inv-divider">

    <!-- Footer: Notes + Totals -->
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:24px;">
        <div style="max-width:360px;">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94A3B8;margin-bottom:6px;">Notes</div>
            <div style="font-size:12px;color:#64748B;line-height:1.6;"><?php echo nl2br(sanitize($invoice_footer)); ?></div>
        </div>
        <div style="min-width:260px;">
            <table style="width:100%;border-collapse:collapse;">
                <tr><td style="padding:5px 0;font-size:13px;color:#64748B;">Subtotal</td><td style="text-align:right;font-size:13px;font-weight:500;">₹<?php echo number_format($bill['subtotal'], 2); ?></td></tr>
                <?php if ($bill['discount_amount'] > 0): ?>
                    <tr><td style="padding:5px 0;font-size:13px;color:#64748B;">Discount (<?php echo $bill['discount_percent']; ?>%)</td><td style="text-align:right;font-size:13px;color:#EF4444;font-weight:500;">-₹<?php echo number_format($bill['discount_amount'], 2); ?></td></tr>
                <?php endif; ?>
                <?php if ($bill['gst_percent'] >= 18): ?>
                <tr><td style="padding:5px 0;font-size:13px;color:#64748B;">GST (<?php echo $bill['gst_percent']; ?>%)</td><td style="text-align:right;font-size:13px;font-weight:500;">₹<?php echo number_format($bill['gst_amount'], 2); ?></td></tr>
                <?php endif; ?>
                <?php if (!empty($bill['alteration_charge']) && $bill['alteration_charge'] > 0): ?>
                <tr><td style="padding:5px 0;font-size:13px;color:#64748B;">Alteration<?php echo $bill['alteration_length'] ? ' (New Length: '.sanitize($bill['alteration_length']).')' : ''; ?></td><td style="text-align:right;font-size:13px;font-weight:500;">+₹<?php echo number_format($bill['alteration_charge'], 2); ?></td></tr>
                <?php endif; ?>
                <tr>
                    <td style="padding:12px 0 5px;border-top:2px solid #E2E8F0;font-size:16px;font-weight:700;color:#1E293B;">Grand Total</td>
                    <td style="text-align:right;padding:12px 0 5px;border-top:2px solid #E2E8F0;font-size:18px;font-weight:800;color:#6366F1;">₹<?php echo number_format($bill['total_amount'], 2); ?></td>
                </tr>
                <?php if ($bill['bill_type'] !== 'credit'): ?>
                    <tr><td style="padding:4px 0;font-size:12px;color:#94A3B8;">Received</td><td style="text-align:right;font-size:12px;color:#94A3B8;">₹<?php echo number_format($bill['paid_amount'], 2); ?></td></tr>
                    <tr><td style="padding:4px 0;font-size:12px;color:#94A3B8;">Change</td><td style="text-align:right;font-size:12px;color:#94A3B8;">₹<?php echo number_format(max(0, $bill['paid_amount'] - $bill['total_amount']), 2); ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <?php if (!empty($return_policy_en) || !empty($return_policy_mr)): ?>
    <div style="margin-top:28px;padding:12px;border:1.5px solid #1E293B;border-radius:6px;text-align:center;">
        <?php if (!empty($return_policy_en)): ?>
            <div style="font-size:13px;font-weight:800;color:#1E293B;letter-spacing:0.5px;text-transform:uppercase;"><?php echo sanitize($return_policy_en); ?></div>
        <?php endif; ?>
        <?php if (!empty($return_policy_mr)): ?>
            <div style="font-size:12px;font-weight:700;color:#1E293B;margin-top:4px;"><?php echo sanitize($return_policy_mr); ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div style="margin-top:20px;padding-top:16px;border-top:1.5px solid #F1F5F9;text-align:center;">
        <p style="font-size:11px;color:#94A3B8;">This is a computer-generated invoice. No signature required. &nbsp;|&nbsp; <?php echo sanitize($company_name); ?></p>
    </div>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
