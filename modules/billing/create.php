<?php
/**
 * POS / Billing Creation
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../customers/customer_lib.php';

requireLogin();

// Branch the user is billing AS. Non-admins use their own branch; the owner
// (superadmin) operates as the configured/Main branch (see getOperatingBranchId()).
$branch_id = getOperatingBranchId();

// Resolve the branch name/code for display and bill numbering.
$branch_meta = null;
if ($branch_id) {
    $bmeta = $pdo->prepare("SELECT name, code FROM branches WHERE id = ?");
    $bmeta->execute([$branch_id]);
    $branch_meta = $bmeta->fetch();
}
$branch_name = $branch_meta['name'] ?? ($_SESSION['branch_name'] ?? '');
$branch_code = $branch_meta['code'] ?? 'MAIN';

// 2. Handle POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
        redirect('create.php');
    }

    $cart = json_decode($_POST['cart_json'], true);
    if (empty($cart)) {
        flashMessage('danger', 'Cart is empty.');
        redirect('create.php');
    }

    // $branch_id / $branch_code are already resolved above to the operating branch.

    $customer_name = sanitize($_POST['customer_name'] ?? 'Walk-in');
    $customer_phone = sanitize($_POST['customer_phone'] ?? '');
    $collector_name = sanitize($_POST['collector_name'] ?? '');
    $employee_name = sanitize($_POST['employee_name'] ?? '');
    $customer_dob = trim($_POST['customer_dob'] ?? '');
    $bill_type = $_POST['bill_type'] ?? 'cash';
    $subtotal = (float)$_POST['subtotal'];
    $discount_amount = (float)$_POST['discount_amount'];
    $discount_percent = (float)$_POST['discount_percent'];
    $gst_amount = (float)$_POST['gst_amount'];
    $gst_percent = (float)$_POST['gst_percent'];
    $total_amount = (float)$_POST['total_amount'];
    $received_amount = (float)$_POST['received_amount'];
    $status = $bill_type === 'credit' ? 'credit' : 'paid';

    // Per-bill alteration (charge is already folded into total_amount client-side).
    $alteration_required = !empty($_POST['alteration_required']) ? 1 : 0;
    $alteration_length   = sanitize($_POST['alteration_length'] ?? '');
    $alteration_charge   = (float)($_POST['alteration_charge'] ?? 0);
    if (!$alteration_required) { $alteration_length = ''; $alteration_charge = 0; }

    $save_mode = ($_POST['save_mode'] ?? 'confirm') === 'draft' ? 'draft' : 'confirm';
    $draft_id  = (int)($_POST['draft_id'] ?? 0);
    $draft_scope = !isAdmin() ? " AND branch_id = " . (int)$branch_id : "";

    // ── Save as DRAFT — held cart, no stock movement, no bill number ──
    if ($save_mode === 'draft') {
        $cart_json = $_POST['cart_json'];
        $dob = $customer_dob !== '' ? $customer_dob : null;
        if ($draft_id) {
            $stmt = $pdo->prepare("UPDATE draft_bills SET customer_name=?, customer_phone=?, customer_dob=?, employee_name=?, bill_type=?, alteration_required=?, alteration_length=?, alteration_charge=?, cart_json=?, subtotal=?, discount_amount=?, discount_percent=?, gst_amount=?, gst_percent=?, total_amount=? WHERE id=?$draft_scope");
            $stmt->execute([$customer_name, $customer_phone, $dob, $employee_name, $bill_type, $alteration_required, $alteration_length, $alteration_charge, $cart_json, $subtotal, $discount_amount, $discount_percent, $gst_amount, $gst_percent, $total_amount, $draft_id]);
            flashMessage('success', 'Draft updated.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO draft_bills (branch_id, customer_name, customer_phone, customer_dob, employee_name, bill_type, alteration_required, alteration_length, alteration_charge, cart_json, subtotal, discount_amount, discount_percent, gst_amount, gst_percent, total_amount, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$branch_id, $customer_name, $customer_phone, $dob, $employee_name, $bill_type, $alteration_required, $alteration_length, $alteration_charge, $cart_json, $subtotal, $discount_amount, $discount_percent, $gst_amount, $gst_percent, $total_amount, $_SESSION['user_id']]);
            flashMessage('success', 'Saved as draft.');
        }
        logAudit('save_draft', 'billing', "Draft saved (₹$total_amount)");
        redirect('drafts.php');
    }

    try {
        $pdo->beginTransaction();

        $bill_number = generateBillNumber($branch_code, $branch_id);

        // Find-or-create the customer master record (null for walk-in / no phone).
        $customer_id = findOrCreateCustomer($pdo, $branch_id, $customer_name, $customer_phone, $customer_dob, (int)$_SESSION['user_id']);

        // 3. Insert Bill
        $stmt = $pdo->prepare("INSERT INTO bills (branch_id, customer_id, bill_number, customer_name, customer_phone, collector_name, employee_name, bill_type, subtotal, discount_amount, discount_percent, gst_amount, gst_percent, total_amount, paid_amount, alteration_required, alteration_length, alteration_charge, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$branch_id, $customer_id, $bill_number, $customer_name, $customer_phone, $collector_name, $employee_name, $bill_type, $subtotal, $discount_amount, $discount_percent, $gst_amount, $gst_percent, $total_amount, $received_amount, $alteration_required, $alteration_length, $alteration_charge, $status, $_SESSION['user_id']]);
        $bill_id = $pdo->lastInsertId();

        // 4. Insert Items and Update Stock
        foreach ($cart as $item) {
            // Validate stock against the operating branch (the owner bills as the
            // Main/operating branch; staff are locked to their own branch).
            $check = $pdo->prepare("SELECT quantity, name FROM products WHERE id = ? AND branch_id = ?");
            $check->execute([$item['id'], $branch_id]);
            $prod = $check->fetch();
            if (!$prod || $prod['quantity'] < $item['qty']) {
                throw new Exception("Insufficient stock for \"" . ($prod['name'] ?? $item['name']) . "\". Available: " . ($prod['quantity'] ?? 0) . ", Requested: " . $item['qty']);
            }

            $disc_pct = max(0, min(100, (float)($item['discPct'] ?? 0)));
            $item_size = isset($item['size']) ? sanitize((string)$item['size']) : null;
            $stmt = $pdo->prepare("INSERT INTO bill_items (bill_id, product_id, product_name, size, selling_price, quantity, discount, discount_percent, total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$bill_id, $item['id'], $item['name'], $item_size, $item['price'], $item['qty'], $item['discount'], $disc_pct, $item['total']]);

            // Update Stock
            $stmt = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
            $stmt->execute([$item['qty'], $item['id']]);

            // Log Inventory
            $stmt = $pdo->prepare("INSERT INTO inventory_log (branch_id, product_id, type, quantity, reference_type, reference_id, created_by) VALUES (?, ?, 'out', ?, 'bill', ?, ?)");
            $stmt->execute([$branch_id, $item['id'], $item['qty'], $bill_id, $_SESSION['user_id']]);
        }

        // 5. If the bill includes an alteration, log it to the Alteration module too.
        if ($alteration_required) {
            $alt_type = $alteration_length !== '' ? ('New Length: ' . $alteration_length) : 'Alteration';
            $alt_product = $cart[0]['name'] ?? null;
            $stmt = $pdo->prepare("INSERT INTO alterations (branch_id, customer_id, customer_name, mobile, bill_number, product_name, alteration_type, alteration_charge, alteration_date, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'pending', ?)");
            $stmt->execute([$branch_id, $customer_id, $customer_name, $customer_phone, $bill_number, $alt_product, $alt_type, $alteration_charge, $_SESSION['user_id']]);
        }

        $pdo->commit();

        // If this confirmation came from a held draft, remove the draft now.
        if ($draft_id) {
            $pdo->prepare("DELETE FROM draft_bills WHERE id = ?$draft_scope")->execute([$draft_id]);
        }

        logAudit('create_bill', 'billing', "Bill $bill_number created for ₹$total_amount");
        flashMessage('success', "Bill $bill_number generated successfully.");
        redirect("invoice.php?id=$bill_id");

    } catch (Exception $e) {
        $pdo->rollBack();
        flashMessage('danger', 'Error: ' . $e->getMessage());
        redirect('create.php');
    }
}

// Load a held draft for editing (prefills the cart + fields below).
$prefill = null;
if (isset($_GET['draft'])) {
    $stmt = $pdo->prepare("SELECT * FROM draft_bills WHERE id = ?" . (!isAdmin() ? " AND branch_id = " . (int)$branch_id : ""));
    $stmt->execute([(int)$_GET['draft']]);
    $prefill = $stmt->fetch();
}

$pageTitle = 'Point of Sale';
include_once __DIR__ . '/../../includes/header.php';
?>

<style>
    /* ── POS Layout ─────────────────────────────────────── */
    .pos-wrapper  { display:flex; height:calc(100vh - var(--header-height) - 48px); overflow:hidden; margin:-24px; }
    .pos-cart     { display:flex; flex-direction:column; flex:1; border-right:1.5px solid var(--border); background:var(--surface-variant); overflow:hidden; }
    .pos-cart-top { flex-shrink:0; display:flex; align-items:center; justify-content:space-between; padding:16px 20px; background:var(--surface); border-bottom:1.5px solid var(--border); }
    .pos-cart-body{ flex:1; overflow-y:auto; padding:16px 20px; }
    .pos-right    { width:400px; padding:20px; overflow-y:auto; flex-shrink:0; background:var(--surface); border-left:1.5px solid var(--border); }

    /* ── Cart table ─────────────────────────────────────── */
    .pos-section-title { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:1.2px; color:var(--on-surface-subtle); margin-bottom:10px; display:flex; align-items:center; gap:8px; }
    .pos-cart-table    { width:100%; border-collapse:collapse; }
    .pos-cart-table th { padding:9px 10px; font-size:10px; text-transform:uppercase; letter-spacing:0.6px; font-weight:700; color:var(--on-surface-muted); background:var(--surface); border-bottom:1.5px solid var(--border); text-align:left; }
    .pos-cart-table td { padding:12px 10px; border-bottom:1px solid var(--border); font-size:13px; vertical-align:middle; color:var(--on-surface); background:var(--surface); }
    .pos-cart-table tr:hover td { background:var(--surface-variant); }
    .qty-input  { width:62px; padding:6px 8px; background:var(--surface); border:1.5px solid var(--border); border-radius:6px; color:var(--on-surface); text-align:center; font-size:13px; outline:none; font-family:inherit; transition:border-color .15s; }
    .qty-input:focus  { border-color:var(--primary); }
    .disc-input { width:84px; padding:6px 8px; background:var(--surface); border:1.5px solid var(--border); border-radius:6px; color:var(--on-surface); font-size:13px; outline:none; font-family:inherit; }
    .disc-input:focus { border-color:var(--primary); }
    .disc-row   { display:flex; align-items:center; gap:6px; margin-top:6px; }
    .disc-bands { display:flex; flex-wrap:wrap; gap:4px; margin-top:6px; }
    .disc-band-btn { padding:2px 7px; font-size:10px; font-weight:600; cursor:pointer; border-radius:5px; border:1.5px solid var(--border); background:var(--surface); color:var(--on-surface-muted); transition:all .12s; font-family:inherit; }
    .disc-band-btn:hover { border-color:var(--primary); color:var(--primary); }
    .disc-band-btn.active { background:var(--primary); border-color:var(--primary); color:#fff; }

    /* ── Grand total bar ────────────────────────────────── */
    .pos-grand-bar { flex-shrink:0; display:flex; justify-content:space-between; align-items:center; padding:14px 20px; background:var(--surface); border-top:2px solid var(--primary); }
    .pos-grand-bar-label { font-size:12px; color:var(--on-surface-muted); text-transform:uppercase; letter-spacing:0.5px; }
    .pos-grand-bar-value { font-size:28px; font-weight:800; color:var(--primary); }

    /* ── Right panel totals ─────────────────────────────── */
    .pos-total-row  { display:flex; justify-content:space-between; padding:7px 0; font-size:13px; color:var(--on-surface-muted); border-bottom:1px dashed var(--border); }
    .pos-grand-total{ display:flex; justify-content:space-between; padding:14px 0; font-size:20px; font-weight:700; color:var(--on-surface); border-top:2px solid var(--border); margin-top:8px; }

    /* ── Bill-type toggle ───────────────────────────────── */
    .bill-type-toggle { display:flex; background:var(--surface-variant); border:1.5px solid var(--border); border-radius:var(--radius); overflow:hidden; margin-bottom:16px; padding:4px; gap:4px; }
    .bill-type-toggle label { flex:1; text-align:center; padding:9px; font-size:13px; font-weight:600; cursor:pointer; color:var(--on-surface-muted); transition:all .15s; border-radius:6px; margin-bottom:0; }
    .bill-type-toggle label:hover { background:var(--surface-hover); color:var(--on-surface); }
    .bill-type-toggle input[type=radio] { display:none; }
    .bill-type-toggle input:checked + span { background:var(--primary); color:#fff; display:block; margin:-9px; padding:9px; border-radius:6px; }

    /* ── Product search dropdown ────────────────────────── */
    #searchResults, #creditResults {
        position:absolute; left:0; right:0; top:100%;
        background:var(--surface);
        border:1.5px solid var(--border);
        border-top:none;
        border-radius:0 0 var(--radius-lg) var(--radius-lg);
        z-index:1000;
        box-shadow:var(--shadow-xl);
        max-height:300px;
        overflow-y:auto;
        display:none;
    }
    .search-item { padding:11px 16px; cursor:pointer; border-bottom:1px solid var(--border); transition:background .1s; }
    .search-item:hover { background:var(--surface-variant); }
    .search-item:last-child { border-bottom:none; }
    .search-item .item-name { font-size:13px; font-weight:600; color:var(--on-surface); }
    .search-item .item-meta { font-size:11px; color:var(--on-surface-muted); margin-top:2px; }

    /* ── In-page notice ─────────────────────────────────── */
    #searchNotice { border-radius:var(--radius); padding:9px 14px; font-size:13px; margin-top:8px; display:none; }
</style>

<div class="pos-wrapper">

    <!-- ═══════════════════════════════════ LEFT: CART ═══ -->
    <div class="pos-cart">
        <!-- Cart header -->
        <div class="pos-cart-top">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:34px;height:34px;background:var(--primary-light);border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--primary);">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div>
                    <div style="font-size:15px;font-weight:700;color:var(--on-surface);">Billing Cart</div>
                    <div style="font-size:11px;color:var(--on-surface-subtle);" id="itemCount">0 items in cart</div>
                </div>
                <?php if (!empty($branch_name)): ?>
                <span class="badge-pill" style="background:var(--primary-light);color:var(--primary);font-weight:600;margin-left:4px;" title="You are billing for this branch">
                    <i class="fas fa-store" style="font-size:10px;"></i> Billing as: <?php echo sanitize($branch_name); ?>
                </span>
                <?php endif; ?>
            </div>
            <button type="button" class="btn btn-outline btn-sm" style="color:var(--danger);border-color:var(--danger);" onclick="clearCart()">
                <i class="fas fa-trash-alt"></i> Clear Cart
            </button>
        </div>

        <!-- Cart table (scrollable) -->
        <div class="pos-cart-body">
            <table class="pos-cart-table">
                <thead>
                    <tr>
                        <th style="width:42%">Product</th>
                        <th>Qty</th>
                        <th>Unit Price</th>
                        <th style="text-align:right;">Total</th>
                        <th style="width:32px;"></th>
                    </tr>
                </thead>
                <tbody id="cartBody">
                    <tr id="emptyRow">
                        <td colspan="5" style="padding:56px 20px;text-align:center;">
                            <i class="fas fa-barcode" style="font-size:36px;color:var(--border);display:block;margin-bottom:14px;"></i>
                            <div style="font-size:14px;font-weight:600;color:var(--on-surface-muted);margin-bottom:6px;">Cart is empty</div>
                            <div style="font-size:12px;color:var(--on-surface-subtle);">Scan a barcode or search a product on the right panel →</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Grand Total Bar -->
        <div class="pos-grand-bar">
            <div>
                <div class="pos-grand-bar-label">Grand Total</div>
                <div class="pos-grand-bar-value" id="grandTotalBar">₹0.00</div>
            </div>
            <div style="text-align:right;">
                <div class="pos-grand-bar-label">Items</div>
                <div style="font-size:18px;font-weight:700;color:var(--on-surface);" id="itemCountBar">0</div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════ RIGHT: PANEL ═ -->
    <div class="pos-right">
        <form id="billForm" method="POST">
            <input type="hidden" name="csrf_token"       value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="draft_id"         value="<?php echo $prefill['id'] ?? ''; ?>">
            <input type="hidden" name="cart_json"        id="f_cart_json">
            <input type="hidden" name="subtotal"         id="f_subtotal">
            <input type="hidden" name="discount_amount"  id="f_discount_amount">
            <input type="hidden" name="discount_percent" id="f_discount_percent">
            <input type="hidden" name="gst_amount"       id="f_gst_amount">
            <input type="hidden" name="total_amount"     id="f_total_amount">
            <input type="hidden" name="received_amount"  id="f_received_amount" value="0">

            <!-- ── SEARCH / SCAN ── -->
            <div class="pos-section-title"><i class="fas fa-magnifying-glass"></i> Search / Scan Product</div>
            <div style="position:relative;margin-bottom:16px;">
                <input type="text" id="smartSearch" class="form-control barcode-scan"
                       placeholder="Scan barcode or type product name…" autofocus autocomplete="off"
                       style="font-size:14px;padding:10px 14px;height:44px;">
                <div style="font-size:11px;color:var(--on-surface-subtle);margin-top:5px;">
                    <i class="fas fa-circle-info"></i> Press Enter after barcode scan · Type 2+ chars for search
                </div>
                <div id="searchNotice" class="alert" style="display:none;margin-top:8px;padding:9px 14px;font-size:13px;"></div>
                <div id="searchResults"></div>
            </div>

            <hr style="border:none;border-top:1.5px solid var(--border);margin:16px 0;">

            <!-- ── BILL TYPE ── -->
            <div class="pos-section-title"><i class="fas fa-tag"></i> Payment Type</div>
            <div class="bill-type-toggle">
                <label>
                    <input type="radio" name="bill_type" value="cash" checked onchange="toggleBillType('cash')">
                    <span><i class="fas fa-money-bill-wave"></i> &nbsp;CASH</span>
                </label>
                <label>
                    <input type="radio" name="bill_type" value="online" onchange="toggleBillType('online')">
                    <span><i class="fas fa-wifi"></i> &nbsp;ONLINE</span>
                </label>
                <label>
                    <input type="radio" name="bill_type" value="card" onchange="toggleBillType('card')">
                    <span><i class="fas fa-credit-card"></i> &nbsp;CARD</span>
                </label>
                <label>
                    <input type="radio" name="bill_type" value="credit" onchange="toggleBillType('credit')">
                    <span><i class="fas fa-hourglass-half"></i> &nbsp;CREDIT</span>
                </label>
            </div>

            <!-- ── CUSTOMER ── -->
            <div id="customerSection">
                <div id="creditSearchBox" style="display:none;background:var(--warning-light);border:1.5px solid #FDE68A;border-radius:var(--radius);padding:12px;margin-bottom:12px;">
                    <div class="pos-section-title" style="color:#D97706;margin-bottom:8px;">
                        <i class="fas fa-user-check"></i> Find Existing Credit Customer
                    </div>
                    <div style="position:relative;">
                        <input type="text" id="creditSearch" class="form-control" placeholder="Type name or phone…" autocomplete="off" style="font-size:13px;">
                        <div id="creditResults"></div>
                    </div>
                    <div id="creditOutstanding" style="display:none;margin-top:8px;font-size:12px;font-weight:600;color:#D97706;"></div>
                </div>

                <div class="pos-section-title"><i class="fas fa-user"></i> Customer Details</div>
                <div style="margin-bottom:10px;">
                    <input type="text" id="customerNameInput" name="customer_name" class="form-control"
                           placeholder="Customer name (optional)" style="font-size:13px;">
                </div>
                <div style="margin-bottom:10px;">
                    <input type="text" id="customerPhoneInput" name="customer_phone" class="form-control"
                           placeholder="Phone number (optional)" style="font-size:13px;">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
                    <div>
                        <label style="font-size:11px;font-weight:600;color:var(--on-surface-muted);display:block;margin-bottom:4px;">Date of Birth</label>
                        <input type="date" id="customerDobInput" name="customer_dob" class="form-control" style="font-size:13px;">
                        <div style="font-size:10px;color:var(--on-surface-subtle);margin-top:3px;"><i class="fas fa-circle-info"></i> Saved to customer profile (needs phone)</div>
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:600;color:var(--on-surface-muted);display:block;margin-bottom:4px;">Employee Name</label>
                        <input type="text" id="employeeNameInput" name="employee_name" class="form-control"
                               placeholder="Salesperson (optional)" style="font-size:13px;">
                    </div>
                </div>
                <div id="collectorNameSection" style="display:none;margin-bottom:14px;">
                    <input type="text" id="collectorNameInput" name="collector_name" class="form-control"
                           placeholder="Goods collector name (if different from account holder)" style="font-size:13px;">
                    <div style="font-size:11px;color:var(--on-surface-subtle);margin-top:4px;"><i class="fas fa-circle-info"></i> Who is physically collecting the goods?</div>
                </div>
            </div>

            <hr style="border:none;border-top:1.5px solid var(--border);margin:16px 0;">

            <!-- ── ALTERATION ── -->
            <div class="pos-section-title"><i class="fas fa-scissors"></i> Alteration</div>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;margin-bottom:6px;">
                <input type="checkbox" name="alteration_required" id="altRequired" value="1" onchange="toggleAlteration()"> Alteration required
            </label>
            <div id="altFields" style="display:none;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
                <div>
                    <label style="font-size:11px;font-weight:600;color:var(--on-surface-muted);display:block;margin-bottom:4px;">New Length</label>
                    <input type="text" name="alteration_length" id="altLength" class="form-control" placeholder="e.g. 40" style="font-size:13px;">
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;color:var(--on-surface-muted);display:block;margin-bottom:4px;">Charge (₹)</label>
                    <input type="number" name="alteration_charge" id="altCharge" class="form-control" value="0" min="0" step="0.01" oninput="recalculate()" style="font-size:13px;">
                </div>
            </div>

            <hr style="border:none;border-top:1.5px solid var(--border);margin:16px 0;">

            <!-- ── BILL SUMMARY ── -->
            <div class="pos-section-title"><i class="fas fa-calculator"></i> Bill Summary</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
                <div>
                    <label style="font-size:11px;font-weight:600;color:var(--on-surface-muted);display:block;margin-bottom:4px;">GST %</label>
                    <select name="gst_percent" id="gstSelect" class="form-control" onchange="recalculate()" style="font-size:13px;">
                        <option value="0" selected>0% (No GST)</option>
                        <option value="5">5%</option>
                        <option value="12">12%</option>
                        <option value="18">18%</option>
                        <option value="28">28%</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;color:var(--on-surface-muted);display:block;margin-bottom:4px;">Bill Discount (₹)</label>
                    <input type="number" id="billDiscount" name="bill_discount" class="form-control"
                           value="0" min="0" step="0.01" oninput="recalculate()" style="font-size:13px;">
                </div>
            </div>

            <div style="background:var(--surface-variant);border-radius:var(--radius);padding:14px;margin-bottom:14px;">
                <div class="pos-total-row"><span>Subtotal</span><span id="subtotalVal" style="font-weight:500;color:var(--on-surface);">₹0.00</span></div>
                <div class="pos-total-row"><span>Discount</span><span id="discountVal" style="color:var(--danger);font-weight:500;">-₹0.00</span></div>
                <div class="pos-total-row" style="border-bottom:none;"><span>GST</span><span id="gstVal" style="font-weight:500;color:var(--on-surface);">₹0.00</span></div>
                <div class="pos-total-row" id="altSummaryRow" style="display:none;"><span>Alteration</span><span id="altVal" style="font-weight:500;color:var(--primary);">₹0.00</span></div>
                <div class="pos-grand-total" style="margin-top:10px;padding-top:10px;">
                    <span>Grand Total</span>
                    <span id="grandTotalVal" style="color:var(--primary);">₹0.00</span>
                </div>
            </div>

            <!-- ── RECEIVED AMOUNT (cash) ── -->
            <div id="receivedFields" style="margin-bottom:16px;">
                <label style="font-size:11px;font-weight:600;color:var(--on-surface-muted);display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px;">Received Amount (₹)</label>
                <input type="number" id="receivedAmt" class="form-control"
                       value="0" min="0" step="0.01" oninput="updateChange()"
                       style="font-size:18px;font-weight:700;text-align:right;height:48px;color:var(--on-surface);">
                <div style="display:flex;justify-content:space-between;margin-top:8px;font-size:13px;">
                    <span style="color:var(--on-surface-muted);">Change / Return:</span>
                    <span id="changeAmt" style="font-weight:700;color:var(--success);">₹0.00</span>
                </div>
            </div>

            <!-- ── CREDIT NOTE ── -->
            <div id="creditNote" style="display:none;background:var(--warning-light);border:1.5px solid #FDE68A;border-radius:var(--radius);padding:12px;margin-bottom:16px;font-size:13px;color:#92400E;">
                <i class="fas fa-info-circle" style="margin-right:6px;"></i>
                Credit sale — customer pays later. Record payment via <strong>Credit Customers</strong> module.
            </div>

            <!-- ── DRAFT / GENERATE ── -->
            <div style="display:flex;gap:10px;">
                <button type="submit" name="save_mode" value="draft" class="btn btn-outline" style="flex:0 0 40%;height:50px;font-size:14px;font-weight:600;border-radius:var(--radius);">
                    <i class="fas fa-floppy-disk"></i> Save Draft
                </button>
                <button type="submit" name="save_mode" value="confirm" class="btn btn-primary" style="flex:1;height:50px;font-size:15px;font-weight:700;letter-spacing:0.3px;border-radius:var(--radius);">
                    <i class="fas fa-file-invoice"></i> Generate Bill
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let cart = [];

