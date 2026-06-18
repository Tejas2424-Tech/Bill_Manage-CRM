<?php
/**
 * Birthday List — customers whose birthday falls on a chosen date
 * (defaults to today). Cashier-accessible; branch-scoped for non-admins.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle  = 'Birthday List';
$breadcrumb = '<a href="'.BASE_URL.'/modules/dashboard/index.php">Home</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Birthday List</span>';

$isAdmin   = isAdmin();
$branch_id = (int)($_SESSION['branch_id'] ?? 0);

// Selected date (default today). Fall back to today on a bad value.
$date = $_GET['date'] ?? date('Y-m-d');
$ts = strtotime($date);
if ($ts === false) { $ts = time(); }
$date = date('Y-m-d', $ts);
$month = (int)date('n', $ts);
$day   = (int)date('j', $ts);

$params = [$month, $day];
$scope  = '';
if (!$isAdmin) { $scope = ' AND branch_id = ?'; $params[] = $branch_id; }

$stmt = $pdo->prepare("SELECT id, name, mobile, date_of_birth
                       FROM customers
                       WHERE date_of_birth IS NOT NULL
                         AND MONTH(date_of_birth) = ? AND DAY(date_of_birth) = ? $scope
                       ORDER BY name ASC");
$stmt->execute($params);
$customers = $stmt->fetchAll();

$isToday = ($date === date('Y-m-d'));

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Birthday List</h1>
        <div class="sub"><?php echo $isToday ? "Today — " . date('d M') : date('d M', $ts); ?> · <?php echo count($customers); ?> customer<?php echo count($customers) != 1 ? 's' : ''; ?></div>
    </div>
</div>

<div class="table-wrapper">
    <div class="table-toolbar" style="flex-wrap:wrap;gap:10px;">
        <form action="" method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <label class="fs-13 text-muted">Birthday on:</label>
            <input type="date" name="date" class="form-control" value="<?php echo sanitize($date); ?>" style="max-width:180px;">
            <button type="submit" class="btn btn-outline"><i class="fas fa-magnifying-glass"></i> Show</button>
            <?php if (!$isToday): ?>
                <a href="index.php" class="btn btn-ghost">Today</a>
            <?php endif; ?>
        </form>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Customer Name</th>
                <th>Mobile Number</th>
                <th>Date of Birth</th>
                <th class="text-end">Turning</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($customers)): ?>
                <tr><td colspan="4" class="text-center py-5 text-muted">
                    <i class="fas fa-cake-candles" style="font-size:30px;color:var(--border);display:block;margin-bottom:10px;"></i>
                    No birthdays on this date.
                </td></tr>
            <?php else: foreach ($customers as $c): ?>
                <?php
                    $dobTs = strtotime($c['date_of_birth']);
                    $turning = (int)date('Y', $ts) - (int)date('Y', $dobTs);
                ?>
                <tr>
                    <td class="fw-600"><i class="fas fa-cake-candles" style="color:var(--purple);margin-right:6px;"></i><?php echo sanitize($c['name']); ?></td>
                    <td><?php echo sanitize($c['mobile']); ?></td>
                    <td class="fs-13"><?php echo formatDate($c['date_of_birth']); ?></td>
                    <td class="text-end"><?php echo $turning > 0 ? $turning . ' yrs' : '<span class="text-muted">-</span>'; ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
