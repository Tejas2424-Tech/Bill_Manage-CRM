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
 * Generate unique bill number (BRANCH-YEAR-00042)
 */
function generateBillNumber($branch_code) {
    global $pdo;
    $year = date('Y');
    $branch_id = $_SESSION['branch_id'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM bills WHERE branch_id = ? AND YEAR(created_at) = ?");
    $stmt->execute([$branch_id, $year]);
    $result = $stmt->fetch();
    $count = ($result ? $result['total'] : 0) + 1;
    
    return strtoupper($branch_code) . '-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
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