/* ─── Cart operations ───────────────────────────────────── */

function addToCart(product) {
    const stock = parseInt(product.quantity) || 0;
    const existing = cart.find(i => i.id == product.id);
    const currentQty = existing ? existing.qty : 0;

    if (stock > 0 && currentQty + 1 > stock) {
        showNotice('Not enough stock for "' + product.name + '". Available: ' + stock + ', already in cart: ' + currentQty, 'danger');
        return;
    }

    if (existing) {
        existing.qty++;
        existing.total = (existing.price * existing.qty) - existing.discount;
    } else {
        cart.push({
            id: product.id,
            name: product.name,
            size: product.size || '',
            price: parseFloat(product.selling_price),
            qty: 1,
            discount: 0,
            discPct: 0,
            total: parseFloat(product.selling_price),
            stock: stock,
            showDisc: false
        });
    }
    renderCart();
    recalculate();
    showNotice('"' + product.name + '" added to cart.', 'success');
}

function removeFromCart(idx) {
    cart.splice(idx, 1);
    renderCart();
    recalculate();
}

function updateQty(idx, val) {
    val = parseInt(val) || 1;
    if (val < 1) { removeFromCart(idx); return; }
    const stock = cart[idx].stock || 0;
    if (stock > 0 && val > stock) {
        showNotice('Max available stock is ' + stock + ' for "' + cart[idx].name + '"', 'danger');
        val = stock;
    }
    cart[idx].qty = val;
    applyLineDiscount(idx);   // ₹ off follows the % when qty changes
    renderCart();
    recalculate();
}

