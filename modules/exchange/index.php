<?php
/**
 * Returns — unified Exchange / Defective Replacement.
 * Mobile → previous bills → pick item → two-panel. A mode toggle switches between:
 *   - Exchange: new value >= return value; pay difference (cash/online/card).
 *   - Defective Replacement: replacement value >= original; defect reason; no payment;
 *     defective stock +1, normal -1; sales unchanged.
 * Posts to the existing commit endpoints (commit.php / ../defective/commit.php).
 * Cashier-accessible.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle  = 'Exchange / Replacement';
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Exchange / Replacement</span>';

$isAdmin   = isAdmin();
$branch_id = (int)($_SESSION['branch_id'] ?? 0);

$mode_default = ($_GET['mode'] ?? 'exchange') === 'defective' ? 'defective' : 'exchange';
$mobile   = trim($_GET['mobile'] ?? '');
$searched = ($mobile !== '');
$bills = [];
$itemsByBill = [];

if ($searched) {
    $scope = $isAdmin ? '' : ' AND branch_id = ' . $branch_id;
    $stmt = $pdo->prepare("SELECT id, branch_id, bill_number, created_at, total_amount, customer_name
                           FROM bills WHERE customer_phone = ? AND status != 'cancelled' $scope
                           ORDER BY created_at DESC");
    $stmt->execute([$mobile]);
    $bills = $stmt->fetchAll();
    $billBranch = array_column($bills, 'branch_id', 'id');

    if ($bills) {
        $ids = array_column($bills, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT bi.id, bi.bill_id, bi.product_id, bi.product_name, bi.selling_price, bi.quantity,
                                      bi.discount_percent, bi.total, COALESCE(bi.size, p.size) AS size, c.name AS category
                               FROM bill_items bi
                               LEFT JOIN products p ON bi.product_id = p.id
                               LEFT JOIN categories c ON p.category_id = c.id
                               WHERE bi.bill_id IN ($ph)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $it) {
            $qty   = max(1, (int)$it['quantity']);
            $final = round((float)$it['total'] / $qty, 2);
            $disc  = (float)$it['discount_percent'];
            if ($disc <= 0 && (float)$it['selling_price'] > 0) {
                $disc = round((1 - ($final / (float)$it['selling_price'])) * 100, 2);
                if ($disc < 0) $disc = 0;
            }
            $itemsByBill[$it['bill_id']][] = [
                'item_id'      => (int)$it['id'],
                'product_id'   => $it['product_id'],
                'product_name' => $it['product_name'],
                'category'     => $it['category'] ?: '—',
                'size'         => $it['size'] ?: '—',
                'qty'          => $qty,
                'mrp'          => (float)$it['selling_price'],
                'disc'         => $disc,
                'final'        => $final,
                'branch_id'    => (int)($billBranch[$it['bill_id']] ?? 0),
            ];
        }
    }
}

$csrf_token = generateCSRFToken();
include_once __DIR__ . '/../../includes/header.php';
?>

<style>
    .mode-toggle { display:flex; background:var(--surface-variant); border:1.5px solid var(--border); border-radius:var(--radius); overflow:hidden; padding:4px; gap:4px; max-width:480px; }
    .mode-toggle button { flex:1; padding:10px; font-size:13px; font-weight:600; cursor:pointer; color:var(--on-surface-muted); border:none; background:none; border-radius:6px; font-family:inherit; transition:all .15s; }
    .mode-toggle button:hover { color:var(--on-surface); }
    .mode-toggle button.active { background:var(--primary); color:#fff; }
</style>

<div class="page-header">
    <h1>Exchange / Replacement</h1>
</div>

<!-- Mode choice -->
<div class="mode-toggle mb-4">
    <button type="button" id="modeExchange" onclick="setMode('exchange')"><i class="fas fa-right-left"></i> &nbsp;Exchange</button>
    <button type="button" id="modeDefective" onclick="setMode('defective')"><i class="fas fa-triangle-exclamation"></i> &nbsp;Defective Replacement</button>
</div>

<div class="table-wrapper mb-4">
    <form action="" method="GET" style="display:flex;gap:10px;flex-wrap:wrap;padding:6px;">
        <input type="hidden" name="mode" id="modeField" value="<?php echo $mode_default; ?>">
        <div style="position:relative;flex:1;min-width:220px;max-width:360px;">
            <i class="fas fa-mobile-screen-button" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--on-surface-subtle);font-size:13px;pointer-events:none;"></i>
            <input type="text" name="mobile" class="form-control" placeholder="Enter customer mobile number…"
                   value="<?php echo sanitize($mobile); ?>" autofocus autocomplete="off" style="padding-left:36px;height:44px;">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-magnifying-glass"></i> Find Bills</button>
        <?php if ($searched): ?><a href="index.php" class="btn btn-ghost">Clear</a><?php endif; ?>
    </form>
</div>

<?php if ($searched && empty($bills)): ?>
    <div class="table-wrapper"><div class="empty-state" style="padding:50px;text-align:center;">
        <i class="fas fa-receipt empty-icon" style="font-size:34px;color:var(--border);display:block;margin-bottom:12px;"></i>
        <h3>No bills found</h3><p>No bills for <strong><?php echo sanitize($mobile); ?></strong>.</p>
    </div></div>
<?php elseif ($searched): ?>

<div style="display:grid;grid-template-columns:1fr 1.3fr;gap:20px;align-items:start;">
    <!-- Bills + items -->
    <div class="table-wrapper">
        <div style="padding:14px 16px;border-bottom:1.5px solid var(--border);font-weight:700;">Previous Bills — pick an item</div>
        <div style="max-height:70vh;overflow-y:auto;">
            <?php foreach ($bills as $b): ?>
                <div style="padding:12px 16px;border-bottom:1px solid var(--border);">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <div class="fw-600"><?php echo sanitize($b['bill_number']); ?></div>
                        <div class="fs-12 text-muted"><?php echo formatDateTime($b['created_at']); ?></div>
                    </div>
                    <?php foreach (($itemsByBill[$b['id']] ?? []) as $it): ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:6px 0;">
                            <div class="fs-13">
                                <span class="fw-600"><?php echo sanitize($it['product_name']); ?></span>
                                <span class="text-muted">· <?php echo sanitize($it['category']); ?> · Size <?php echo sanitize($it['size']); ?> · <?php echo formatCurrency($it['final']); ?></span>
                            </div>
                            <button type="button" class="btn btn-outline btn-sm" onclick='selectItem(<?php echo (int)$b['id']; ?>, <?php echo $it['item_id']; ?>)'>Select</button>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($itemsByBill[$b['id']])): ?><div class="fs-12 text-muted">No items.</div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Two-panel -->
    <div id="panel" class="table-wrapper" style="display:none;padding:18px;">
        <form action="commit.php" method="POST" id="returnForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="original_bill_item_id" id="f_item_id">
            <input type="hidden" name="new_product_id" id="f_new_pid">
            <input type="hidden" name="replacement_product_id" id="f_repl_pid">
            <input type="hidden" name="new_discount_percent" id="f_new_disc">
            <input type="hidden" name="replacement_discount_percent" id="f_repl_disc">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <!-- OLD / DEFECTIVE -->
                <div id="oldPanel" style="border:1.5px solid var(--border);border-radius:10px;padding:14px;background:var(--surface-variant);">
                    <div class="pos-section-title" id="oldTitle" style="margin-bottom:10px;"><i class="fas fa-rotate-left"></i> Old Product (Return)</div>
                    <div id="oldName" class="fw-600 fs-15 mb-2">—</div>
                    <table style="width:100%;font-size:13px;">
                        <tr><td class="text-muted">Category</td><td class="text-end" id="oldCat">—</td></tr>
                        <tr><td class="text-muted">Size</td><td class="text-end" id="oldSize">—</td></tr>
                        <tr><td class="text-muted" id="lblMrp">MRP</td><td class="text-end" id="oldMrp">—</td></tr>
                        <tr><td class="text-muted" id="lblDisc">Discount %</td><td class="text-end" id="oldDisc">—</td></tr>
                        <tr><td class="fw-600" id="lblFinal">Final Price</td><td class="text-end fw-700" id="oldFinal">—</td></tr>
                        <tr id="returnRow"><td class="fw-600" style="color:var(--primary);">Return Value</td><td class="text-end fw-700" id="oldReturn" style="color:var(--primary);">—</td></tr>
                    </table>
                    <div id="reasonWrap" style="margin-top:10px;display:none;">
                        <label class="fs-12 fw-600 text-muted">Defect Reason <span style="color:var(--danger);">*</span></label>
                        <input type="text" name="defect_reason" id="defectReason" class="form-control" placeholder="e.g. broken zip, color fade…" style="font-size:13px;margin-top:4px;" oninput="recompute()">
                    </div>
                </div>

                <!-- NEW / REPLACEMENT -->
                <div style="border:1.5px solid var(--primary);border-radius:10px;padding:14px;">
                    <div class="pos-section-title" id="newTitle" style="margin-bottom:10px;"><i class="fas fa-tag"></i> New Product</div>
                    <div style="position:relative;margin-bottom:10px;">
                        <input type="text" id="newSearch" class="form-control" placeholder="Search product…" autocomplete="off" style="font-size:13px;">
                        <div id="newResults" style="position:absolute;left:0;right:0;top:100%;background:var(--surface);border:1.5px solid var(--border);border-top:none;border-radius:0 0 8px 8px;z-index:50;box-shadow:var(--shadow-lg);max-height:240px;overflow-y:auto;display:none;"></div>
                    </div>
                    <div id="newDetails" style="display:none;">
                        <div id="newName" class="fw-600 fs-15 mb-2">—</div>
                        <table style="width:100%;font-size:13px;">
                            <tr><td class="text-muted">Category</td><td class="text-end" id="newCat">—</td></tr>
                            <tr><td class="text-muted">Size</td><td class="text-end" id="newSize">—</td></tr>
                            <tr><td class="text-muted">Price</td><td class="text-end" id="newMrp">—</td></tr>
                            <tr><td class="text-muted">Stock</td><td class="text-end" id="newStock">—</td></tr>
                            <tr>
                                <td class="text-muted">Discount %</td>
                                <td class="text-end"><input type="number" id="discInput" class="form-control" value="0" min="0" max="100" step="0.01" style="width:90px;display:inline-block;text-align:right;font-size:13px;" oninput="onDisc()"></td>
                            </tr>
                            <tr><td class="fw-600" style="color:var(--primary);">Final Price</td><td class="text-end fw-700" id="newFinal" style="color:var(--primary);">—</td></tr>
                        </table>
                    </div>
                </div>
            </div>

            <div id="resultBox" style="margin-top:16px;padding:14px;border-radius:10px;background:var(--surface-variant);display:none;">
                <div id="diffLine" style="display:flex;justify-content:space-between;align-items:center;font-size:15px;">
                    <span class="fw-600">Difference</span><span class="fw-700" id="diffVal">₹0.00</span>
                </div>
                <div id="resultMsg" style="font-size:12px;margin-top:6px;"></div>
                <div id="paymentRow" style="margin-top:12px;display:none;">
                    <label class="fs-12 fw-600 text-muted">Customer pays difference via</label>
                    <select name="payment_mode" id="paymentMode" class="form-control" style="font-size:13px;margin-top:4px;">
                        <option value="cash">Cash</option><option value="online">Online</option><option value="card">Card</option>
                    </select>
                </div>
            </div>

            <div class="d-flex justify-end gap-2 mt-4">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('panel').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary" id="submitBtn" disabled><i class="fas fa-check"></i> <span id="submitLabel">Confirm Exchange</span></button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<script>
const BILLS = <?php echo json_encode($itemsByBill); ?>;
let OLD = null, NEW = null, MODE = '<?php echo $mode_default; ?>';
const fmt = v => '₹' + (parseFloat(v)||0).toFixed(2);

function setMode(m) {
    MODE = m;
    document.getElementById('modeField').value = m;
    document.getElementById('modeExchange').classList.toggle('active', m === 'exchange');
    document.getElementById('modeDefective').classList.toggle('active', m === 'defective');

    const form = document.getElementById('returnForm');
    if (form) form.action = (m === 'exchange') ? 'commit.php' : '../defective/commit.php';

    // Relabel panels
    setText('oldTitle', m === 'exchange' ? '<i class="fas fa-rotate-left"></i> Old Product (Return)' : '<i class="fas fa-triangle-exclamation"></i> Defective Product', true);
    setText('newTitle', m === 'exchange' ? '<i class="fas fa-tag"></i> New Product' : '<i class="fas fa-tag"></i> Replacement Product', true);
    setText('lblMrp',  m === 'exchange' ? 'MRP' : 'Original Price');
    setText('lblDisc', m === 'exchange' ? 'Discount %' : 'Original Discount');
    setText('lblFinal',m === 'exchange' ? 'Final Price' : 'Final Sale Price');
    const sl = document.getElementById('submitLabel'); if (sl) sl.textContent = m === 'exchange' ? 'Confirm Exchange' : 'Confirm Replacement';

    const rRow = document.getElementById('returnRow'); if (rRow) rRow.style.display = m === 'exchange' ? '' : 'none';
    const rw = document.getElementById('reasonWrap'); if (rw) rw.style.display = m === 'defective' ? 'block' : 'none';
    const dl = document.getElementById('diffLine'); if (dl) dl.style.display = m === 'exchange' ? 'flex' : 'none';

    if (OLD && NEW) recompute();
}
function setText(id, val, html) { const el = document.getElementById(id); if (!el) return; if (html) el.innerHTML = val; else el.textContent = val; }

function selectItem(billId, itemId) {
    const items = BILLS[billId] || [];
    OLD = items.find(i => i.item_id === itemId);
    if (!OLD) return;
    document.getElementById('panel').style.display = 'block';
    document.getElementById('f_item_id').value = OLD.item_id;
    setText('oldName', OLD.product_name);
    setText('oldCat', OLD.category);
    setText('oldSize', OLD.size);
    setText('oldMrp', fmt(OLD.mrp));
    setText('oldDisc', (parseFloat(OLD.disc)||0).toFixed(2) + '%');
    setText('oldFinal', fmt(OLD.final));
    setText('oldReturn', fmt(OLD.final));
    NEW = null;
    document.getElementById('f_new_pid').value = '';
    document.getElementById('f_repl_pid').value = '';
    document.getElementById('newSearch').value = '';
    document.getElementById('newDetails').style.display = 'none';
    document.getElementById('discInput').value = (parseFloat(OLD.disc)||0).toFixed(2); // auto-copy old discount
    onDisc();
    document.getElementById('defectReason').value = '';
    document.getElementById('resultBox').style.display = 'none';
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('panel').scrollIntoView({behavior:'smooth', block:'nearest'});
}

function onDisc() {
    const v = document.getElementById('discInput').value;
    document.getElementById('f_new_disc').value = v;
    document.getElementById('f_repl_disc').value = v;
    recompute();
}

let t;
const newSearchEl = document.getElementById('newSearch');
if (newSearchEl) newSearchEl.addEventListener('input', function() {
    clearTimeout(t);
    const q = this.value.trim();
    const box = document.getElementById('newResults');
    if (q.length < 2) { box.style.display='none'; return; }
    t = setTimeout(() => {
        const br = OLD && OLD.branch_id ? '&branch_id=' + OLD.branch_id : '';
        fetch('<?php echo BASE_URL; ?>/modules/billing/search_product.php?q=' + encodeURIComponent(q) + br)
            .then(r => r.json())
            .then(data => {
                if (!data || !data.length) { box.style.display='none'; return; }
                box.innerHTML = data.map(p => `<div style="padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--border);" onclick='selectNewProduct(${JSON.stringify(p)})'>
                    <div class="fw-600 fs-13">${p.name}</div>
                    <div class="fs-11 text-muted">${p.category||'—'} · Size ${p.size||'—'} · ${fmt(p.selling_price)} · Stock ${p.quantity}</div></div>`).join('');
                box.style.display='block';
            });
    }, 250);
});

function selectNewProduct(p) {
    NEW = p;
    document.getElementById('newResults').style.display='none';
    document.getElementById('newSearch').value = p.name;
    document.getElementById('f_new_pid').value = p.id;
    document.getElementById('f_repl_pid').value = p.id;
    setText('newName', p.name);
    setText('newCat', p.category || '—');
    setText('newSize', p.size || '—');
    setText('newMrp', fmt(p.selling_price));
    setText('newStock', p.quantity);
    document.getElementById('newDetails').style.display = 'block';
    recompute();
}

function recompute() {
    if (!OLD || !NEW) return;
    const mrp = parseFloat(NEW.selling_price) || 0;
    const disc = Math.max(0, Math.min(100, parseFloat(document.getElementById('discInput').value) || 0));
    const newFinal = +(mrp * (1 - disc/100)).toFixed(2);
    const base = parseFloat(OLD.final) || 0;
    const diff = +(newFinal - base).toFixed(2);
    setText('newFinal', fmt(newFinal));

    const box = document.getElementById('resultBox');
    const msg = document.getElementById('resultMsg');
    const payRow = document.getElementById('paymentRow');
    const submit = document.getElementById('submitBtn');
    const stockOk = parseInt(NEW.quantity) >= 1;
    box.style.display = 'block';
    document.getElementById('diffVal').textContent = fmt(Math.abs(diff));

    if (MODE === 'exchange') {
        if (diff < 0) {
            msg.innerHTML = '<span style="color:var(--danger);font-weight:600;">Exchange value cannot be lower than return value. No Refund. No Cash Back.</span>';
            payRow.style.display = 'none'; submit.disabled = true;
        } else if (diff > 0) {
            msg.innerHTML = '<span style="color:var(--on-surface-muted);">Customer pays ' + fmt(diff) + ' extra.</span>' + (stockOk ? '' : ' <span style="color:var(--danger);">Out of stock.</span>');
            payRow.style.display = 'block'; submit.disabled = !stockOk;
        } else {
            msg.innerHTML = '<span style="color:var(--success);font-weight:600;">Even exchange — no extra payment.</span>' + (stockOk ? '' : ' <span style="color:var(--danger);">Out of stock.</span>');
            payRow.style.display = 'none'; submit.disabled = !stockOk;
        }
    } else { // defective
        payRow.style.display = 'none';
        const reasonOk = document.getElementById('defectReason').value.trim() !== '';
        if (newFinal < base) {
            msg.innerHTML = '<span style="color:var(--danger);font-weight:600;">Replacement value cannot be lower than the original sale price. Lower value product not allowed.</span>';
            submit.disabled = true;
        } else {
            let m = '<span style="color:var(--success);font-weight:600;">Valid replacement — sales unchanged, no payment.</span>';
            if (!reasonOk) m += ' <span style="color:var(--danger);">Enter a defect reason.</span>';
            if (!stockOk)  m += ' <span style="color:var(--danger);">Out of stock.</span>';
            msg.innerHTML = m;
            submit.disabled = !(reasonOk && stockOk);
        }
    }
}

document.addEventListener('click', e => {
    const box = document.getElementById('newResults');
    if (box && !e.target.closest('#newSearch') && !e.target.closest('#newResults'))
        box.style.display='none';
});

setMode(MODE); // initialise from ?mode=
</script>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
