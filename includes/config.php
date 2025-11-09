<?php
/**
 * Configuration File
 * Handles database connection and base path configuration
 */

// Database configuration
define('DB_HOST', 'localhost');     // MySQL host (XAMPP default)
define('DB_USER', 'root');         // MySQL username (XAMPP default)
define('DB_PASS', '');             // MySQL password (XAMPP default)
define('DB_NAME', 'exam_db');  // Database name

// Establish database connection
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    // Check connection
    if ($conn->connect_error) {
        if ($conn->connect_errno == 1049) {
            die("<div style='padding: 20px; border: 1px solid #dc3545; border-radius: 5px; margin: 20px; background: #f8d7da; color: #721c24;'>" .
                "<h3 style='margin-top:0'>Database Error</h3>" .
                "<p>Database '" . DB_NAME . "' not found. Please run the database setup script first.</p>" .
                "</div>");
        }
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Set UTF-8 character set
    if (!$conn->set_charset("utf8mb4")) {
        throw new Exception("Error setting character set utf8mb4");
    }
    
    // Set SQL mode for strict rules
    $conn->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    
} catch (Exception $e) {
    die("<div style='padding: 20px; border: 1px solid #dc3545; border-radius: 5px; margin: 20px; background: #f8d7da; color: #721c24;'>" .
        "<h3 style='margin-top:0'>Database Connection Error</h3>" .
        "<p>" . htmlspecialchars($e->getMessage()) . "</p>" .
        "<p>Please check the database configuration and ensure MySQL is running.</p>" .
        "</div>");
}

// Get the base path dynamically
$script_name = $_SERVER['SCRIPT_NAME'];
$script_dir = str_replace('\\', '/', dirname($script_name));

// Remove /auth, /includes, /templates, etc. to get project root
$base_path = $script_dir;
if (strpos($base_path, '/auth') !== false) {
    $base_path = str_replace('/auth', '', $base_path);
}
if (strpos($base_path, '/includes') !== false) {
    $base_path = str_replace('/includes', '', $base_path);
}
if (strpos($base_path, '/templates') !== false) {
    $base_path = str_replace('/templates', '', $base_path);
}

// Remove trailing slash if not root
if ($base_path !== '/') {
    $base_path = rtrim($base_path, '/');
}

// Define base URL and path
if (!defined('BASE_URL')) {
    define('BASE_URL', $base_path);
}
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $base_path);
}

// Register shutdown function to close database connection
register_shutdown_function(function() {
    global $conn;
    if ($conn instanceof mysqli) {
        $conn->close();
    }
});
?>