/* Recompute a line's ₹ discount + total from its discount % */
function applyLineDiscount(idx) {
    const it = cart[idx];
    const gross = it.price * it.qty;
    const pct = Math.max(0, Math.min(100, parseFloat(it.discPct) || 0));
    it.discPct = pct;
    it.discount = gross * pct / 100;
    it.total = Math.max(0, gross - it.discount);
}

/* Per-line discount % — accepts any custom value; band chips just fill this */
function updateDiscountPct(idx, val) {
    cart[idx].discPct = Math.max(0, Math.min(100, parseFloat(val) || 0));
    applyLineDiscount(idx);
    renderCart();
    recalculate();
}

function toggleDiscRow(idx) {
    cart[idx].showDisc = !cart[idx].showDisc;
    renderCart();
}

function clearCart() {
    if (!cart.length) return;
    if (confirm('Clear entire cart?')) { cart = []; renderCart(); recalculate(); }
}

function renderCart() {
    const tb      = document.getElementById('cartBody');
    const badge   = document.getElementById('itemCount');
    const badgeBar = document.getElementById('itemCountBar');
    const total   = cart.reduce((s, i) => s + i.qty, 0);
    badge.textContent    = cart.length + ' Items';
    badgeBar.textContent = total;

    if (!cart.length) {
        tb.innerHTML = `<tr id="emptyRow">
            <td colspan="5" style="padding:56px 20px;text-align:center;">
                <i class="fas fa-barcode" style="font-size:36px;color:var(--border);display:block;margin-bottom:14px;"></i>
                <div style="font-size:14px;font-weight:600;color:var(--on-surface-muted);margin-bottom:6px;">Cart is empty</div>
                <div style="font-size:12px;color:var(--on-surface-subtle);">Scan a barcode or search a product on the right panel →</div>
            </td></tr>`;
        return;
    }

    tb.innerHTML = cart.map((item, i) => `
        <tr>
            <td>
                <div style="font-weight:600;color:var(--on-surface);font-size:13px;">${item.name}${item.size ? ` <span style="font-weight:500;color:var(--on-surface-muted);font-size:11px;">(${item.size})</span>` : ''}</div>
                ${item.showDisc ? `
                <div class="disc-row">
                    <span style="font-size:11px;color:var(--on-surface-subtle);">Disc %:</span>
                    <input class="disc-input" type="number" value="${item.discPct || 0}"
                           min="0" max="100" step="0.01" style="width:64px;"
                           oninput="updateDiscountPct(${i}, this.value)">
                    ${item.discount > 0 ? `<span style="font-size:11px;color:var(--success);font-weight:600;">-₹${item.discount.toFixed(2)}</span>` : ''}
                </div>
                <div class="disc-bands">
                    ${[15,20,25,30,35,40,45,50].map(p => `<button type="button" class="disc-band-btn${(parseFloat(item.discPct)||0)==p?' active':''}" onclick="updateDiscountPct(${i}, ${p})">${p}%</button>`).join('')}
                </div>` : ''}
            </td>
            <td>
                <input class="qty-input" type="number" value="${item.qty}" min="1"
                       ${item.stock > 0 ? `max="${item.stock}"` : ''}
                       oninput="updateQty(${i}, this.value)">
                ${item.stock > 0 ? `<div style="font-size:10px;color:var(--on-surface-subtle);margin-top:3px;">Stock: ${item.stock}</div>` : ''}
            </td>
            <td style="font-weight:500;">₹${item.price.toFixed(2)}</td>
            <td style="text-align:right;font-weight:700;">₹${item.total.toFixed(2)}
                <div>
                    <button type="button" onclick="toggleDiscRow(${i})"
                            style="background:none;border:none;cursor:pointer;font-size:10px;color:var(--on-surface-subtle);padding:2px 4px;" title="Item discount">
                        <i class="fas fa-tag"></i> disc
                    </button>
                </div>
            </td>
            <td style="text-align:right;">
                <button type="button" onclick="removeFromCart(${i})"
                        style="background:none;border:none;cursor:pointer;font-size:18px;color:var(--on-surface-subtle);padding:4px;line-height:1;transition:color .15s;"
                        onmouseover="this.style.color='var(--danger)'" onmouseout="this.style.color='var(--on-surface-subtle)'">×</button>
            </td>
        </tr>`).join('');
}

