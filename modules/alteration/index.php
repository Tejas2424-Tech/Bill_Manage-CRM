<?php
/**
 * Alteration Management — list + Daily/Weekly/Monthly/Yearly report, with
 * add/edit, status workflow (pending/ready/delivered) and CSV export.
 * Not cashier-accessible (per spec role list).
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle  = 'Alterations';
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Alterations</span>';

$isAdmin   = isAdmin();
$branch_id = (int)($_SESSION['branch_id'] ?? 0);

// POST actions: status update / delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
        redirect('index.php');
    }
    $aid = (int)($_POST['id'] ?? 0);
    $scope = !$isAdmin ? " AND branch_id = " . $branch_id : "";

    if (($_POST['action'] ?? '') === 'update_status') {
        $status = in_array($_POST['status'] ?? '', ['pending','ready','delivered'], true) ? $_POST['status'] : 'pending';
        $stmt = $pdo->prepare("UPDATE alterations SET status = ? WHERE id = ?$scope");
        $stmt->execute([$status, $aid]);
        logAudit('alteration_status', 'alteration', "Alteration #$aid → $status");
        flashMessage('success', 'Status updated.');
    } elseif (($_POST['action'] ?? '') === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM alterations WHERE id = ?$scope");
        $stmt->execute([$aid]);
        logAudit('delete_alteration', 'alteration', "Deleted alteration #$aid");
        flashMessage('success', 'Alteration deleted.');
    }
    redirect('index.php' . (!empty($_POST['qs']) ? '?' . $_POST['qs'] : ''));
}

// Date range (report) filters — Daily / Weekly(Mon) / Monthly / Yearly / Custom
$range    = $_GET['range'] ?? 'monthly';
$status_f = $_GET['status'] ?? '';
[$date_from, $date_to] = resolveReportRange($range, $_GET['date_from'] ?? '', $_GET['date_to'] ?? '');

$where  = ["alteration_date BETWEEN ? AND ?"];
$params = [$date_from, $date_to];
if (!$isAdmin) { $where[] = "branch_id = ?"; $params[] = $branch_id; }
if (in_array($status_f, ['pending','ready','delivered'], true)) { $where[] = "status = ?"; $params[] = $status_f; }
$where_sql = implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT * FROM alterations WHERE $where_sql ORDER BY alteration_date DESC, id DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$total_charge = 0; foreach ($rows as $r) $total_charge += (float)$r['alteration_charge'];

// CSV export
if (isset($_GET['export'])) {
    $headers = ['Date','Customer','Mobile','Bill No','Product','Type','Charge','Status'];
    $data = array_map(fn($r) => [
        $r['alteration_date'], $r['customer_name'], $r['mobile'], $r['bill_number'],
        $r['product_name'], $r['alteration_type'], $r['alteration_charge'], ucfirst($r['status'])
    ], $rows);
    exportCSV('alterations_' . $date_from . '_to_' . $date_to . '.csv', $headers, $data);
}

$qs = http_build_query(['range'=>$range,'status'=>$status_f,'date_from'=>$date_from,'date_to'=>$date_to]);
$csrf_token = generateCSRFToken();

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Alteration Management</h1>
        <div class="sub"><?php echo count($rows); ?> job<?php echo count($rows) != 1 ? 's' : ''; ?> · <?php echo formatDate($date_from); ?> – <?php echo formatDate($date_to); ?> · Total <?php echo formatCurrency($total_charge); ?></div>
    </div>
    <div class="page-header-actions">
        <a href="?<?php echo $qs; ?>&export=1" class="btn btn-outline btn-sm"><i class="fas fa-file-csv"></i> Export</a>
        <a href="add.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add Alteration</a>
    </div>
</div>

<div class="table-wrapper">
    <div class="table-toolbar" style="flex-wrap:wrap;gap:10px;">
        <form action="" method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;flex:1;">
            <select name="range" class="form-control" style="max-width:140px;" onchange="this.form.submit()">
                <?php foreach (reportRangeOptions() as $k=>$v): ?>
                    <option value="<?php echo $k; ?>" <?php echo $range === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($range === 'custom'): ?>
                <input type="date" name="date_from" class="form-control" value="<?php echo sanitize($date_from); ?>" style="max-width:160px;">
                <input type="date" name="date_to" class="form-control" value="<?php echo sanitize($date_to); ?>" style="max-width:160px;">
            <?php endif; ?>
            <select name="status" class="form-control" style="max-width:140px;" onchange="this.form.submit()">
                <option value="">All Status</option>
                <?php foreach (['pending'=>'Pending','ready'=>'Ready','delivered'=>'Delivered'] as $k=>$v): ?>
                    <option value="<?php echo $k; ?>" <?php echo $status_f === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline"><i class="fas fa-filter"></i> Apply</button>
        </form>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Customer</th>
                <th>Bill No</th>
                <th>Product</th>
                <th>Type</th>
                <th class="text-end">Charge</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="8" class="text-center py-5 text-muted">No alterations in this period.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td class="fs-12"><?php echo formatDate($r['alteration_date']); ?></td>
                    <td>
                        <div class="fw-600"><?php echo sanitize($r['customer_name']); ?></div>
                        <?php if ($r['mobile']): ?><div class="fs-11 text-muted"><?php echo sanitize($r['mobile']); ?></div><?php endif; ?>
                    </td>
                    <td class="fs-12"><?php echo $r['bill_number'] ? sanitize($r['bill_number']) : '<span class="text-muted">-</span>'; ?></td>
                    <td class="fs-13"><?php echo $r['product_name'] ? sanitize($r['product_name']) : '<span class="text-muted">-</span>'; ?></td>
                    <td class="fs-13"><?php echo sanitize($r['alteration_type']); ?></td>
                    <td class="text-end fw-600"><?php echo formatCurrency($r['alteration_charge']); ?></td>
                    <td>
                        <form action="" method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                            <input type="hidden" name="qs" value="<?php echo sanitize($qs); ?>">
                            <select name="status" onchange="this.form.submit()" class="form-control" style="padding:3px 8px;font-size:12px;width:auto;display:inline-block;
                                <?php echo $r['status']==='delivered' ? 'color:var(--success);' : ($r['status']==='ready' ? 'color:var(--secondary);' : 'color:var(--warning);'); ?>">
                                <?php foreach (['pending'=>'Pending','ready'=>'Ready','delivered'=>'Delivered'] as $k=>$v): ?>
                                    <option value="<?php echo $k; ?>" <?php echo $r['status']===$k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td class="text-end">
                        <div class="d-flex justify-end gap-1">
                            <a href="add.php?id=<?php echo $r['id']; ?>" class="btn btn-ghost btn-icon btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                            <form action="" method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                <input type="hidden" name="qs" value="<?php echo sanitize($qs); ?>">
                                <button type="submit" class="btn btn-ghost btn-icon btn-sm text-danger" data-confirm="Delete this alteration?" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
