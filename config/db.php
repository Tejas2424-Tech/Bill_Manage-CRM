<?php
/**
 * Database Configuration and Connection
 */

// Database credentials
$host = 'localhost';
$db   = 'billmanage';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

// Define BASE_URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$http_host = $_SERVER['HTTP_HOST'];

// Get the directory of the current script (config/db.php)
$config_dir = str_replace('\\', '/', __DIR__);
// Get the document root
$doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
// Calculate the relative path from doc root to project root
$project_root_path = str_replace($doc_root, '', dirname($config_dir));
// Ensure it starts with a slash and doesn't end with one
$project_root_path = '/' . ltrim($project_root_path, '/');

define('BASE_URL', $protocol . "://" . $http_host . $project_root_path);

// Global Constants
define('CURRENCY', '₹');

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>