/* ─── Calculation ───────────────────────────────────────── */

function toggleAlteration() {
    const on = document.getElementById('altRequired').checked;
    document.getElementById('altFields').style.display = on ? 'grid' : 'none';
    if (!on) { document.getElementById('altCharge').value = 0; document.getElementById('altLength').value = ''; }
    recalculate();
}

function recalculate() {
    const subtotal   = cart.reduce((s, i) => s + i.total, 0);
    const gstPct     = parseFloat(document.getElementById('gstSelect').value) || 0;
    const billDisc   = Math.min(parseFloat(document.getElementById('billDiscount').value) || 0, subtotal);
    const billDiscPct = subtotal > 0 ? (billDisc / subtotal * 100) : 0;
    const gst        = (subtotal - billDisc) * gstPct / 100;
    const altOn      = document.getElementById('altRequired').checked;
    const altCharge  = altOn ? (parseFloat(document.getElementById('altCharge').value) || 0) : 0;
    const grandTotal = subtotal - billDisc + gst + altCharge;

    document.getElementById('altSummaryRow').style.display = altCharge > 0 ? 'flex' : 'none';
    document.getElementById('altVal').textContent        = '+₹' + altCharge.toFixed(2);
    document.getElementById('subtotalVal').textContent  = '₹' + subtotal.toFixed(2);
    document.getElementById('discountVal').textContent  = '-₹' + billDisc.toFixed(2);
    document.getElementById('gstVal').textContent       = '₹' + gst.toFixed(2);
    document.getElementById('grandTotalVal').textContent = '₹' + grandTotal.toFixed(2);
    document.getElementById('grandTotalBar').textContent = '₹' + grandTotal.toFixed(2);

    document.getElementById('f_subtotal').value        = subtotal.toFixed(2);
    document.getElementById('f_discount_amount').value = billDisc.toFixed(2);
    document.getElementById('f_discount_percent').value = billDiscPct.toFixed(2);
    document.getElementById('f_gst_amount').value      = gst.toFixed(2);
    document.getElementById('f_total_amount').value    = grandTotal.toFixed(2);

    updateChange();
}

