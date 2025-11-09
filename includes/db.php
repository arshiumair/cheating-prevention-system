<?php
/**
 * Database Connection File
 * Handles MySQL database connection for the Online Exam Monitoring System
 * 
 * This file provides a reusable database connection that can be included
 * in other PHP files using require_once. It uses MySQLi for the connection
 * and includes error handling with clear messages.
 * 
 * IMPORTANT: Always use require_once to include this file to prevent
 * multiple inclusion issues.
 */

// Only define constants if they haven't been defined yet
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');     // XAMPP default host
if (!defined('DB_USER')) define('DB_USER', 'root');         // XAMPP default username
if (!defined('DB_PASS')) define('DB_PASS', '');             // XAMPP default password (empty)
if (!defined('DB_NAME')) define('DB_NAME', 'exam_db');      // Our database name

// Alternative approach using variables (uncomment if you prefer variables over constants)
/*
$db_config = [
    'host' => 'localhost',
    'user' => 'root',
    'pass' => '',
    'name' => 'exam_db'
];
*/

// Prevent this file from being accessed directly
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    die('This file cannot be accessed directly.');
}

// Global connection variable (null by default)
$conn = null;

// Create connection with comprehensive error handling
try {
    // Initialize MySQLi connection with error reporting
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    // Check for connection errors (should be caught by exceptions, but keep as safeguard)
    if ($conn->connect_error) {
        // Specific error for database not found
        if ($conn->connect_errno == 1049) {
            throw new Exception(
                "Database '" . DB_NAME . "' not found. " .
                "Please ensure the database exists and run setup_database.php first."
            );
        }
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Set UTF-8 character set for proper encoding
    if (!$conn->set_charset("utf8mb4")) {
        throw new Exception("Error setting character set utf8mb4: " . $conn->error);
    }
    
    // Set SQL mode for stricter SQL rules and better error catching
    $conn->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'");
    
    // Optional: Set wait_timeout to prevent connection timeouts during long exams
    $conn->query("SET SESSION wait_timeout = 28800"); // 8 hours
    
} catch (Exception $e) {
    // Convert any errors to a clear message
    $error_message = "Database Connection Error: " . $e->getMessage();
    
    // Add debug information if in development environment
    if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE === true) {
        $error_message .= "\n\nDebug Information:\n";
        $error_message .= "PHP Version: " . PHP_VERSION . "\n";
        $error_message .= "MySQL Client Version: " . mysqli_get_client_info() . "\n";
        $error_message .= "Server: " . $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
    }
    
    // Check if this is being accessed via web or CLI
    if (php_sapi_name() !== 'cli' && isset($_SERVER['REQUEST_URI'])) {
        // Web access - show formatted error with Bootstrap-like styling
        die("<div style='color: #721c24; background: #f8d7da; border: 1px solid #f5c6cb; padding: 20px; margin: 10px; border-radius: 5px;'>" .
            "<h3 style='margin-top: 0;'>Database Connection Failed</h3>" .
            "<p>" . nl2br(htmlspecialchars($error_message)) . "</p>" .
            "<p>Troubleshooting Steps:</p>" .
            "<ul>" .
            "<li>Verify XAMPP/MySQL service is running</li>" .
            "<li>Check database credentials in includes/db.php</li>" .
            "<li>Ensure database '" . DB_NAME . "' exists</li>" .
            "<li>Run setup_database.php if this is a new installation</li>" .
            "</ul>" .
            "</div>");
    } else {
        // CLI access - show detailed error
        die($error_message . "\n");
    }
}

/**
 * Helper function to safely close the database connection
 * This function is registered as a shutdown function to ensure
 * the connection is always properly closed, even if the script
 * terminates unexpectedly.
 */
function closeDatabase() {
    global $conn;
    if ($conn && $conn instanceof mysqli) {
        try {
            // Safely close the connection on shutdown.
            // Disable mysqli exceptions temporarily to avoid fatal errors during shutdown
            mysqli_report(MYSQLI_REPORT_OFF);
            // Attempt to close the connection; suppress any warnings
            try {
                @ $conn->close();
            } catch (Throwable $e) {
                // ignore any errors while closing
            }
            // Restore strict reporting for the rest of the application (best-effort)
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        } catch (Exception $e) {
            // Silently fail on shutdown - can't show errors at this point
        }
        $conn = null;
    }
}

// Register shutdown function to ensure connection is always closed
register_shutdown_function('closeDatabase');

// Optional: Return the connection object if someone needs direct access
// return $conn;
?>

