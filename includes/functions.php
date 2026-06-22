<?php
/**
 * Global Utility Functions
 */

require_once __DIR__ . '/../config/db.php';

/**
 * Format currency to Indian format (₹1,23,456.50)
 */
function formatCurrency($amount) {
    $amount = (float)$amount;
    $negative = $amount < 0 ? '-' : '';
    $amount = abs($amount);
    
    $exploded = explode('.', number_format($amount, 2, '.', ''));
    $whole = $exploded[0];
    $decimal = $exploded[1];
    
    $last_three = substr($whole, -3);
    $rest = substr($whole, 0, -3);
    
    if ($rest != '') {
        $rest = preg_replace("/\B(?=(\d{2})+(?!\d))/", ",", $rest) . ",";
    }
    
    return CURRENCY . $negative . $rest . $last_three . "." . $decimal;
}

/**
 * Format date (15 Jan 2025)
 */
function formatDate($date) {
    return date('d M Y', strtotime($date));
}

/**
 * Format datetime (15 Jan 2025, 3:45 PM)
 */
function formatDateTime($dt) {
    return date('d M Y, g:i A', strtotime($dt));
}

/**
 * Generate a Financial-Year based bill number: BRANCH-FY-00001
 *
 * FY runs 1 April → 31 March; numbering restarts at 1 each FY, per branch.
 * Uses the bill_counters table with an atomic INSERT … ON DUPLICATE KEY UPDATE
 * so it is safe under concurrency (fixes the old COUNT+1 race). Intended to be
 * called inside the bill's PDO transaction.
 *
 * @param string   $branch_code  branch code for the prefix
 * @param int|null $branch_id    resolved branch id (pass explicitly — superadmin's
 *                               session branch_id is NULL)
 */
function generateBillNumber($branch_code, $branch_id = null) {
    global $pdo;
    if ($branch_id === null) {
        $branch_id = $_SESSION['branch_id'] ?? 0;
    }
    $branch_id = (int)$branch_id;

    // Financial year: month >= April → Y/(Y+1), else (Y-1)/Y
    $y = (int)date('Y');
    $m = (int)date('n');
    $start = ($m >= 4) ? $y : $y - 1;
    $fy = $start . '-' . substr((string)($start + 1), -2);   // e.g. 2026-27

    // Atomic increment + read-back via LAST_INSERT_ID()
    $stmt = $pdo->prepare("INSERT INTO bill_counters (branch_id, fy, last_no)
                           VALUES (?, ?, LAST_INSERT_ID(1))
                           ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)");
    $stmt->execute([$branch_id, $fy]);
    $no = (int)$pdo->lastInsertId();

    return strtoupper($branch_code) . '-' . $fy . '-' . str_pad($no, 5, '0', STR_PAD_LEFT);
}

/**
 * Generate product SKU from name
 */
function generateSKU($name) {
    $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 3));
    if (strlen($prefix) < 3) $prefix = str_pad($prefix, 3, 'X');
    return $prefix . rand(1000, 9999);
}

/**
 * Generate 13-digit numeric barcode (starting with 2)
 */
function generateBarcode() {
    return '2' . str_pad(rand(0, 999999999999), 12, '0', STR_PAD_LEFT);
}

/**
 * Get setting value by key
 */
function getSettingValue($key) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE key_name = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    return $result ? $result['value'] : null;
}

/**
 * Log activity to audit log
 */
function logAudit($action, $module, $description) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, branch_id, action, module, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_SESSION['user_id'] ?? null,
        $_SESSION['branch_id'] ?? null,
        $action,
        $module,
        $description,
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    ]);
}

/**
 * Create a new notification
 */
function createNotification($branch_id, $type, $title, $message) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO notifications (branch_id, type, title, message) VALUES (?, ?, ?, ?)");
    $stmt->execute([$branch_id, $type, $title, $message]);
}

/**
 * Get count of unread notifications for current branch
 */
function getUnreadNotificationCount() {
    global $pdo;
    $branch_id = $_SESSION['branch_id'] ?? null;
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM notifications WHERE is_read = 0 AND (branch_id = ? OR branch_id IS NULL)");
    $stmt->execute([$branch_id]);
    $result = $stmt->fetch();
    return $result ? $result['total'] : 0;
}

/**
 * Sanitize user input
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect to a URL
 */
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

/**
 * Set flash message in session
 */
function flashMessage($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Get and clear flash message
 */
function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Validate form data based on rules
 * Rules: required, email, numeric, min:X, max:X
 */
function validateForm($rules, $data) {
    $errors = [];
    foreach ($rules as $field => $field_rules) {
        $val = $data[$field] ?? '';
        $rule_list = explode('|', $field_rules);
        
        foreach ($rule_list as $rule) {
            if ($rule === 'required' && empty($val)) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required.";
            } elseif ($rule === 'email' && !empty($val) && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Please enter a valid email address.";
            } elseif ($rule === 'numeric' && !empty($val) && !is_numeric($val)) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . " must be a number.";
            } elseif (strpos($rule, 'min:') === 0) {
                $min = (float)substr($rule, 4);
                if (!empty($val) && (float)$val < $min) {
                    $errors[] = ucfirst(str_replace('_', ' ', $field)) . " must be at least $min.";
                }
            } elseif (strpos($rule, 'max:') === 0) {
                $max = (float)substr($rule, 4);
                if (!empty($val) && (float)$val > $max) {
                    $errors[] = ucfirst(str_replace('_', ' ', $field)) . " cannot exceed $max.";
                }
            }
        }
    }
    return $errors;
}

/**
 * Get branch name by ID
 */
function getBranchName($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetchColumn() ?: 'Unknown Branch';
}

/**
 * Update system setting
 */
function updateSetting($key, $value) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?");
    $stmt->execute([$key, $value, $value]);
}

/**
 * Resolve a report date range from a preset key.
 * Presets: daily, weekly (Mon-start), monthly, yearly, financial_year (1 Apr–31 Mar), custom.
 * Returns [from, to] as Y-m-d strings.
 */
function resolveReportRange($range, $from = '', $to = '') {
    $today = date('Y-m-d');
    switch ($range) {
        case 'daily':          return [$today, $today];
        case 'weekly':         return [date('Y-m-d', strtotime('monday this week')), $today];
        case 'monthly':        return [date('Y-m-01'), $today];
        case 'yearly':         return [date('Y-01-01'), $today];
        case 'financial_year':
            $y = (int)date('Y'); $m = (int)date('n');
            $start = ($m >= 4) ? $y : $y - 1;     // FY starts 1 April
            return [$start . '-04-01', $today];
        case 'custom':         return [$from ?: date('Y-m-01'), $to ?: $today];
        default:               return [date('Y-m-01'), $today];
    }
}

/** Preset options for report range dropdowns (key => label). */
function reportRangeOptions() {
    return ['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','yearly'=>'Yearly','financial_year'=>'Financial Year','custom'=>'Custom'];
}

/**
 * Export data to CSV
 */
function exportCSV($filename, $headers, $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

/**
 * Time elapsed string (e.g. 2 hours ago)
 */
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
?>