function updateChange() {
    const total = parseFloat(document.getElementById('f_total_amount').value) || 0;
    const recv  = parseFloat(document.getElementById('receivedAmt').value) || 0;
    const change = recv - total;
    const el    = document.getElementById('changeAmt');
    el.textContent = change >= 0 ? '₹' + change.toFixed(2) : '-₹' + Math.abs(change).toFixed(2);
    el.style.color = change >= 0 ? 'var(--success)' : 'var(--danger)';
    document.getElementById('f_received_amount').value = recv.toFixed(2);
}

/* ─── Bill type toggle ──────────────────────────────────── */

function toggleBillType(type) {
    const nameInput   = document.getElementById('customerNameInput');
    const phoneInput  = document.getElementById('customerPhoneInput');
    const searchBox   = document.getElementById('creditSearchBox');
    const creditNote  = document.getElementById('creditNote');
    const recvFields  = document.getElementById('receivedFields');
    const custSection = document.getElementById('customerSection');

    const collectorSection = document.getElementById('collectorNameSection');
    const isCreditMode = (type === 'credit');

    nameInput.required  = isCreditMode;
    phoneInput.required = isCreditMode;
    nameInput.placeholder  = isCreditMode ? 'Customer name (required)' : 'Customer name (optional)';
    phoneInput.placeholder = isCreditMode ? 'Phone number (required)'  : 'Phone number (optional)';

    custSection.style.border       = isCreditMode ? '1px solid var(--warning)' : '';
    custSection.style.borderRadius = isCreditMode ? 'var(--radius)' : '';
    custSection.style.padding      = isCreditMode ? '12px' : '';

    searchBox.style.display       = isCreditMode ? 'block' : 'none';
    creditNote.style.display      = isCreditMode ? 'block' : 'none';
    recvFields.style.display      = isCreditMode ? 'none'  : 'block';
    collectorSection.style.display = isCreditMode ? 'block' : 'none';

    if (!isCreditMode) {
        document.getElementById('creditResults').style.display     = 'none';
        document.getElementById('creditOutstanding').style.display = 'none';
        document.getElementById('collectorNameInput').value = '';
    }
}

