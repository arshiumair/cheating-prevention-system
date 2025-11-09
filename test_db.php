<?php
/**
 * Database Connection Test Script
 * Verifies the database connection and configuration
 */

// Include the database configuration
require_once __DIR__ . '/includes/db.php';

// Function to test the database connection
function testDatabaseConnection() {
    global $conn;
    
    echo "<div style='font-family: Arial, sans-serif; max-width: 800px; margin: 20px auto; padding: 20px;'>";
    
    // Test 1: Basic Connection
    echo "<h2>Database Connection Test</h2>";
    if ($conn && $conn instanceof mysqli && !$conn->connect_error) {
        echo "<p style='color: #155724; background: #d4edda; padding: 15px; border-radius: 4px;'>✅ Database connected successfully!</p>";
        
        // Test 2: Database Information
        echo "<h3>Connection Details:</h3>";
        echo "<ul style='list-style: none; padding: 0;'>";
        echo "<li>📍 Server: " . htmlspecialchars($conn->host_info) . "</li>";
        echo "<li>📚 Database: " . htmlspecialchars(DB_NAME) . "</li>";
        echo "<li>🔤 Character Set: " . htmlspecialchars($conn->character_set_name()) . "</li>";
        echo "<li>⚙️ Server Version: " . htmlspecialchars($conn->server_info) . "</li>";
        echo "</ul>";
        
        // Test 3: Check Required Tables
        echo "<h3>Required Tables Check:</h3>";
        $required_tables = ['users', 'exam_sessions', 'exam_violations'];
        $tables_found = [];
        
        $result = $conn->query("SHOW TABLES");
        if ($result) {
            while ($row = $result->fetch_array()) {
                $tables_found[] = $row[0];
            }
            
            echo "<ul style='list-style: none; padding: 0;'>";
            foreach ($required_tables as $table) {
                $exists = in_array($table, $tables_found);
                $icon = $exists ? '✅' : '❌';
                $style = $exists ? 'color: #155724' : 'color: #721c24';
                echo "<li style='{$style}'>{$icon} {$table}</li>";
            }
            echo "</ul>";
        }
        
    } else {
        echo "<p style='color: #721c24; background: #f8d7da; padding: 15px; border-radius: 4px;'>❌ Database connection failed!</p>";
    }
    
    echo "</div>";
}

// Run the test
testDatabaseConnection();
?>