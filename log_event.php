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

// Expect an exam id in session (this is the exam identifier, not the DB session_id)
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

// Validate event type
if (!isValidEventType($eventType)) {
    sendResponse(false, null, 'Invalid event type');
}

try {
    

    // Start transaction
    $conn->begin_transaction();

    // Ensure there is an active exam session for this user & exam
    $stmt = $conn->prepare("SELECT session_id, started_at FROM exam_sessions WHERE user_id = ? AND exam_id = ? AND ended_at IS NULL LIMIT 1");
    if (!$stmt) throw new Exception('Failed to prepare session lookup: ' . $conn->error);
    $stmt->bind_param('ii', $userId, $examId);
    $stmt->execute();
    $res = $stmt->get_result();

    $sessionId = null;
    $startedAt = null;
    if ($row = $res->fetch_assoc()) {
        $sessionId = (int)$row['session_id'];
        $startedAt = $row['started_at'];
    }
    $stmt->close();

    // If client asked to start a fresh session, always create a new session row
    if ($eventType === 'start_session') {
        $stmt = $conn->prepare("INSERT INTO exam_sessions (user_id, exam_id, started_at) VALUES (?, ?, NOW())");
        if (!$stmt) throw new Exception('Failed to prepare session insert: ' . $conn->error);
        $stmt->bind_param('ii', $userId, $examId);
        if (!$stmt->execute()) throw new Exception('Failed to create exam session: ' . $stmt->error);
        $sessionId = $stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("SELECT started_at FROM exam_sessions WHERE session_id = ?");
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $startedAt = $row['started_at'];
        $stmt->close();

        $_SESSION['exam_session_id'] = $sessionId;
    }

    // If no active session exists, create one (normal flow)
    if (!$sessionId) {
        $stmt = $conn->prepare("INSERT INTO exam_sessions (user_id, exam_id, started_at) VALUES (?, ?, NOW())");
        if (!$stmt) throw new Exception('Failed to prepare session insert: ' . $conn->error);
        $stmt->bind_param('ii', $userId, $examId);
        if (!$stmt->execute()) throw new Exception('Failed to create exam session: ' . $stmt->error);
        $sessionId = $stmt->insert_id;
        // fetch started_at value
        $stmt->close();

        $stmt = $conn->prepare("SELECT started_at FROM exam_sessions WHERE session_id = ?");
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $startedAt = $row['started_at'];
        $stmt->close();

        // Store session id in PHP session for later use if desired
        $_SESSION['exam_session_id'] = $sessionId;
    }

    // If client explicitly requested to start a session (no violation), return current state
    if ($eventType === 'start_session') {
        // Count violations since started (should normally be 0 right after start)
        $stmt = $conn->prepare("SELECT COUNT(*) AS violation_count FROM exam_violations WHERE user_id = ? AND exam_id = ? AND event_time >= ?");
        if (!$stmt) throw new Exception('Failed to prepare violation count: ' . $conn->error);
        $stmt->bind_param('iis', $userId, $examId, $startedAt);
        if (!$stmt->execute()) throw new Exception('Failed to execute violation count: ' . $stmt->error);
        $violationCount = (int)$stmt->get_result()->fetch_assoc()['violation_count'];
        $stmt->close();

        $conn->commit();
        // Align server escalation with client: warn on 1st and 2nd violations, end on 3rd or more
        $action = 'ok';
        if ($violationCount >= 3) {
            $action = 'end';
        } elseif ($violationCount >= 1) {
            $action = 'warn';
        }
        sendResponse(true, [
            'violations' => $violationCount,
            'action' => $action,
            'message' => 'Session started',
            'session_id' => $sessionId
        ]);
    }

    // Insert violation into exam_violations
    $stmt = $conn->prepare("INSERT INTO exam_violations (user_id, exam_id, event_type, event_time, details) VALUES (?, ?, ?, NOW(), ?)");
    if (!$stmt) throw new Exception('Failed to prepare violation insert: ' . $conn->error);
    $stmt->bind_param('iiss', $userId, $examId, $eventType, $details);
    if (!$stmt->execute()) throw new Exception('Failed to insert violation: ' . $stmt->error);
    $stmt->close();

    // Count violations for this session (only those after session started)
    $stmt = $conn->prepare("SELECT COUNT(*) AS violation_count FROM exam_violations WHERE user_id = ? AND exam_id = ? AND event_time >= ?");
    if (!$stmt) throw new Exception('Failed to prepare violation count: ' . $conn->error);
    $stmt->bind_param('iis', $userId, $examId, $startedAt);
    if (!$stmt->execute()) throw new Exception('Failed to execute violation count: ' . $stmt->error);
    $violationCount = (int)$stmt->get_result()->fetch_assoc()['violation_count'];
    $stmt->close();

    // Decide action: warn on 1st and 2nd violations, end on 3rd or more
    $action = 'ok';
    $message = 'Violation logged';

    if ($violationCount >= 3) {
        // Terminate session
        $stmt = $conn->prepare("UPDATE exam_sessions SET ended_at = NOW(), ended_reason = 'terminated' WHERE session_id = ? AND user_id = ? AND ended_at IS NULL");
        if ($stmt) {
            $stmt->bind_param('ii', $sessionId, $userId);
            $stmt->execute();
            $stmt->close();
        }
        $action = 'end';
        $message = 'Exam terminated due to multiple violations';
    } elseif ($violationCount === 2) {
        $action = 'warn';
        $message = 'Final Warning: One more violation will terminate the exam';
    } elseif ($violationCount === 1) {
        $action = 'warn';
        $message = 'Warning: Next violation will escalate';
    }

    $conn->commit();

    // Return structured response
    sendResponse(true, [
        'violations' => $violationCount,
        'action' => $action,
        'message' => $message,
        'session_id' => $sessionId
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn && $conn->errno) {
        $conn->rollback();
    }
    error_log("Error in log_event.php: " . $e->getMessage());
    sendResponse(false, null, 'Internal server error');
}

// Helper function to validate event type
function isValidEventType($type) {
    $validTypes = [
        'blur',
        'visibilitychange',
        'cursor_out',
        'cursor_leave',
        'mouseleave',
        'fullscreen_exit',
        'devtools_shortcut',
        'start_session'
    ];
    return in_array($type, $validTypes, true);
}

?>