/* ─── Credit customer autocomplete ─────────────────────── */

let creditTimeout;
document.getElementById('creditSearch').addEventListener('input', function() {
    clearTimeout(creditTimeout);
    const q       = this.value.trim();
    const results = document.getElementById('creditResults');
    if (q.length < 2) { results.style.display = 'none'; return; }

    creditTimeout = setTimeout(() => {
        fetch('<?php echo BASE_URL; ?>/modules/customers/get.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                if (!data || !data.length) { results.style.display = 'none'; return; }
                results.innerHTML = data.map(c => {
                    const ref = c.reference_name
                        ? (c.reference_name + (c.reference_relation ? ' (' + c.reference_relation + ')' : ''))
                        : '';
                    const json = JSON.stringify(c).replace(/'/g, "\\'");
                    return `
                    <div class="search-item" onclick='selectCreditCustomer(${json})'>
                        <div class="fw-600">${c.name}</div>
                        <div class="fs-11 text-muted">${c.phone}
                            ${parseFloat(c.outstanding) > 0
                                ? ' &nbsp;|&nbsp; <span style="color:var(--warning);font-weight:600;">Due: ₹' + parseFloat(c.outstanding).toFixed(2) + '</span>'
                                : ''}
                        </div>
                        ${ref ? `<div class="fs-11" style="color:var(--on-surface-subtle);margin-top:2px;"><i class="fas fa-user-group" style="font-size:9px;margin-right:3px;"></i>${ref}</div>` : ''}
                        ${c.address ? `<div class="fs-11" style="color:var(--on-surface-subtle);"><i class="fas fa-location-dot" style="font-size:9px;margin-right:3px;"></i>${c.address}</div>` : ''}
                    </div>`;
                }).join('');
                results.style.display = 'block';
            });
    }, 300);
});

