<?php
/**
 * Utility Functions
 * Common helper functions used across the application
 */

require_once __DIR__ . '/db.php';

/**
 * Sanitize input data
 */
function sanitize($data) {
    global $conn;
    return mysqli_real_escape_string($conn, htmlspecialchars(strip_tags(trim($data))));
}

/**
 * Get all questions
 */
function getAllQuestions() {
    global $conn;
    $sql = "SELECT * FROM questions ORDER BY RAND() LIMIT 10";
    $questions = [];
    try {
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $questions[] = $row;
            }
        }
    } catch (Exception $e) {
        // Table might not exist or another DB error occurred. Log and return empty set.
        error_log("getAllQuestions error: " . $e->getMessage());
    }
    return $questions;
}

/**
 * Get question by ID
 */
function getQuestionById($id) {
    global $conn;
    $id = (int)$id;
    $sql = "SELECT * FROM questions WHERE id = $id";
    try {
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }
    } catch (Exception $e) {
        error_log("getQuestionById error: " . $e->getMessage());
    }
    return null;
}

/**
 * Save exam result
 */
function saveResult($user_id, $score, $total_questions, $time_taken, $status = 'completed') {
    global $conn;
    
    $user_id = (int)$user_id;
    $score = (int)$score;
    $total_questions = (int)$total_questions;
    $time_taken = (int)$time_taken;
    $status = sanitize($status);
    
    $sql = "INSERT INTO results (user_id, score, total_questions, time_taken, status) 
            VALUES ($user_id, $score, $total_questions, $time_taken, '$status')";
    try {
        return $conn->query($sql);
    } catch (Exception $e) {
        error_log("saveResult error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user results
 */
function getUserResults($user_id) {
    global $conn;
    $user_id = (int)$user_id;
    $sql = "SELECT * FROM results WHERE user_id = $user_id ORDER BY created_at DESC";
    $results = [];
    try {
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $results[] = $row;
            }
        }
    } catch (Exception $e) {
        error_log("getUserResults error: " . $e->getMessage());
    }
    return $results;
}

/**
 * Get user statistics
 */
function getUserStats($user_id) {
    global $conn;
    $user_id = (int)$user_id;
    
    $sql = "SELECT 
                COUNT(*) as total_attempts,
                AVG(score) as average_score,
                MAX(score) as highest_score,
                SUM(CASE WHEN status = 'cheated' THEN 1 ELSE 0 END) as cheated_count
            FROM results 
            WHERE user_id = $user_id";
    
    try {
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }
    } catch (Exception $e) {
        error_log("getUserStats error: " . $e->getMessage());
    }
    return null;
}
?>

