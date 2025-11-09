<?php
/**
 * Event Logging Handler
 * Handles exam violation events and manages exam termination
 * 
 * Receives POST requests with JSON data:
 * {
 *   "event_type": string,  // e.g., "blur", "cursor_out", "fullscreen_exit"
 *   "details": string      // optional additional details
 * }
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Required configuration files
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// Set JSON response header
header('Content-Type: application/json');

/**
 * Send JSON response and exit
 */
function sendResponse($success, $data = null, $error = null) {
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'error' => $error
    ]);
    exit;
}

// Verify request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, null, 'Invalid request method');
}

// Verify user is logged in
if (!isset($_SESSION['user_id'])) {
    sendResponse(false, null, 'User not authenticated');
}

// Verify exam session exists
if (!isset($_SESSION['exam_id'])) {
    sendResponse(false, null, 'No active exam session');
}

// Get POST data (JSON)
$jsonData = file_get_contents('php://input');
$data = json_decode($jsonData);

if (!$data || !isset($data->event_type)) {
    sendResponse(false, null, 'Invalid request data');
}

// Sanitize inputs
$userId = (int)$_SESSION['user_id'];
$examId = (int)$_SESSION['exam_id'];
$eventType = substr(trim($data->event_type), 0, 50); // Limit to 50 chars
$details = isset($data->details) ? substr(trim($data->details), 0, 1000) : null; // Limit details to 1000 chars

try {
    // Start transaction
    $conn->begin_transaction();

    // Insert violation event with current timestamp
    $stmt = $conn->prepare("
        INSERT INTO exam_violations (
            session_id,
            user_id,
            event_type,
            event_time,
            details
        ) VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?)
    ");
    
    if (!$stmt) {
        throw new Exception("Failed to prepare violation insert statement: " . $conn->error);
    }
    
    $stmt->bind_param('iiss', $examId, $userId, $eventType, $details);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to insert violation: " . $stmt->error);
    }
    
    $stmt->close();

    // Count total violations for this exam session
    $stmt = $conn->prepare("
        SELECT COUNT(*) as violation_count 
        FROM exam_violations 
        WHERE session_id = ? AND user_id = ?
        AND event_time >= (
            SELECT started_at 
            FROM exam_sessions 
            WHERE session_id = ? AND user_id = ?
            AND ended_at IS NULL
        )
    ");
    
    if (!$stmt) {
        throw new Exception("Failed to prepare violation count statement: " . $conn->error);
    }
    
    $stmt->bind_param('iiii', $examId, $userId, $examId, $userId);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to count violations: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $violationCount = $result->fetch_assoc()['violation_count'];
    
    $stmt->close();

    // Determine action based on violation count
    $action = 'ok';
    $message = 'Violation logged';
    
    if ($violationCount >= 3) {
        // End exam session
        $stmt = $conn->prepare("
            UPDATE exam_sessions 
            SET ended_at = CURRENT_TIMESTAMP,
                ended_reason = 'terminated'
            WHERE id = ? AND user_id = ? AND ended_at IS NULL
        ");
        $stmt->bind_param('ii', $examId, $userId);
        $stmt->execute();
        $stmt->close();

        $action = 'end';
        $message = 'Exam terminated due to multiple violations';
    } 
    elseif ($violationCount == 2) {
        $action = 'warn';
        $message = 'Warning: Next violation will terminate the exam';
    }

    // Commit transaction
    $conn->commit();

    // Send response
    sendResponse(true, [
        'violations' => $violationCount,
        'action' => $action,
        'message' => $message
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    // Log error (you may want to implement proper error logging)
    error_log("Error in log_event.php: " . $e->getMessage());
    
    sendResponse(false, null, 'Internal server error');
}

// Helper function to validate event type
function isValidEventType($type) {
    $validTypes = ['blur', 'cursor_out', 'fullscreen_exit'];
    return in_array($type, $validTypes);
}
?>