function selectCreditCustomer(c) {
    document.getElementById('customerNameInput').value  = c.name;
    document.getElementById('customerPhoneInput').value = c.phone;
    document.getElementById('creditResults').style.display = 'none';
    document.getElementById('creditSearch').value = c.name + '  (' + c.phone + ')';
    const outstanding = parseFloat(c.outstanding) || 0;
    const outEl = document.getElementById('creditOutstanding');
    if (outstanding > 0) {
        outEl.innerHTML = '<i class="fas fa-triangle-exclamation me-1"></i> This customer already owes ₹' + outstanding.toFixed(2);
        outEl.style.display = 'block';
    } else {
        outEl.style.display = 'none';
    }
}

document.addEventListener('click', e => {
    if (!e.target.closest('#creditSearch') && !e.target.closest('#creditResults'))
        document.getElementById('creditResults').style.display = 'none';
});

/* ─── Smart unified search ──────────────────────────────── */

let searchTimeout;
const smartSearch = document.getElementById('smartSearch');

// On Enter: barcode (all-digit 8+) or text search
smartSearch.addEventListener('keydown', e => {
    if (e.key !== 'Enter') return;
    const val = e.target.value.trim();
    if (!val) return;
    e.preventDefault();

    if (/^\d{8,}$/.test(val)) {
        // Barcode scan
        fetch('search_product.php?branch_id=<?php echo (int)$branch_id; ?>&barcode=' + encodeURIComponent(val))
            .then(r => r.json())
            .then(p => {
                if (p && p.id) { addToCart(p); }
                else { showNotice('No product found for barcode: ' + val, 'danger'); }
            });
    } else {
        // Text Enter — pick first result
        doSearch(val, true);
    }
    e.target.value = '';
    document.getElementById('searchResults').style.display = 'none';
});

// On typing: debounced text search dropdown
smartSearch.addEventListener('input', e => {
    clearTimeout(searchTimeout);
    const val = e.target.value.trim();
    if (val.length < 2) { document.getElementById('searchResults').style.display = 'none'; return; }
    searchTimeout = setTimeout(() => doSearch(val, false), 300);
});

function doSearch(q, addFirst) {
    fetch('search_product.php?branch_id=<?php echo (int)$branch_id; ?>&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
            const results = document.getElementById('searchResults');
            if (!data || !data.length) {
                if (addFirst) showNotice('No product found for "' + q + '"', 'danger');
                results.style.display = 'none';
                return;
            }
            if (addFirst) {
                addToCart(data[0]);
                smartSearch.value = '';
                results.style.display = 'none';
                return;
            }
            results.innerHTML = data.map(p => `
                <div class="search-item" onclick='addToCart(${JSON.stringify(p)}); document.getElementById("searchResults").style.display="none"; document.getElementById("smartSearch").value="";'>
                    <div class="fw-600">${p.name}</div>
                    <div class="fs-11 text-muted">SKU: ${p.sku} &nbsp;·&nbsp; ₹${parseFloat(p.selling_price).toFixed(2)} &nbsp;·&nbsp; Stock: ${p.quantity} ${p.unit || ''}</div>
                </div>`).join('');
            results.style.display = 'block';
        });
}

document.addEventListener('click', e => {
    if (!e.target.closest('#smartSearch') && !e.target.closest('#searchResults'))
        document.getElementById('searchResults').style.display = 'none';
});

/* ─── In-page notice (replaces alert) ──────────────────── */

let noticeTimer;
function showNotice(msg, type) {
    const el = document.getElementById('searchNotice');
    el.textContent = msg;
    el.className   = 'alert alert-' + (type || 'danger');
    el.style.display = 'flex';
    clearTimeout(noticeTimer);
    noticeTimer = setTimeout(() => { el.style.display = 'none'; }, 3000);
}

/* ─── Form submit ───────────────────────────────────────── */

document.getElementById('billForm').addEventListener('submit', e => {
    document.getElementById('f_cart_json').value = JSON.stringify(cart);

    if (!cart.length) {
        e.preventDefault();
        showNotice('Cart is empty. Add products before generating a bill.', 'danger');
        return;
    }

    // Draft save skips the cash-received check.
    const mode = (e.submitter && e.submitter.value) || 'confirm';
    if (mode === 'draft') return;

    // Warn if cash received < total (but don't block)
    const billType = document.querySelector('input[name="bill_type"]:checked').value;
    if (billType === 'cash') {
        const total = parseFloat(document.getElementById('f_total_amount').value) || 0;
        const recv  = parseFloat(document.getElementById('receivedAmt').value) || 0;
        if (total > 0 && recv < total) {
            if (!confirm('Received amount ₹' + recv.toFixed(2) + ' is less than total ₹' + total.toFixed(2) + '. Proceed anyway?')) {
                e.preventDefault();
                return;
            }
        }
    }
});

<?php if ($prefill): ?>
/* ─── Prefill from a held draft ─────────────────────────── */
(function () {
    cart = <?php echo json_encode(json_decode($prefill['cart_json']) ?: []); ?>;
    renderCart();
    document.getElementById('customerNameInput').value  = <?php echo json_encode($prefill['customer_name'] ?? ''); ?>;
    document.getElementById('customerPhoneInput').value = <?php echo json_encode($prefill['customer_phone'] ?? ''); ?>;
    document.getElementById('customerDobInput').value   = <?php echo json_encode($prefill['customer_dob'] ?? ''); ?>;
    document.getElementById('employeeNameInput').value  = <?php echo json_encode($prefill['employee_name'] ?? ''); ?>;
    var bt  = <?php echo json_encode($prefill['bill_type'] ?? 'cash'); ?>;
    var btr = document.querySelector('input[name="bill_type"][value="' + bt + '"]');
    if (btr) { btr.checked = true; toggleBillType(bt); }
    document.getElementById('gstSelect').value    = <?php echo json_encode((string)(int)$prefill['gst_percent']); ?>;
    document.getElementById('billDiscount').value = <?php echo json_encode((string)$prefill['discount_amount']); ?>;
    if (<?php echo !empty($prefill['alteration_required']) ? 'true' : 'false'; ?>) {
        document.getElementById('altRequired').checked = true;
        document.getElementById('altLength').value = <?php echo json_encode($prefill['alteration_length'] ?? ''); ?>;
        document.getElementById('altCharge').value = <?php echo json_encode((string)$prefill['alteration_charge']); ?>;
    }
    toggleAlteration();
    recalculate();
    showNotice('Editing held draft — review and Generate Bill to confirm.', 'success');
})();
<?php endif; ?>
</script>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
